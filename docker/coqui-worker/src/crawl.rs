//! `POST /jobs/crawl`: run a bounded site crawl in the background and stream
//! the results to Laravel's callback endpoint.
//!
//! Each crawl gets its own SQLite file in a temporary directory, so memory use
//! stays flat with crawl size; the file is deleted when the job ends. Results
//! go to Laravel in chunks under its per-request limits, then a final
//! `completed` (or `failed`) status closes the run.

use std::sync::Arc;
use std::sync::atomic::AtomicBool;
use std::time::Duration;

use coqui_core::{
    CrawlConfig, CrawlProgress, CrawlStatus, IssueSeverity, LinkRecord, UrlRecord, now_ms,
};
use coqui_store::Store;
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use tokio::sync::watch;
use tokio_util::sync::CancellationToken;

use crate::AppState;
use crate::callback::{CallbackClient, CallbackError};

/// Laravel's limits are 1,000 URLs and 2,500 links or issues per request; URL
/// rows are the heaviest, so they go in smaller batches.
const URL_CHUNK: usize = 500;
const LINK_CHUNK: usize = 2_500;
const ISSUE_CHUNK: usize = 2_500;

/// How often progress is posted while a crawl runs, and the longest gap
/// between posts (Laravel treats a long silence as a stalled crawl).
const PROGRESS_INTERVAL: Duration = Duration::from_secs(5);
const HEARTBEAT_INTERVAL: Duration = Duration::from_secs(60);

#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct CrawlRequest {
    pub crawl_id: String,
    #[serde(default)]
    pub project_id: Option<Value>,
    #[serde(default = "default_seed_kind")]
    pub seed_kind: String,
    pub seed: String,
    #[serde(default)]
    pub config: RequestConfig,
}

fn default_seed_kind() -> String {
    "url".into()
}

#[derive(Debug, Clone, Default, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RequestConfig {
    pub max_depth: Option<u32>,
    pub max_urls: Option<u32>,
    pub concurrency: Option<u16>,
    pub request_timeout_ms: Option<u64>,
    pub same_host_only: Option<bool>,
    pub respect_robots: Option<bool>,
}

/// Worker-wide limits from the environment.
#[derive(Debug, Clone)]
pub struct Limits {
    pub max_urls: u32,
    pub max_depth: u32,
    pub concurrency: u16,
    pub user_agent: Option<String>,
}

impl CrawlRequest {
    /// Validate the request and build the engine config, clamped to the
    /// worker's own limits whatever the caller asked for.
    pub fn engine_config(&self, limits: &Limits, proxy_url: &str) -> Result<CrawlConfig, String> {
        if !matches!(self.seed_kind.as_str(), "url" | "sitemap") {
            return Err(format!("unsupported seedKind '{}'", self.seed_kind));
        }
        if self.crawl_id.trim().is_empty() || self.crawl_id.len() > 64 {
            return Err("crawlId is required".into());
        }
        crate::net::check_url(&self.seed).map_err(|blocked| blocked.to_string())?;

        let defaults = CrawlConfig::default();
        let mut config = CrawlConfig {
            max_depth: self.config.max_depth.unwrap_or(3).min(limits.max_depth),
            max_urls: self
                .config
                .max_urls
                .unwrap_or(1_000)
                .clamp(1, limits.max_urls),
            concurrency: self
                .config
                .concurrency
                .unwrap_or(4)
                .clamp(1, limits.concurrency),
            request_timeout_ms: self
                .config
                .request_timeout_ms
                .unwrap_or(15_000)
                .clamp(1_000, 60_000),
            same_host_only: self.config.same_host_only.unwrap_or(true),
            respect_robots: self.config.respect_robots.unwrap_or(true),
            // Every request the engine makes goes through the egress guard.
            proxy_url: Some(proxy_url.to_string()),
            render: false,
            ..defaults
        };
        if let Some(agent) = &limits.user_agent {
            config.user_agent = agent.clone();
        }
        Ok(config)
    }
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct WireUrl<'a> {
    id: i64,
    url: &'a str,
    status: Option<u16>,
    content_type: Option<&'a str>,
    title: Option<&'a str>,
    meta_description: Option<&'a str>,
    h1: Option<&'a str>,
    h1_count: u32,
    canonical: Option<&'a str>,
    meta_robots: Option<&'a str>,
    redirect_target: Option<&'a str>,
    redirect_chain: &'a [String],
    word_count: Option<u32>,
    depth: u32,
    response_time_ms: Option<u64>,
    size_bytes: Option<u64>,
    error: Option<&'a str>,
    discovered_at: i64,
}

