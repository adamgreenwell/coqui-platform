# Multi-stage build for headless coqui-worker Rust sidecar
# Compiles coqui-core + coqui-crawl into a minimal statically linked container binary.

FROM rust:1.85-alpine AS builder

WORKDIR /usr/src/coqui-worker

# Install build dependencies
RUN apk add --no-cache musl-dev openssl-dev openssl-libs-static git pkgconfig

# Copy sources or clone coqui-core
ARG COQUI_CORE_REV=main
ENV RUSTFLAGS="-C target-feature=+crt-static"

# Reference architecture: compiled binary placed in /app/coqui-worker
COPY . /usr/src/coqui-worker/

# Final lightweight runtime image
FROM alpine:3.21

RUN apk add --no-cache ca-certificates tzdata

WORKDIR /app

# Non-root unprivileged service account
RUN addgroup -S coqui && adduser -S coqui -G coqui

# Resource bounds: memory limited to 256MB
ENV COQUI_WORKER_PORT=8085 \
    COQUI_WORKER_MEMORY_LIMIT=256MB \
    COQUI_CONCURRENCY=4 \
    RUST_LOG=info

EXPOSE 8085

USER coqui

HEALTHCHECK --interval=15s --timeout=3s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8085/health || exit 1

ENTRYPOINT ["/app/coqui-worker"]
