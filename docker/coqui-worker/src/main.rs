//! coqui-worker: the headless crawler Laravel sends cloud crawls and backlink
//! inspections to. See README.md for the HTTP contract.

mod callback;
mod crawl;
mod inspect;
mod net;

use std::collections::HashSet;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use axum::extract::{Request, State};
use axum::http::{StatusCode, header};
use axum::middleware::{self, Next};
use axum::response::{IntoResponse, Response};
use axum::routing::{get, post};
use axum::{Json, Router};
use serde_json::json;
use tokio::sync::Semaphore;
use tokio_util::sync::CancellationToken;

use crate::callback::CallbackClient;
use crate::crawl::{CrawlRequest, Limits};
use crate::inspect::InspectRequest;

pub struct AppState {
    token: String,
    limits: Limits,
    proxy_url: String,
    pub callback: CallbackClient,
    inspect_client: reqwest::Client,
    pub crawl_slots: Arc<Semaphore>,
    max_queued: usize,
    jobs: Mutex<Jobs>,
    shutdown: CancellationToken,
}

#[derive(Default)]
struct Jobs {
    /// Every crawl accepted and not yet finished, queued or running.
    accepted: HashSet<String>,
    running: HashSet<String>,
}

impl Jobs {
    fn queued(&self) -> usize {
        self.accepted.len() - self.running.len()
    }
}

impl AppState {
    pub fn mark_started(&self, crawl_id: &str) {
        self.jobs
            .lock()
            .expect("jobs lock")
            .running
            .insert(crawl_id.to_string());
    }

    pub fn finish(&self, crawl_id: &str) {
        let mut jobs = self.jobs.lock().expect("jobs lock");
        jobs.accepted.remove(crawl_id);
        jobs.running.remove(crawl_id);
    }

    fn counts(&self) -> (usize, usize) {
        let jobs = self.jobs.lock().expect("jobs lock");
        (jobs.running.len(), jobs.queued())
    }
}

struct Settings {
    port: u16,
    token: String,
    callback_url: String,
    max_crawls: usize,
    max_queued: usize,
    limits: Limits,
}

fn env_or<T: std::str::FromStr>(name: &str, default: T) -> T {
    std::env::var(name)
        .ok()
        .and_then(|value| value.trim().parse().ok())
        .unwrap_or(default)
}

