//! `POST /jobs/inspect`: fetch a list of pages and report, for each, its
//! status, whether it links to the target (and with what rel), and whether
//! it's noindex. Used for backlink verification; Laravel falls back to
//! fetching any URL missing from the response itself.
//!
//! The target is the page's entry in `targetUrls` when there is one (the
//! monitored link must point at that host and path), otherwise any link to
//! `targetDomain` or its subdomains.

use std::collections::HashMap;
use std::time::Duration;

use futures::StreamExt as _;
use scraper::{Html, Selector};
use serde::{Deserialize, Serialize};

use crate::net;

pub const MAX_URLS: usize = 200;
const MAX_REDIRECTS: usize = 5;
const MAX_BODY_BYTES: usize = 2 * 1024 * 1024;
const CONCURRENCY: usize = 8;

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct InspectRequest {
    pub urls: Vec<String>,
    #[serde(default)]
    pub target_domain: Option<String>,
    /// Source URL => the exact URL the monitored link should point at.
    #[serde(default)]
    pub target_urls: HashMap<String, String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
enum Target {
    /// Any link to this host or its subdomains.
    Domain(String),
    /// A link to this host (ignoring `www.`) and path (ignoring a trailing slash).
    Page { host: String, path: String },
}

impl Target {
    fn page(target_url: &str) -> Option<Self> {
        let parsed = url::Url::parse(target_url.trim()).ok()?;
        let host = normalise_host(parsed.host_str()?);
        Some(Self::Page {
            host,
            path: parsed.path().trim_end_matches('/').to_string(),
        })
    }

    fn matches(&self, link: &url::Url) -> bool {
        let Some(host) = link.host_str().map(normalise_host) else {
            return false;
        };
        match self {
            Self::Domain(domain) => host == *domain || host.ends_with(&format!(".{domain}")),
            Self::Page { host: want, path } => {
                host == *want && link.path().trim_end_matches('/') == path
            }
        }
    }
}

#[derive(Debug, Serialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct Observation {
    pub url: String,
    pub status: Option<u16>,
    pub has_target_link: bool,
    pub observed_rel: Option<String>,
    pub noindex: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<String>,
}

impl Observation {
    fn failed(url: &str, error: String) -> Self {
        Self {
            url: url.to_string(),
            status: None,
            has_target_link: false,
            observed_rel: None,
            noindex: false,
            error: Some(error),
        }
    }
}

/// Inspect every URL, a few at a time, stopping at `deadline` so the caller's
/// HTTP timeout isn't hit. URLs not reached by then are left out.
pub async fn inspect_all(
    client: &reqwest::Client,
    request: &InspectRequest,
    deadline: Duration,
) -> Vec<Observation> {
    let domain = request
        .target_domain
        .as_deref()
        .map(normalise_host)
        .filter(|host| !host.is_empty())
        .map(Target::Domain);

    let urls: Vec<String> = request.urls.iter().take(MAX_URLS).cloned().collect();
    let mut stream = futures::stream::iter(urls)
        .map(|url| {
            let target = request
                .target_urls
                .get(&url)
                .and_then(|target_url| Target::page(target_url))
                .or_else(|| domain.clone());
            async move { inspect_one(client, &url, target.as_ref()).await }
        })
        .buffer_unordered(CONCURRENCY);

    let deadline_at = tokio::time::Instant::now() + deadline;
    let mut results = Vec::new();
    while let Ok(Some(observation)) = tokio::time::timeout_at(deadline_at, stream.next()).await {
        results.push(observation);
    }
    results
}

async fn inspect_one(client: &reqwest::Client, url: &str, target: Option<&Target>) -> Observation {
    let mut current = url.to_string();
    for _ in 0..=MAX_REDIRECTS {
        if let Err(blocked) = net::check_url(&current) {
            return Observation::failed(url, blocked.to_string());
        }
        let mut response = match client.get(&current).send().await {
            Ok(response) => response,
            Err(error) => {
                let reason = net::blocked_cause(&error)
                    .map_or_else(|| error.to_string(), ToString::to_string);
                return Observation::failed(url, reason);
            }
        };
        let status = response.status();

        if status.is_redirection() {
            let next = response
                .headers()
                .get(reqwest::header::LOCATION)
                .and_then(|value| value.to_str().ok())
                .and_then(|location| url::Url::parse(&current).ok()?.join(location).ok());
            match next {
                Some(next) => {
                    current = next.to_string();
                    continue;
                }
                None => return observation(url, status.as_u16(), false, None, false),
            }
        }

        if status.as_u16() >= 400 {
            return observation(url, status.as_u16(), false, None, false);
        }

        let header_noindex = response
            .headers()
            .get_all("x-robots-tag")
            .iter()
            .filter_map(|value| value.to_str().ok())
            .any(robots_says_noindex);

        let mut body = Vec::new();
        loop {
            match response.chunk().await {
                Ok(Some(chunk)) => {
                    let room = MAX_BODY_BYTES.saturating_sub(body.len());
                    body.extend_from_slice(&chunk[..chunk.len().min(room)]);
                    if body.len() >= MAX_BODY_BYTES {
                        break;
                    }
                }
                Ok(None) => break,
                Err(error) => return Observation::failed(url, error.to_string()),
            }
        }
        let html = String::from_utf8_lossy(&body);
        let (has_link, rel, meta_noindex) = analyse_html(&html, &current, target);
        return observation(
            url,
            status.as_u16(),
            has_link,
            rel,
            header_noindex || meta_noindex,
        );
    }
    Observation::failed(url, "too many redirects".into())
}

