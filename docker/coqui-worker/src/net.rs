//! Outbound network policy.
//!
//! The worker fetches pages on customer sites from inside the same network as
//! the database, Redis and the app. Every outbound request it makes for a crawl
//! or an inspection must therefore reach a public address only: never loopback,
//! a private range, link-local (cloud metadata), or another container.
//!
//! Inspections use [`guarded_client`] directly. The crawl engine builds its own
//! HTTP client, so crawls are pointed at [`EgressProxy`], a local forward proxy
//! that applies the same rule to every request and redirect hop the crawler
//! makes. DNS answers are checked and then pinned, so a hostname can't pass the
//! check and then resolve somewhere else for the connection.

use std::convert::Infallible;
use std::net::{IpAddr, Ipv4Addr, SocketAddr};
use std::sync::Arc;
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Full, combinators::BoxBody};
use hyper::body::Incoming;
use hyper::server::conn::http1;
use hyper::service::service_fn;
use hyper::{Method, Request, Response, StatusCode};
use hyper_util::rt::TokioIo;
use reqwest::dns::{Addrs, Name, Resolve, Resolving};
use tokio::net::{TcpListener, TcpStream};

/// Whether an address is on the public internet.
pub fn is_public_ip(ip: IpAddr) -> bool {
    match ip {
        IpAddr::V4(v4) => is_public_v4(v4),
        IpAddr::V6(v6) => {
            if let Some(v4) = v6.to_ipv4_mapped() {
                return is_public_v4(v4);
            }
            let segments = v6.segments();
            !(v6.is_unspecified()
                || v6.is_loopback()
                || v6.is_multicast()
                // fc00::/7 unique local
                || (segments[0] & 0xfe00) == 0xfc00
                // fe80::/10 link-local
                || (segments[0] & 0xffc0) == 0xfe80
                // 2001:db8::/32 documentation
                || (segments[0] == 0x2001 && segments[1] == 0x0db8)
                // 64:ff9b::/96 NAT64 can reach IPv4 private space
                || (segments[0] == 0x0064 && segments[1] == 0xff9b))
        }
    }
}