impl<'a> From<&'a UrlRecord> for WireUrl<'a> {
    fn from(row: &'a UrlRecord) -> Self {
        Self {
            id: row.id,
            url: &row.url,
            status: row.status,
            content_type: row.content_type.as_deref().map(|value| clip(value, 160)),
            title: row.title.as_deref(),
            meta_description: row.meta_description.as_deref(),
            h1: row.h1.as_deref(),
            h1_count: row.h1_count,
            canonical: row.canonical.as_deref(),
            meta_robots: row.meta_robots.as_deref().map(|value| clip(value, 255)),
            redirect_target: row.redirect_target.as_deref(),
            redirect_chain: &row.redirect_chain,
            word_count: row.word_count,
            depth: row.depth,
            response_time_ms: row.response_time_ms,
            size_bytes: row.size_bytes,
            error: row.error.as_deref(),
            discovered_at: row.discovered_at.max(0),
        }
    }
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct WireLink<'a> {
    from_url: &'a str,
    to_url: &'a str,
    anchor: Option<&'a str>,
    rel: Option<&'a str>,
    internal: bool,
}

impl<'a> From<&'a LinkRecord> for WireLink<'a> {
    fn from(row: &'a LinkRecord) -> Self {
        Self {
            from_url: &row.from_url,
            to_url: &row.to_url,
            anchor: row.anchor.as_deref(),
            rel: row.rel.as_deref().map(|value| clip(value, 120)),
            internal: row.internal,
        }
    }
}

#[derive(Debug, Serialize)]
struct WireIssue {
    url: String,
    rule: String,
    severity: &'static str,
    detail: Option<String>,
}

/// Cut a string to at most `max` bytes on a character boundary, so a long
/// header value can't get a whole chunk rejected by Laravel's validation.
fn clip(value: &str, max: usize) -> &str {
    if value.len() <= max {
        return value;
    }
    let mut end = max;
    while !value.is_char_boundary(end) {
        end -= 1;
    }
    &value[..end]
}

/// URLs longer than Laravel accepts are left out of the upload.
fn url_fits(url: &str) -> bool {
    url.len() <= 4_096
}

/// Run one accepted crawl: wait for a slot, crawl, audit, upload, close.
pub async fn run_job(
    state: Arc<AppState>,
    request: CrawlRequest,
    config: CrawlConfig,
    cancel: CancellationToken,
) {
    let crawl_id = request.crawl_id.clone();
    let callback = state.callback.clone();

    // Heartbeats start as soon as the job is accepted, so a crawl waiting
    // for a slot isn't mistaken for a stalled one.
    let (progress_tx, progress_rx) = watch::channel((0u64, 0u64));
    let heartbeat = tokio::spawn(heartbeat_loop(
        callback.clone(),
        crawl_id.clone(),
        config.max_urls as u64,
        progress_rx,
        cancel.clone(),
    ));

    let outcome = tokio::select! {
        permit = state.crawl_slots.clone().acquire_owned() => {
            let _permit = permit.expect("crawl semaphore closed");
            state.mark_started(&crawl_id);
            crawl_and_upload(&callback, &request, config, cancel.clone(), progress_tx).await
        }
        () = cancel.cancelled() => Err(JobError::Cancelled),
    };

    heartbeat.abort();

    let final_status = match &outcome {
        Ok(()) => "completed",
        Err(JobError::Callback(CallbackError::Rejected(status @ (404 | 409)))) => {
            // Laravel has closed or doesn't know this run; nothing more to send.
            tracing::warn!(crawl_id, status, "callback rejected; stopping");
            state.finish(&crawl_id);
            return;
        }
        Err(error) => {
            tracing::warn!(crawl_id, "crawl failed: {error}");
            "failed"
        }
    };

    if let Err(error) = callback
        .send(&json!({ "crawlId": crawl_id, "status": final_status }))
        .await
    {
        tracing::error!(
            crawl_id,
            "could not report final status {final_status}: {error}"
        );
    } else {
        tracing::info!(crawl_id, "crawl {final_status}");
    }
    state.finish(&crawl_id);
}