fn settings() -> Result<Settings, String> {
    let token = std::env::var("COQUI_WORKER_TOKEN")
        .unwrap_or_default()
        .trim()
        .to_string();
    if token.is_empty() {
        return Err(
            "COQUI_WORKER_TOKEN is not set. Set the same value for the worker and for Laravel \
             (COQUI_WORKER_TOKEN in .env); without it Laravel can't authenticate the worker's callbacks."
                .into(),
        );
    }
    let callback_url = std::env::var("COQUI_CALLBACK_URL")
        .unwrap_or_default()
        .trim()
        .to_string();
    if callback_url.is_empty() {
        return Err(
            "COQUI_CALLBACK_URL is not set. Point it at Laravel's /api/internal/crawls/callback \
             (for docker compose: http://nginx/api/internal/crawls/callback)."
                .into(),
        );
    }
    Ok(Settings {
        port: env_or("COQUI_WORKER_PORT", 8085),
        token,
        callback_url,
        // One crawl at a time keeps a 5,000-URL crawl inside the 256MB limit.
        max_crawls: env_or("COQUI_MAX_CRAWLS", 1usize).max(1),
        max_queued: env_or("COQUI_MAX_QUEUED", 20usize),
        limits: Limits {
            max_urls: env_or("COQUI_MAX_URLS", 10_000u32).max(1),
            max_depth: env_or("COQUI_MAX_DEPTH", 10u32),
            concurrency: env_or("COQUI_CONCURRENCY", 4u16).max(1),
            user_agent: std::env::var("COQUI_USER_AGENT")
                .ok()
                .filter(|value| !value.trim().is_empty()),
        },
    })
}

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt()
        .with_env_filter(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| tracing_subscriber::EnvFilter::new("info")),
        )
        .with_target(false)
        .init();

    let settings = match settings() {
        Ok(settings) => settings,
        Err(message) => {
            tracing::error!("{message}");
            std::process::exit(1);
        }
    };

    let proxy = net::EgressProxy::start(Duration::from_secs(60))
        .await
        .expect("start egress guard");

    let user_agent = settings
        .limits
        .user_agent
        .clone()
        .unwrap_or_else(|| coqui_core::CrawlConfig::default().user_agent);
    let state = Arc::new(AppState {
        // The callback carries the same token Laravel checks on the way in.
        callback: CallbackClient::new(settings.callback_url, settings.token.clone()),
        token: settings.token,
        limits: settings.limits,
        proxy_url: proxy.url,
        inspect_client: net::guarded_client(Duration::from_secs(8), &user_agent),
        crawl_slots: Arc::new(Semaphore::new(settings.max_crawls)),
        max_queued: settings.max_queued,
        jobs: Mutex::new(Jobs::default()),
        shutdown: CancellationToken::new(),
    });
    let app = router(state.clone());
    let listener = tokio::net::TcpListener::bind(("0.0.0.0", settings.port))
        .await
        .unwrap_or_else(|error| {
            tracing::error!("cannot listen on port {}: {error}", settings.port);
            std::process::exit(1);
        });
    tracing::info!(
        port = settings.port,
        max_crawls = settings.max_crawls,
        "coqui-worker listening"
    );

    let shutdown = state.shutdown.clone();
    axum::serve(listener, app)
        .with_graceful_shutdown(async move {
            wait_for_signal().await;
            tracing::info!("shutting down; reporting unfinished crawls as failed");
            shutdown.cancel();
        })
        .await
        .expect("server");

    // Tell Laravel every unfinished crawl failed, straight away: a crawl in
    // the middle of a fetch batch can take longer to wind down than Docker's
    // stop timeout allows.
    let unfinished: Vec<String> = state
        .jobs
        .lock()
        .expect("jobs lock")
        .accepted
        .iter()
        .cloned()
        .collect();
    let reports = unfinished.iter().map(|crawl_id| {
        let callback = state.callback.clone();
        async move {
            let body = json!({ "crawlId": crawl_id, "status": "failed" });
            match tokio::time::timeout(Duration::from_secs(5), callback.send_once(&body)).await {
                Ok(Ok(())) => tracing::info!(crawl_id, "reported as failed"),
                Ok(Err(error)) => tracing::warn!(crawl_id, "could not report failure: {error}"),
                Err(_) => tracing::warn!(crawl_id, "timed out reporting failure"),
            }
        }
    });
    futures::future::join_all(reports).await;
}

async fn wait_for_signal() {
    let ctrl_c = async {
        let _ = tokio::signal::ctrl_c().await;
    };
    #[cfg(unix)]
    let terminate = async {
        if let Ok(mut signal) =
            tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
        {
            signal.recv().await;
        }
    };
    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();
    tokio::select! {
        () = ctrl_c => {}
        () = terminate => {}
    }
}

fn router(state: Arc<AppState>) -> Router {
    let jobs = Router::new()
        .route("/jobs/crawl", post(start_crawl))
        .route("/jobs/inspect", post(inspect_urls))
        .route_layer(middleware::from_fn_with_state(state.clone(), require_token));
    Router::new()
        .route("/health", get(health))
        .merge(jobs)
        .with_state(state)
}

async fn require_token(
    State(state): State<Arc<AppState>>,
    request: Request,
    next: Next,
) -> Response {
    let provided = request
        .headers()
        .get(header::AUTHORIZATION)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.strip_prefix("Bearer "))
        .unwrap_or_default();
    if !constant_time_eq(provided.as_bytes(), state.token.as_bytes()) {
        return (
            StatusCode::UNAUTHORIZED,
            Json(json!({"message": "Invalid worker token."})),
        )
            .into_response();
    }
    next.run(request).await
}

fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    if a.len() != b.len() {
        return false;
    }
    a.iter().zip(b).fold(0u8, |acc, (x, y)| acc | (x ^ y)) == 0
}

async fn health(State(state): State<Arc<AppState>>) -> Json<serde_json::Value> {
    let (running, queued) = state.counts();
    Json(json!({
        "status": "healthy",
        "activeJobs": running,
        "queuedJobs": queued,
        "memoryUsageMb": memory_usage_mb(),
    }))
}

/// Resident memory from /proc (Linux only; null elsewhere).
fn memory_usage_mb() -> Option<f64> {
    let status = std::fs::read_to_string("/proc/self/status").ok()?;
    let line = status.lines().find(|line| line.starts_with("VmRSS:"))?;
    let kb: f64 = line.split_whitespace().nth(1)?.parse().ok()?;
    Some((kb / 1024.0 * 10.0).round() / 10.0)
}

async fn start_crawl(
    State(state): State<Arc<AppState>>,
    Json(request): Json<CrawlRequest>,
) -> Response {
    let config = match request.engine_config(&state.limits, &state.proxy_url) {
        Ok(config) => config,
        Err(message) => {
            return (
                StatusCode::UNPROCESSABLE_ENTITY,
                Json(json!({"message": message})),
            )
                .into_response();
        }
    };
    // Refuse a seed whose host doesn't resolve to a public address now, so
    // Laravel can fail the run at once (the egress guard would block every
    // fetch anyway).
    if let Some(seed) = url::Url::parse(&request.seed).ok()
        && let Some(host) = seed.host_str()
        && let Err(blocked) =
            net::resolve_public(host, seed.port_or_known_default().unwrap_or(443)).await
    {
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({"message": blocked.to_string()})),
        )
            .into_response();
    }
    if state.shutdown.is_cancelled() {
        return (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(json!({"message": "Worker is shutting down."})),
        )
            .into_response();
    }

    {
        let mut jobs = state.jobs.lock().expect("jobs lock");
        if jobs.accepted.contains(&request.crawl_id) {
            // A retried dispatch for a crawl already queued or running.
            return (
                StatusCode::ACCEPTED,
                Json(json!({"accepted": true, "crawlId": request.crawl_id, "duplicate": true})),
            )
                .into_response();
        }
        if jobs.queued() >= state.max_queued {
            return (
                StatusCode::SERVICE_UNAVAILABLE,
                Json(json!({"message": "The crawl queue is full. Try again later."})),
            )
                .into_response();
        }
        jobs.accepted.insert(request.crawl_id.clone());
    }

    tracing::info!(crawl_id = %request.crawl_id, project_id = ?request.project_id, "crawl accepted");
    let crawl_id = request.crawl_id.clone();
    let cancel = state.shutdown.child_token();
    tokio::spawn(crawl::run_job(state.clone(), request, config, cancel));

    (
        StatusCode::ACCEPTED,
        Json(json!({"accepted": true, "crawlId": crawl_id})),
    )
        .into_response()
}

