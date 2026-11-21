# syntax=docker/dockerfile:1
#
# coqui-worker: a static musl binary built from this directory's crate, which
# pulls coqui-core, coqui-store, coqui-crawl and coqui-audit from
# github.com/adamgreenwell/coqui at the commit pinned in Cargo.toml.
#
#   docker build -t coqui-worker docker/coqui-worker
#
# To try a different coqui commit without editing Cargo.toml:
#
#   docker build --build-arg COQUI_CORE_REV=<commit sha> -t coqui-worker docker/coqui-worker

FROM rust:1.100-alpine AS builder

# build-base for the bundled SQLite; cmake and perl for aws-lc (rustls).
RUN apk add --no-cache build-base cmake perl git

WORKDIR /src

# Empty means "use the rev pinned in Cargo.toml" (and the exact Cargo.lock).
ARG COQUI_CORE_REV=""

COPY Cargo.toml Cargo.lock ./

# Build the dependencies on their own first so source edits don't refetch
# and recompile the whole tree.
RUN set -eu; \
    if [ -n "$COQUI_CORE_REV" ]; then \
      sed -i -E "s|(github.com/adamgreenwell/coqui\", rev = \")[0-9a-f]+|\1${COQUI_CORE_REV}|" Cargo.toml; \
      LOCKED=""; \
    else \
      LOCKED="--locked"; \
    fi; \
    mkdir src && echo 'fn main() {}' > src/main.rs; \
    cargo build --release $LOCKED; \
    rm -rf src

COPY src ./src

RUN set -eu; \
    touch src/main.rs; \
    if [ -n "$COQUI_CORE_REV" ]; then cargo build --release; else cargo build --release --locked; fi; \
    # Fail the build if the binary isn't fully static.
    if ldd target/release/coqui-worker 2>&1 | grep -q '=>'; then \
      echo "coqui-worker is dynamically linked" >&2; exit 1; \
    fi; \
    cp target/release/coqui-worker /coqui-worker

FROM alpine:3.23

# ca-certificates for the callback over HTTPS; busybox wget for the healthcheck.
RUN apk add --no-cache ca-certificates tzdata \
    && addgroup -S coqui && adduser -S coqui -G coqui

COPY --from=builder /coqui-worker /app/coqui-worker

# Crawls run one at a time by default so a 5,000-URL crawl stays inside a
# 256MB container; each crawl's SQLite file lives under /tmp until it's sent.
ENV COQUI_WORKER_PORT=8085 \
    COQUI_CONCURRENCY=4 \
    COQUI_MAX_CRAWLS=1 \
    RUST_LOG=info

EXPOSE 8085

USER coqui

HEALTHCHECK --interval=15s --timeout=3s --start-period=5s --retries=3 \
  CMD wget -qO- "http://127.0.0.1:${COQUI_WORKER_PORT}/health" >/dev/null || exit 1

ENTRYPOINT ["/app/coqui-worker"]