#[derive(Debug)]
enum JobError {
    Cancelled,
    Crawl(String),
    Callback(CallbackError),
}

impl std::fmt::Display for JobError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Cancelled => f.write_str("cancelled (worker shutting down)"),
            Self::Crawl(message) => f.write_str(message),
            Self::Callback(error) => write!(f, "callback failed: {error}"),
        }
    }
}

impl From<CallbackError> for JobError {
    fn from(error: CallbackError) -> Self {
        Self::Callback(error)
    }
}

async fn crawl_and_upload(
    callback: &CallbackClient,
    request: &CrawlRequest,
    config: CrawlConfig,
    cancel: CancellationToken,
    progress_tx: watch::Sender<(u64, u64)>,
) -> Result<(), JobError> {
    let crawl_id = request.crawl_id.clone();
    let workdir = tempfile::Builder::new()
        .prefix("coqui-crawl-")
        .tempdir()
        .map_err(|error| JobError::Crawl(format!("temp dir: {error}")))?;
    let store = Arc::new(
        Store::open(workdir.path().join("crawl.db"))
            .map_err(|error| JobError::Crawl(format!("open store: {error}")))?,
    );

    store
        .create_crawl(
            &crawl_id,
            &request.seed,
            &request.seed_kind,
            &config,
            now_ms(),
        )
        .and_then(|_| store.mark_running(&crawl_id, now_ms()))
        .map_err(|error| JobError::Crawl(format!("create crawl: {error}")))?;

    tracing::info!(crawl_id, seed = %request.seed, max_urls = config.max_urls, "crawl started");
    let on_progress = Arc::new(move |progress: CrawlProgress| {
        let _ = progress_tx.send((progress.crawled, progress.discovered));
    });
    let result = coqui_crawl::run_crawl(
        store.clone(),
        crawl_id.clone(),
        request.seed_kind.clone(),
        request.seed.clone(),
        config,
        cancel.clone(),
        Arc::new(AtomicBool::new(false)),
        None,
        on_progress,
    )
    .await;

    match result {
        Ok(result) => {
            tracing::info!(
                crawl_id,
                crawled = result.stats.crawled,
                discovered = result.stats.discovered,
                "crawl finished; auditing"
            );
        }
        Err(coqui_crawl::CrawlError::Cancelled) => return Err(JobError::Cancelled),
        Err(error) => {
            let _ = store.mark_finished(&crawl_id, CrawlStatus::Failed, now_ms());
            return Err(JobError::Crawl(error.to_string()));
        }
    }

    let audit_store = store.clone();
    let audit_id = crawl_id.clone();
    tokio::task::spawn_blocking(move || -> Result<(), String> {
        coqui_audit::analyze_crawl(&audit_store, &audit_id).map_err(|error| error.to_string())?;
        audit_store
            .mark_finished(&audit_id, CrawlStatus::Completed, now_ms())
            .map_err(|error| error.to_string())
    })
    .await
    .map_err(|error| JobError::Crawl(format!("audit task: {error}")))?
    .map_err(|error| JobError::Crawl(format!("audit: {error}")))?;

    upload(callback, &store, &crawl_id, workdir.path()).await
}

