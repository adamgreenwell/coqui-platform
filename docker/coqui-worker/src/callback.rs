//! Posts crawl progress and results to Laravel's
//! `POST /api/internal/crawls/callback`, authenticated with the shared
//! `COQUI_WORKER_TOKEN`.

use std::time::Duration;

use serde_json::Value;

#[derive(Debug)]
pub enum CallbackError {
    /// Laravel answered with a 4xx other than 429: the run is closed (409),
    /// unknown (404), or the token is wrong (401). Retrying won't help.
    Rejected(u16),
    /// Network error, timeout, 429 or 5xx after every retry.
    Unavailable(String),
}

impl std::fmt::Display for CallbackError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Rejected(status) => write!(f, "Laravel rejected the callback with HTTP {status}"),
            Self::Unavailable(message) => write!(f, "callback endpoint unavailable: {message}"),
        }
    }
}

#[derive(Debug, Clone)]
pub struct CallbackClient {
    client: reqwest::Client,
    url: String,
    token: String,
}

const RETRIES: u32 = 5;

impl CallbackClient {
    pub fn new(url: String, token: String) -> Self {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(120))
            .connect_timeout(Duration::from_secs(10))
            .no_proxy()
            .build()
            .expect("build callback client");
        Self { client, url, token }
    }

    /// Send with retries and backoff for transient failures.
    ///
    /// A request that timed out may still have been processed. URL and issue
    /// rows are upserted, so resending them is harmless; a resent link chunk
    /// can duplicate links, which is the lesser problem next to losing them.
    pub async fn send(&self, body: &Value) -> Result<(), CallbackError> {
        let mut delay = Duration::from_secs(1);
        let mut last = String::new();
        for attempt in 0..=RETRIES {
            match self.send_once(body).await {
                Ok(()) => return Ok(()),
                Err(CallbackError::Rejected(status)) => {
                    return Err(CallbackError::Rejected(status));
                }
                Err(CallbackError::Unavailable(message)) => last = message,
            }
            if attempt < RETRIES {
                tokio::time::sleep(delay).await;
                delay = (delay * 2).min(Duration::from_secs(30));
            }
        }
        Err(CallbackError::Unavailable(last))
    }

    pub async fn send_once(&self, body: &Value) -> Result<(), CallbackError> {
        let response = self
            .client
            .post(&self.url)
            .bearer_auth(&self.token)
            .header("accept", "application/json")
            .json(body)
            .send()
            .await
            .map_err(|error| CallbackError::Unavailable(error.to_string()))?;

        let status = response.status();
        if status.is_success() {
            return Ok(());
        }
        let code = status.as_u16();
        let detail = response.text().await.unwrap_or_default();
        if status.is_client_error() && code != 429 {
            tracing::warn!(status = code, body = %detail.chars().take(500).collect::<String>(), "callback rejected");
            return Err(CallbackError::Rejected(code));
        }
        Err(CallbackError::Unavailable(format!("HTTP {code}")))
    }
}