fn is_public_v4(ip: Ipv4Addr) -> bool {
    let [a, b, c, _] = ip.octets();
    !(ip.is_unspecified()
        || ip.is_loopback()
        || ip.is_private()
        || ip.is_link_local()
        || ip.is_broadcast()
        || ip.is_multicast()
        || ip.is_documentation()
        || a == 0
        // 100.64.0.0/10 carrier-grade NAT
        || (a == 100 && (64..=127).contains(&b))
        // 192.0.0.0/24 protocol assignments
        || (a == 192 && b == 0 && c == 0)
        // 198.18.0.0/15 benchmarking
        || (a == 198 && (b == 18 || b == 19))
        // 240.0.0.0/4 reserved
        || a >= 240)
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Blocked(pub String);

impl std::fmt::Display for Blocked {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for Blocked {}

/// Resolve a host and keep only public addresses. Errors when the host is a
/// non-public literal, doesn't resolve, or resolves only to non-public
/// addresses.
pub async fn resolve_public(host: &str, port: u16) -> Result<Vec<SocketAddr>, Blocked> {
    let bare = host.trim_start_matches('[').trim_end_matches(']');
    if let Ok(ip) = bare.parse::<IpAddr>() {
        return if is_public_ip(ip) {
            Ok(vec![SocketAddr::new(ip, port)])
        } else {
            Err(Blocked(format!("{host} is not a public address")))
        };
    }

    let addrs: Vec<SocketAddr> = tokio::net::lookup_host((bare, port))
        .await
        .map_err(|error| Blocked(format!("could not resolve {host}: {error}")))?
        .collect();
    let public: Vec<SocketAddr> = addrs
        .iter()
        .copied()
        .filter(|addr| is_public_ip(addr.ip()))
        .collect();
    if public.is_empty() {
        return Err(Blocked(format!(
            "{host} does not resolve to a public address"
        )));
    }
    Ok(public)
}

/// Check a URL's scheme and, for an IP literal host, its address. Hostnames
/// are checked at connection time by [`PublicOnlyResolver`].
pub fn check_url(raw: &str) -> Result<url::Url, Blocked> {
    let parsed = url::Url::parse(raw).map_err(|_| Blocked(format!("invalid URL: {raw}")))?;
    if !matches!(parsed.scheme(), "http" | "https") {
        return Err(Blocked(format!("unsupported scheme in {raw}")));
    }
    match parsed.host() {
        Some(url::Host::Ipv4(ip)) if !is_public_ip(IpAddr::V4(ip)) => {
            Err(Blocked(format!("{ip} is not a public address")))
        }
        Some(url::Host::Ipv6(ip)) if !is_public_ip(IpAddr::V6(ip)) => {
            Err(Blocked(format!("{ip} is not a public address")))
        }
        Some(_) => Ok(parsed),
        None => Err(Blocked(format!("URL has no host: {raw}"))),
    }
}

/// reqwest resolver that only ever hands public addresses to the connector.
#[derive(Debug, Default, Clone)]
pub struct PublicOnlyResolver;

impl Resolve for PublicOnlyResolver {
    fn resolve(&self, name: Name) -> Resolving {
        Box::pin(async move {
            let addrs = resolve_public(name.as_str(), 0).await?;
            let iter: Addrs = Box::new(addrs.into_iter());
            Ok(iter)
        })
    }
}

/// HTTP client for outbound fetches: public addresses only, no automatic
/// redirects (callers follow and re-check each hop), no system proxy.
pub fn guarded_client(timeout: Duration, user_agent: &str) -> reqwest::Client {
    reqwest::Client::builder()
        .dns_resolver(Arc::new(PublicOnlyResolver))
        .redirect(reqwest::redirect::Policy::none())
        .no_proxy()
        .timeout(timeout)
        .connect_timeout(Duration::from_secs(10))
        .user_agent(user_agent)
        .build()
        .expect("build guarded HTTP client")
}

/// Local forward proxy the crawl engine is pointed at. HTTPS goes through
/// CONNECT tunnels to a checked address; plain HTTP is re-sent through
/// [`guarded_client`].
pub struct EgressProxy {
    pub url: String,
}

const HOP_BY_HOP: &[&str] = &[
    "connection",
    "proxy-connection",
    "keep-alive",
    "proxy-authorization",
    "proxy-authenticate",
    "te",
    "trailer",
    "transfer-encoding",
    "upgrade",
    "host",
    "content-length",
];

type ProxyBody = BoxBody<Bytes, Infallible>;

/// Largest plain-HTTP response the proxy will relay. The body is buffered, so
/// this bounds the memory one hostile or endless response can take.
const MAX_PROXY_BODY_BYTES: usize = 10 * 1024 * 1024;

fn text_response(status: StatusCode, message: String) -> Response<ProxyBody> {
    Response::builder()
        .status(status)
        .header("content-type", "text/plain")
        .body(Full::new(Bytes::from(message)).boxed())
        .expect("static response")
}

impl EgressProxy {
    /// Bind on an ephemeral loopback port and serve until the process exits.
    pub async fn start(request_timeout: Duration) -> std::io::Result<Self> {
        let listener = TcpListener::bind(("127.0.0.1", 0)).await?;
        let addr = listener.local_addr()?;
        // Decompression stays off so the crawler sees the origin's own
        // Content-Encoding and body.
        let client = reqwest::Client::builder()
            .dns_resolver(Arc::new(PublicOnlyResolver))
            .redirect(reqwest::redirect::Policy::none())
            .no_proxy()
            .no_gzip()
            .no_brotli()
            .no_deflate()
            .no_zstd()
            .timeout(request_timeout)
            .connect_timeout(Duration::from_secs(10))
            .build()
            .expect("build proxy client");

        tokio::spawn(async move {
            loop {
                let Ok((stream, _)) = listener.accept().await else {
                    continue;
                };
                let client = client.clone();
                tokio::spawn(async move {
                    let service = service_fn(move |req| handle(req, client.clone()));
                    let _ = http1::Builder::new()
                        .preserve_header_case(true)
                        .serve_connection(TokioIo::new(stream), service)
                        .with_upgrades()
                        .await;
                });
            }
        });

        Ok(Self {
            url: format!("http://{addr}"),
        })
    }
}

async fn handle(
    req: Request<Incoming>,
    client: reqwest::Client,
) -> Result<Response<ProxyBody>, Infallible> {
    if req.method() == Method::CONNECT {
        return Ok(tunnel(req).await);
    }
    Ok(forward(req, client).await)
}

async fn tunnel(req: Request<Incoming>) -> Response<ProxyBody> {
    let Some(authority) = req.uri().authority().cloned() else {
        return text_response(StatusCode::BAD_REQUEST, "CONNECT needs host:port".into());
    };
    let port = authority.port_u16().unwrap_or(443);
    let addrs = match resolve_public(authority.host(), port).await {
        Ok(addrs) => addrs,
        Err(blocked) => {
            tracing::warn!(target = %authority, "blocked tunnel: {blocked}");
            return text_response(StatusCode::FORBIDDEN, blocked.to_string());
        }
    };
    let upstream = match connect_any(&addrs).await {
        Ok(stream) => stream,
        Err(error) => {
            return text_response(StatusCode::BAD_GATEWAY, format!("connect failed: {error}"));
        }
    };

    tokio::spawn(async move {
        match hyper::upgrade::on(req).await {
            Ok(upgraded) => {
                let mut client_side = TokioIo::new(upgraded);
                let mut upstream = upstream;
                let _ = tokio::io::copy_bidirectional(&mut client_side, &mut upstream).await;
            }
            Err(error) => tracing::debug!("tunnel upgrade failed: {error}"),
        }
    });

    Response::new(Full::new(Bytes::new()).boxed())
}

/// The [`Blocked`] decision behind a failed request, if that's why it failed.
pub fn blocked_cause<'a>(error: &'a (dyn std::error::Error + 'static)) -> Option<&'a Blocked> {
    let mut current: Option<&(dyn std::error::Error + 'static)> = Some(error);
    while let Some(err) = current {
        if let Some(blocked) = err.downcast_ref::<Blocked>() {
            return Some(blocked);
        }
        current = err.source();
    }
    None
}

async fn connect_any(addrs: &[SocketAddr]) -> std::io::Result<TcpStream> {
    let mut last = std::io::Error::other("no addresses");
    for addr in addrs {
        match tokio::time::timeout(Duration::from_secs(10), TcpStream::connect(addr)).await {
            Ok(Ok(stream)) => return Ok(stream),
            Ok(Err(error)) => last = error,
            Err(_) => last = std::io::Error::new(std::io::ErrorKind::TimedOut, "connect timed out"),
        }
    }
    Err(last)
}

async fn forward(req: Request<Incoming>, client: reqwest::Client) -> Response<ProxyBody> {
    let target = req.uri().to_string();
    if let Err(blocked) = check_url(&target) {
        tracing::warn!(target = %target, "blocked request: {blocked}");
        return text_response(StatusCode::FORBIDDEN, blocked.to_string());
    }

    let (parts, body) = req.into_parts();
    let Ok(method) = reqwest::Method::from_bytes(parts.method.as_str().as_bytes()) else {
        return text_response(StatusCode::METHOD_NOT_ALLOWED, "unsupported method".into());
    };
    let body = match body.collect().await {
        Ok(collected) => collected.to_bytes(),
        Err(_) => return text_response(StatusCode::BAD_REQUEST, "unreadable body".into()),
    };

    let mut outbound = client.request(method, &target);
    for (name, value) in &parts.headers {
        if !HOP_BY_HOP.contains(&name.as_str()) {
            outbound = outbound.header(name.as_str(), value.as_bytes());
        }
    }
    if !body.is_empty() {
        outbound = outbound.body(body);
    }

    let response = match outbound.send().await {
        Ok(response) => response,
        Err(error) => {
            if let Some(blocked) = blocked_cause(&error) {
                tracing::warn!(target = %target, "blocked request: {blocked}");
                return text_response(StatusCode::FORBIDDEN, blocked.to_string());
            }
            let status = if error.is_timeout() {
                StatusCode::GATEWAY_TIMEOUT
            } else {
                StatusCode::BAD_GATEWAY
            };
            return text_response(status, format!("upstream error: {error}"));
        }
    };

    let mut builder = Response::builder().status(response.status().as_u16());
    for (name, value) in response.headers() {
        if !HOP_BY_HOP.contains(&name.as_str()) {
            builder = builder.header(name.as_str(), value.as_bytes());
        }
    }
    let bytes = match read_capped(response, MAX_PROXY_BODY_BYTES).await {
        Ok(bytes) => bytes,
        Err(BodyError::TooLarge) => {
            tracing::warn!(target = %target, "upstream response over {MAX_PROXY_BODY_BYTES} bytes");
            return text_response(
                StatusCode::BAD_GATEWAY,
                "upstream response too large".into(),
            );
        }
        // A body that fails part-way must not reach the crawler as a valid,
        // truncated page.
        Err(BodyError::Upstream(error)) => {
            let status = if error.is_timeout() {
                StatusCode::GATEWAY_TIMEOUT
            } else {
                StatusCode::BAD_GATEWAY
            };
            return text_response(status, format!("upstream body error: {error}"));
        }
    };
    builder
        .body(Full::new(bytes).boxed())
        .unwrap_or_else(|_| text_response(StatusCode::BAD_GATEWAY, "bad upstream headers".into()))
}

#[derive(Debug)]
enum BodyError {
    TooLarge,
    Upstream(reqwest::Error),
}

/// Read a response body, giving up as soon as it passes `limit` bytes.
async fn read_capped(mut response: reqwest::Response, limit: usize) -> Result<Bytes, BodyError> {
    if response
        .content_length()
        .is_some_and(|length| length > limit as u64)
    {
        return Err(BodyError::TooLarge);
    }
    let mut body = bytes::BytesMut::new();
    while let Some(chunk) = response.chunk().await.map_err(BodyError::Upstream)? {
        if body.len() + chunk.len() > limit {
            return Err(BodyError::TooLarge);
        }
        body.extend_from_slice(&chunk);
    }
    Ok(body.freeze())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn non_public_addresses_are_rejected() {
        for ip in [
            "127.0.0.1",
            "10.1.2.3",
            "172.20.0.5",
            "192.168.1.1",
            "169.254.169.254",
            "100.64.0.1",
            "0.0.0.0",
            "::1",
            "fd00::1",
            "fe80::1",
            "::ffff:10.0.0.1",
            "::ffff:127.0.0.1",
        ] {
            assert!(!is_public_ip(ip.parse().unwrap()), "{ip} should be blocked");
        }
        for ip in ["8.8.8.8", "1.1.1.1", "2606:4700:4700::1111"] {
            assert!(is_public_ip(ip.parse().unwrap()), "{ip} should be allowed");
        }
    }

    #[test]
    fn literal_ip_urls_are_checked_before_any_lookup() {
        assert!(check_url("http://169.254.169.254/latest/meta-data").is_err());
        assert!(check_url("http://[::1]:8080/").is_err());
        assert!(check_url("ftp://example.com/").is_err());
        assert!(check_url("https://example.com/").is_ok());
    }

    /// Serve one raw HTTP response on a local port and return its URL.
    async fn serve_once(raw: &'static [u8]) -> String {
        use tokio::io::{AsyncReadExt as _, AsyncWriteExt as _};
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move {
            let (mut socket, _) = listener.accept().await.unwrap();
            let mut buf = [0_u8; 1024];
            let _ = socket.read(&mut buf).await;
            let _ = socket.write_all(raw).await;
            let _ = socket.shutdown().await;
        });
        format!("http://{addr}/")
    }

    #[tokio::test]
    async fn oversized_bodies_are_refused_while_reading() {
        // No Content-Length: the size is only known by reading.
        let url = serve_once(
            b"HTTP/1.1 200 OK
Connection: close

0123456789abcdefghij",
        )
        .await;
        let response = reqwest::get(&url).await.unwrap();
        assert!(matches!(
            read_capped(response, 8).await,
            Err(BodyError::TooLarge)
        ));

        let url = serve_once(
            b"HTTP/1.1 200 OK
Content-Length: 5

hello",
        )
        .await;
        let response = reqwest::get(&url).await.unwrap();
        assert_eq!(
            read_capped(response, 8).await.unwrap(),
            Bytes::from_static(b"hello")
        );
    }

    #[tokio::test]
    async fn a_body_cut_short_is_an_error_not_an_empty_page() {
        let url = serve_once(
            b"HTTP/1.1 200 OK
Content-Length: 100

only part",
        )
        .await;
        let response = reqwest::get(&url).await.unwrap();
        assert!(matches!(
            read_capped(response, 1024).await,
            Err(BodyError::Upstream(_))
        ));
    }

    #[tokio::test]
    async fn hostnames_resolving_to_private_space_are_rejected() {
        assert!(resolve_public("localhost", 80).await.is_err());
    }
}
