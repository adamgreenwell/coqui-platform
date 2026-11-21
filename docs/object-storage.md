# Object storage for crawl exports

**Status:** foundation built, waiting on the desktop importer.
**Decision:** customer uploads go straight to a private Cloudflare R2 bucket through a presigned URL. They never touch the application container's disk, and they never pass through PHP or nginx.

## Why not just write it to `storage/`

Three reasons, in order of how quickly they bite:

1. **The app container's volume is not a file store.** `docker-compose.yml` gives the app a `storage_data` volume shared with Horizon and the scheduler. A 500 MB Screaming Frog database per project would fill the disk the queue and logs live on, and every backup of that volume grows with customer data.
2. **The web server caps request bodies at 32 MB** (`docker/nginx/default.conf`, `docker/php/local.ini`). Import files are routinely larger, and raising the cap moves the problem into PHP memory.
3. **Scaling.** A second app replica cannot read another replica's local disk. Object storage is the only shape that survives horizontal scaling.

## The flow

```text
Desktop                          Platform                        R2
   |  POST /projects/{p}/imports      |                            |
   |--------------------------------->|  row: pending              |
   |  { id, upload.url, headers }     |                            |
   |<---------------------------------|                            |
   |  PUT upload.url  (the file)      |                            |
   |------------------------------------------------------------->|
   |  POST /imports/{id}/complete     |                            |
   |--------------------------------->|  HEAD object, check size   |
   |                                  |--------------------------->|
   |  { status: uploaded }            |  row: uploaded             |
   |<---------------------------------|                            |
```

Nothing in the platform needs the bytes yet. When the importer lands (desktop-side parse, or a queued job), it resolves the file through `CrawlImportService::downloadUrl()`, which is a five-minute presigned GET.

## Rows and states

`crawl_imports` is the contract; the object is an implementation detail behind `object_key`.

| Status | Meaning | Who sets it |
|---|---|---|
| `pending` | Row reserved, upload URL issued, nothing in the bucket yet | `POST /imports` |
| `uploaded` | Object exists and its size passed the plan check | `POST /imports/{id}/complete` |
| `processing` | An importer has taken it (not built yet) | future job |
| `completed` | Parsed into a `crawl_run`; `crawl_run_id` is set | future job |
| `failed` | Rejected, oversized, or the import errored; `error` says why | complete, or the job |
| — | Deleted | `DELETE /imports/{id}` or `imports:prune` |

`external_import_id` is the desktop's own identifier for the file. It is unique per project, so a retried reservation updates one row instead of queueing a second copy of the same file.

## Bucket layout

```text
ws/{workspace_uuid}/projects/{project_uuid}/imports/{import_uuid}/{sanitised_filename}
```

The workspace prefix is deliberate: it makes tenant ownership legible in the bucket, lets a prefix be deleted in one operation if a workspace is erased, and keeps lifecycle rules expressible per prefix. Filenames are sanitised to `[A-Za-z0-9._-]`, so no key can contain `..` or a path separator.

## Limits

Sized by plan in `PlanCatalog`, enforced twice: at reservation against the client's declared size, and at completion against the object's real size (an oversized object is deleted, not kept).

| Plan | Max import |
|---|---|
| Free | 25 MB |
| Solo | 100 MB |
| Pro | 500 MB |
| Agency | 2 GB |

Accepted extensions: `.seospider`, `.dbseospider`, `.sqlite`, `.db`, `.zip`. The extension is a shape check, not a trust signal — the importer must still validate what it opens.

## Retention

| Item | Lifecycle |
|---|---|
| Pending reservation, never uploaded | deleted after **24 hours** |
| Failed import | deleted after **7 days** |
| Uploaded, waiting for an importer | kept until imported |
| Completed import | should follow crawl retention (#154), which is not built yet |

`php artisan imports:prune` runs daily at 03:45 and removes expired pending rows and old failures **including their objects**. It is idempotent: a row already gone is skipped.

## Security

- **Private bucket.** No public access, no public URL is ever generated.
- **Presigned PUT**, 15 minutes, scoped to one object key.
- **Presigned GET**, five minutes, issued per request to an authorised caller.
- **Workspace isolation:** the route resolves the import and authorises against its project; a user outside the workspace gets 404, not 403, so ids cannot be probed.
- **Tokens:** the existing Sanctum `crawls:sync` ability covers the import endpoints, so desktop tokens need no new scope.
- **Not implemented:** checksum verification against the declared SHA-256 (stored, not enforced), malware scanning, and content sniffing. All three belong in front of the importer, not the uploader.

## Configuration

```dotenv
IMPORTS_DISK=r2
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=
R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_REGION=auto
R2_USE_PATH_STYLE_ENDPOINT=false
```

The R2 API token needs **Object Read & Write** on this bucket only — not account-wide. With any of the four values blank the endpoints answer `503 storage_not_configured` rather than accepting a file the server cannot store, which is the same posture as an unconfigured data provider.

**CORS** is not required for the desktop client (native HTTP). It becomes necessary only if the web UI ever uploads directly; at that point the bucket needs a CORS rule allowing `PUT` from the app origin.

**Lifecycle:** an optional bucket rule can expire `ws/` objects after N days as a backstop. Do not rely on it for correctness — `imports:prune` owns retention so the database and the bucket cannot disagree.

## API

### Reserve an upload

```http
POST /api/v1/projects/{project}/imports
Authorization: Bearer <token with crawls:sync>
Content-Type: application/json

{ "filename": "https-adamgreenwell-com-.dbseospider", "size_bytes": 1048576 }
```

```json
{
  "data": { "id": "0193...", "status": "pending", "filename": "...", "sizeBytes": 1048576, "expiresAt": "2026-11-22T12:00:00+00:00" },
  "upload": { "url": "https://...r2.cloudflarestorage.com/...", "headers": { "Content-Type": "application/octet-stream" }, "expiresAt": "2026-11-21T12:15:00+00:00" }
}
```

`422` when the filename is not an accepted type or the declared size exceeds the plan.

### Complete

```http
POST /api/v1/imports/{import}/complete
```

`200` with the row at `uploaded`; `422` when the object is missing, the window expired, or the real size exceeds the plan (in which case the object is deleted).

Idempotent: calling it twice returns the same row.

### Inspect / cancel

`GET /api/v1/imports/{import}` returns the row and, once uploaded, a five-minute `downloadUrl`.
`DELETE /api/v1/imports/{import}` removes the row and the object.

## What the desktop session needs to do

1. Reserve with `filename` and the real byte size — do not stream and hope.
2. `PUT` the file to `upload.url` with the returned headers, unmodified.
3. Call `complete`; treat `422` as "upload did not land" and retry from step 1 with the `external_import_id` it already generated.
4. Poll `GET /imports/{id}` (or wait for the future processing contract) for the resulting `crawlRunId`.

## Deliberately not built

- **Multipart upload** — R2's single PUT handles 2 GB, which is the largest plan. Add multipart only if a plan exceeds ~5 GB.
- **Server-side parsing** — the platform does not open a Screaming Frog database yet; that is the importer's job.
- **Per-workspace storage quota** — size caps are per file. A total-bytes quota needs usage accounting, which is the same counter the retention work (#154) needs.
- **Deduplication** — the declared SHA-256 is stored so it can be added later without a migration.