fn observation(
    url: &str,
    status: u16,
    has_target_link: bool,
    observed_rel: Option<String>,
    noindex: bool,
) -> Observation {
    Observation {
        url: url.to_string(),
        status: Some(status),
        has_target_link,
        observed_rel,
        noindex,
        error: None,
    }
}

fn normalise_host(host: &str) -> String {
    let host = host.trim().to_ascii_lowercase();
    let host = host
        .strip_prefix("https://")
        .or_else(|| host.strip_prefix("http://"))
        .unwrap_or(&host);
    let host = host.split(['/', ':']).next().unwrap_or_default();
    host.strip_prefix("www.").unwrap_or(host).to_string()
}

fn robots_says_noindex(value: &str) -> bool {
    let value = value.to_ascii_lowercase();
    value.contains("noindex") || value.split([',', ' ']).any(|token| token.trim() == "none")
}

/// Returns (links to target, rel of the first such link, meta robots noindex).
/// Rel is reported the way Laravel stores it: nofollow, sponsored, ugc or
/// dofollow.
fn analyse_html(
    html: &str,
    page_url: &str,
    target: Option<&Target>,
) -> (bool, Option<String>, bool) {
    let document = Html::parse_document(html);
    let meta = Selector::parse("meta[name]").expect("selector");
    let noindex = document.select(&meta).any(|element| {
        element
            .value()
            .attr("name")
            .is_some_and(|name| name.eq_ignore_ascii_case("robots"))
            && element
                .value()
                .attr("content")
                .is_some_and(robots_says_noindex)
    });

    let Some(target) = target else {
        return (false, None, noindex);
    };
    let base = url::Url::parse(page_url).ok();
    let anchors = Selector::parse("a[href]").expect("selector");
    for anchor in document.select(&anchors) {
        let Some(href) = anchor.value().attr("href") else {
            continue;
        };
        let resolved = match &base {
            Some(base) => base.join(href.trim()).ok(),
            None => url::Url::parse(href.trim()).ok(),
        };
        if resolved.as_ref().is_some_and(|link| target.matches(link)) {
            let rel = anchor
                .value()
                .attr("rel")
                .unwrap_or("")
                .to_ascii_lowercase();
            let observed = if rel.contains("nofollow") {
                "nofollow"
            } else if rel.contains("sponsored") {
                "sponsored"
            } else if rel.contains("ugc") {
                "ugc"
            } else {
                "dofollow"
            };
            return (true, Some(observed.to_string()), noindex);
        }
    }
    (false, None, noindex)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn finds_the_target_link_and_its_rel() {
        let html = r#"<html><head><meta name="ROBOTS" content="index,follow"></head>
            <body><a href="https://other.com/">x</a>
            <a rel="nofollow ugc" href="https://www.example.com/pricing">y</a></body></html>"#;
        let (has, rel, noindex) =
            analyse_html(html, "https://blog.test/post", Some(&domain("example.com")));
        assert!(has);
        assert_eq!(rel.as_deref(), Some("nofollow"));
        assert!(!noindex);
    }

    #[test]
    fn resolves_relative_links_and_subdomains() {
        let html = r#"<a href="//app.example.com/signup">a</a>"#;
        let (has, rel, _) = analyse_html(html, "https://blog.test/", Some(&domain("example.com")));
        assert!(has);
        assert_eq!(rel.as_deref(), Some("dofollow"));
    }

    #[test]
    fn lookalike_domains_do_not_match() {
        let html = r#"<a href="https://notexample.com/">a</a>"#;
        let (has, _, _) = analyse_html(html, "https://blog.test/", Some(&domain("example.com")));
        assert!(!has);
    }

    fn domain(host: &str) -> Target {
        Target::Domain(host.to_string())
    }

    #[test]
    fn a_page_target_needs_the_monitored_host_and_path() {
        let target = Target::page("https://example.com/pricing/").unwrap();

        // Only a homepage link left: the monitored /pricing link is gone.
        let html = r#"<a rel="nofollow" href="https://example.com/">home</a>"#;
        let (has, rel, _) = analyse_html(html, "https://blog.test/", Some(&target));
        assert!(!has);
        assert_eq!(rel, None);

        // The monitored link's own rel is reported, not an earlier link's.
        let html = r#"<a rel="nofollow" href="https://example.com/">home</a>
            <a href="https://www.example.com/pricing?ref=blog">pricing</a>"#;
        let (has, rel, _) = analyse_html(html, "https://blog.test/", Some(&target));
        assert!(has);
        assert_eq!(rel.as_deref(), Some("dofollow"));

        // A subdomain isn't the monitored page.
        let html = r#"<a href="https://app.example.com/pricing">app</a>"#;
        assert!(!analyse_html(html, "https://blog.test/", Some(&target)).0);
    }

    #[test]
    fn meta_robots_noindex_is_reported() {
        let html = r#"<meta name="robots" content="noindex, nofollow">"#;
        let (_, _, noindex) = analyse_html(html, "https://blog.test/", None);
        assert!(noindex);
        assert!(robots_says_noindex("none"));
        assert!(!robots_says_noindex("index, follow"));
    }

    #[test]
    fn target_domain_is_normalised() {
        assert_eq!(
            normalise_host("https://WWW.Example.com/path"),
            "example.com"
        );
        assert_eq!(normalise_host("example.com:443"), "example.com");
    }
}