async fn upload(
    callback: &CallbackClient,
    store: &Arc<Store>,
    crawl_id: &str,
    workdir: &std::path::Path,
) -> Result<(), JobError> {
    // Results are read and sent one bounded batch at a time: a link-heavy
    // crawl can hold millions of link rows, far more than the worker's
    // memory limit if they were all loaded at once.
    let total = blocking({
        let store = store.clone();
        let id = crawl_id.to_string();
        move || store.total_urls(&id).map_err(|error| error.to_string())
    })
    .await?;

    let mut sent_urls = 0usize;
    let mut page = 1u32;
    loop {
        let rows = blocking({
            let store = store.clone();
            let id = crawl_id.to_string();
            move || {
                store
                    .list_urls(&id, page, URL_CHUNK as u32, None)
                    .map(|page| page.rows)
                    .map_err(|error| error.to_string())
            }
        })
        .await?;
        let last_page = rows.len() < URL_CHUNK;
        let chunk: Vec<WireUrl<'_>> = rows
            .iter()
            .filter(|row| url_fits(&row.url))
            .map(WireUrl::from)
            .collect();
        if !chunk.is_empty() {
            callback
                .send(&json!({
                    "crawlId": crawl_id,
                    "status": "running",
                    "totalUrls": total,
                    "urls": chunk,
                }))
                .await?;
            sent_urls += chunk.len();
        }
        if last_page {
            break;
        }
        page += 1;
    }

    let db_path = workdir.join("crawl.db");
    let mut sent_links = 0usize;
    let mut after = 0i64;
    loop {
        let (rows, next) = blocking({
            let db_path = db_path.clone();
            let id = crawl_id.to_string();
            move || read_links_after(&db_path, &id, after, LINK_CHUNK)
        })
        .await?;
        let last_page = rows.len() < LINK_CHUNK;
        let chunk: Vec<WireLink<'_>> = rows
            .iter()
            .filter(|row| url_fits(&row.from_url) && url_fits(&row.to_url))
            .map(WireLink::from)
            .collect();
        if !chunk.is_empty() {
            callback
                .send(&json!({ "crawlId": crawl_id, "status": "running", "links": chunk }))
                .await?;
            sent_links += chunk.len();
        }
        if last_page {
            break;
        }
        after = next;
    }

    let issues_csv = workdir.join("issues.csv");
    let mut reader = blocking({
        let store = store.clone();
        let id = crawl_id.to_string();
        move || {
            store
                .export_issues_csv(&id, &issues_csv)
                .map_err(|error| error.to_string())?;
            csv::Reader::from_path(&issues_csv).map_err(|error| error.to_string())
        }
    })
    .await?;
    let mut sent_issues = 0usize;
    loop {
        let (returned, chunk, done) = blocking(move || {
            let (chunk, done) = read_issue_batch(&mut reader, ISSUE_CHUNK)?;
            Ok((reader, chunk, done))
        })
        .await?;
        reader = returned;
        if !chunk.is_empty() {
            callback
                .send(&json!({ "crawlId": crawl_id, "status": "running", "issues": chunk }))
                .await?;
            sent_issues += chunk.len();
        }
        if done {
            break;
        }
    }

    tracing::info!(
        crawl_id,
        urls = sent_urls,
        links = sent_links,
        issues = sent_issues,
        "results uploaded"
    );
    Ok(())
}

/// Run blocking store or file work off the async runtime.
async fn blocking<T, F>(work: F) -> Result<T, JobError>
where
    T: Send + 'static,
    F: FnOnce() -> Result<T, String> + Send + 'static,
{
    tokio::task::spawn_blocking(work)
        .await
        .map_err(|error| JobError::Crawl(format!("read results: {error}")))?
        .map_err(JobError::Crawl)
}