async fn inspect_urls(
    State(state): State<Arc<AppState>>,
    Json(request): Json<InspectRequest>,
) -> Response {
    if request.urls.len() > inspect::MAX_URLS {
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({"message": format!("At most {} URLs per request.", inspect::MAX_URLS)})),
        )
            .into_response();
    }
    // Laravel's client gives up after 30 seconds by default; answer before
    // that with whatever finished. It inspects anything missing itself.
    let deadline = Duration::from_secs(env_or("COQUI_INSPECT_DEADLINE_SECONDS", 25u64));
    let results = inspect::inspect_all(&state.inspect_client, &request, deadline).await;
    Json(json!({ "results": results })).into_response()
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use tower::ServiceExt;

    fn test_state() -> Arc<AppState> {
        Arc::new(AppState {
            token: "secret".into(),
            limits: Limits {
                max_urls: 100,
                max_depth: 3,
                concurrency: 2,
                user_agent: None,
            },
            proxy_url: "http://127.0.0.1:9".into(),
            callback: CallbackClient::new("http://127.0.0.1:9/callback".into(), "secret".into()),
            inspect_client: net::guarded_client(Duration::from_secs(1), "test"),
            crawl_slots: Arc::new(Semaphore::new(1)),
            max_queued: 1,
            jobs: Mutex::new(Jobs::default()),
            shutdown: CancellationToken::new(),
        })
    }

    async fn call(
        app: Router,
        request: axum::http::Request<Body>,
    ) -> (StatusCode, serde_json::Value) {
        let response = app.oneshot(request).await.unwrap();
        let status = response.status();
        let bytes = axum::body::to_bytes(response.into_body(), 1 << 20)
            .await
            .unwrap();
        (
            status,
            serde_json::from_slice(&bytes).unwrap_or(serde_json::Value::Null),
        )
    }

    fn post(path: &str, token: Option<&str>, body: serde_json::Value) -> axum::http::Request<Body> {
        let mut builder =
            axum::http::Request::post(path).header("content-type", "application/json");
        if let Some(token) = token {
            builder = builder.header("authorization", format!("Bearer {token}"));
        }
        builder.body(Body::from(body.to_string())).unwrap()
    }

    #[tokio::test]
    async fn health_needs_no_token() {
        let (status, body) = call(
            router(test_state()),
            axum::http::Request::get("/health")
                .body(Body::empty())
                .unwrap(),
        )
        .await;
        assert_eq!(status, StatusCode::OK);
        assert_eq!(body["status"], "healthy");
    }

    #[tokio::test]
    async fn jobs_need_the_token() {
        let body = json!({"urls": ["https://example.com/"]});
        let (status, _) = call(
            router(test_state()),
            post("/jobs/inspect", None, body.clone()),
        )
        .await;
        assert_eq!(status, StatusCode::UNAUTHORIZED);
        let (status, _) = call(
            router(test_state()),
            post("/jobs/inspect", Some("wrong"), body),
        )
        .await;
        assert_eq!(status, StatusCode::UNAUTHORIZED);
    }

    #[tokio::test]
    async fn inspect_reports_private_targets_as_errors_without_fetching() {
        let body = json!({"urls": ["http://169.254.169.254/latest/meta-data"], "targetDomain": "example.com"});
        let (status, body) = call(
            router(test_state()),
            post("/jobs/inspect", Some("secret"), body),
        )
        .await;
        assert_eq!(status, StatusCode::OK);
        assert_eq!(body["results"][0]["status"], serde_json::Value::Null);
        assert_eq!(body["results"][0]["hasTargetLink"], false);
    }

    #[tokio::test]
    async fn crawl_requests_are_validated_and_deduplicated() {
        let state = test_state();
        let bad = json!({"crawlId": "c1", "seed": "http://10.0.0.5/"});
        let (status, _) = call(
            router(state.clone()),
            post("/jobs/crawl", Some("secret"), bad),
        )
        .await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
        let private_name = json!({"crawlId": "c1", "seed": "http://localhost:6379/"});
        let (status, _) = call(
            router(state.clone()),
            post("/jobs/crawl", Some("secret"), private_name),
        )
        .await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);

        // Hold the only slot so accepted crawls stay queued.
        let _slot = state.crawl_slots.clone().try_acquire_owned().unwrap();
        let ok = json!({"crawlId": "c2", "seed": "https://example.com/"});
        let (status, _) = call(
            router(state.clone()),
            post("/jobs/crawl", Some("secret"), ok.clone()),
        )
        .await;
        assert_eq!(status, StatusCode::ACCEPTED);
        let (status, body) = call(
            router(state.clone()),
            post("/jobs/crawl", Some("secret"), ok),
        )
        .await;
        assert_eq!(status, StatusCode::ACCEPTED);
        assert_eq!(body["duplicate"], true);

        let full = json!({"crawlId": "c3", "seed": "https://example.com/"});
        let (status, _) = call(
            router(state.clone()),
            post("/jobs/crawl", Some("secret"), full),
        )
        .await;
        assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE);
        state.shutdown.cancel();
    }
}