/// Up to `limit` links with a row id above `after`, plus the last row id read.
///
/// The pinned store crate only offers `all_links`, which loads every row, so
/// this reads the crawl's SQLite file directly with the same query, a page
/// at a time.
fn read_links_after(
    db_path: &std::path::Path,
    crawl_id: &str,
    after: i64,
    limit: usize,
) -> Result<(Vec<LinkRecord>, i64), String> {
    let conn =
        rusqlite::Connection::open_with_flags(db_path, rusqlite::OpenFlags::SQLITE_OPEN_READ_ONLY)
            .map_err(|error| error.to_string())?;
    let mut stmt = conn
        .prepare(
            "SELECT l.id, f.url, t.url, l.anchor, l.rel, l.internal
             FROM links l
             JOIN url_map f ON f.id = l.from_id
             JOIN url_map t ON t.id = l.to_id
             WHERE l.crawl_id = ?1 AND l.id > ?2
             ORDER BY l.id
             LIMIT ?3",
        )
        .map_err(|error| error.to_string())?;
    let mut last = after;
    let mut links = Vec::with_capacity(limit.min(4_096));
    let rows = stmt
        .query_map(rusqlite::params![crawl_id, after, limit as i64], |row| {
            let anchor: String = row.get(3)?;
            Ok((
                row.get::<_, i64>(0)?,
                LinkRecord {
                    from_url: row.get(1)?,
                    to_url: row.get(2)?,
                    anchor: (!anchor.is_empty()).then_some(anchor),
                    rel: row.get(4)?,
                    internal: row.get::<_, i64>(5)? != 0,
                },
            ))
        })
        .map_err(|error| error.to_string())?;
    for row in rows {
        let (id, link) = row.map_err(|error| error.to_string())?;
        last = id;
        links.push(link);
    }
    Ok((links, last))
}

/// Issues come out of the store through its CSV export, which applies the
/// crawl's severity overrides and suppressions the same way the desktop app
/// does. Reads up to `limit` usable rows; `true` once the file is exhausted.
fn read_issue_batch<R: std::io::Read>(
    reader: &mut csv::Reader<R>,
    limit: usize,
) -> Result<(Vec<WireIssue>, bool), String> {
    let mut issues = Vec::with_capacity(limit.min(1_024));
    let mut record = csv::StringRecord::new();
    while issues.len() < limit {
        if !reader
            .read_record(&mut record)
            .map_err(|error| error.to_string())?
        {
            return Ok((issues, true));
        }
        let url = record.get(0).unwrap_or_default();
        let rule = record.get(1).unwrap_or_default();
        if url.is_empty() || rule.is_empty() || !url_fits(url) || rule.len() > 96 {
            continue;
        }
        let detail = record
            .get(3)
            .filter(|detail| !detail.is_empty())
            .map(str::to_string);
        issues.push(WireIssue {
            url: url.to_string(),
            rule: rule.to_string(),
            severity: IssueSeverity::parse(record.get(2).unwrap_or_default()).as_str(),
            detail,
        });
    }
    Ok((issues, false))
}

async fn heartbeat_loop(
    callback: CallbackClient,
    crawl_id: String,
    max_urls: u64,
    mut progress: watch::Receiver<(u64, u64)>,
    cancel: CancellationToken,
) {
    let mut last_sent = tokio::time::Instant::now();
    let mut ticker = tokio::time::interval(PROGRESS_INTERVAL);
    ticker.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
    loop {
        tokio::select! {
            _ = ticker.tick() => {}
            () = cancel.cancelled() => return,
        }
        let changed = progress.has_changed().unwrap_or(false);
        if !changed && last_sent.elapsed() < HEARTBEAT_INTERVAL {
            continue;
        }
        let (crawled, discovered) = *progress.borrow_and_update();
        let total = discovered.max(crawled).min(max_urls.max(crawled));
        let body = json!({
            "crawlId": crawl_id,
            "status": "running",
            "crawledUrls": crawled,
            "totalUrls": total,
        });
        match callback.send_once(&body).await {
            Ok(()) => last_sent = tokio::time::Instant::now(),
            Err(CallbackError::Rejected(status @ (404 | 409))) => {
                tracing::warn!(
                    crawl_id,
                    status,
                    "progress rejected; Laravel closed this run"
                );
                cancel.cancel();
                return;
            }
            Err(error) => tracing::debug!(crawl_id, "progress post failed: {error}"),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn limits() -> Limits {
        Limits {
            max_urls: 5_000,
            max_depth: 10,
            concurrency: 4,
            user_agent: None,
        }
    }

    fn request(json: Value) -> CrawlRequest {
        serde_json::from_value(json).unwrap()
    }

    #[test]
    fn caller_limits_are_clamped_to_the_worker_limits() {
        let req = request(json!({
            "crawlId": "3b2e7c90-0000-4000-8000-000000000000",
            "projectId": 12,
            "seedKind": "url",
            "seed": "https://example.com/",
            "config": {"maxDepth": 50, "maxUrls": 100000, "concurrency": 64, "requestTimeoutMs": 15000,
                       "sameHostOnly": true, "respectRobots": true}
        }));
        let config = req.engine_config(&limits(), "http://127.0.0.1:9").unwrap();
        assert_eq!(config.max_depth, 10);
        assert_eq!(config.max_urls, 5_000);
        assert_eq!(config.concurrency, 4);
        assert_eq!(config.proxy_url.as_deref(), Some("http://127.0.0.1:9"));
        assert!(!config.render);
    }

    #[test]
    fn private_seeds_and_unknown_seed_kinds_are_refused() {
        let base = json!({"crawlId": "a", "seed": "http://169.254.169.254/"});
        assert!(request(base).engine_config(&limits(), "x").is_err());
        let kind = json!({"crawlId": "a", "seed": "https://example.com/", "seedKind": "list"});
        assert!(request(kind).engine_config(&limits(), "x").is_err());
    }

    #[test]
    fn clip_respects_character_boundaries() {
        assert_eq!(clip("héllo", 2), "h");
        assert_eq!(clip("abc", 10), "abc");
    }

    #[test]
    fn links_are_read_in_bounded_pages() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("crawl.db");
        let store = Store::open(&path).unwrap();
        let config = request(json!({ "crawlId": "c1", "seed": "https://example.com/" }))
            .engine_config(&limits(), "http://proxy:8081")
            .unwrap();
        store
            .create_crawl("c1", "https://example.com/", "url", &config, 0)
            .unwrap();
        let home = store.ensure_url_id("c1", "https://example.com/").unwrap();
        for i in 0..7 {
            let to = store
                .ensure_url_id("c1", &format!("https://example.com/p/{i}"))
                .unwrap();
            let anchor = if i == 0 { None } else { Some("Page") };
            store
                .insert_link("c1", home, to, anchor, None, true)
                .unwrap();
        }

        let mut after = 0;
        let mut pages = Vec::new();
        loop {
            let (rows, next) = read_links_after(&path, "c1", after, 3).unwrap();
            pages.push(rows.len());
            if rows.len() < 3 {
                break;
            }
            after = next;
        }
        assert_eq!(pages, vec![3, 3, 1]);

        let (first, _) = read_links_after(&path, "c1", 0, 1).unwrap();
        assert_eq!(first[0].from_url, "https://example.com/");
        assert_eq!(first[0].to_url, "https://example.com/p/0");
        assert_eq!(first[0].anchor, None);
        assert!(first[0].internal);
    }

    #[test]
    fn issues_are_read_in_bounded_batches() {
        let data = "url,rule,severity,detail\n\
                    https://example.com/a,missing_title,error,\n\
                    ,missing_title,error,\n\
                    https://example.com/b,thin_content,warning,120 words\n\
                    https://example.com/c,missing_h1,warning,\n";
        let mut reader = csv::Reader::from_reader(data.as_bytes());

        let (first, done) = read_issue_batch(&mut reader, 2).unwrap();
        assert!(!done);
        assert_eq!(first.len(), 2);
        assert_eq!(first[1].detail.as_deref(), Some("120 words"));

        let (rest, done) = read_issue_batch(&mut reader, 2).unwrap();
        assert!(done);
        assert_eq!(rest.len(), 1);
        assert_eq!(rest[0].rule, "missing_h1");
    }
}
