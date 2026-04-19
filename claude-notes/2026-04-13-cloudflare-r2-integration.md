# Cloudflare R2 CDN Integration — film.md

**Date:** 2026-04-13

## What was done

Implemented full Cloudflare R2 integration for file uploads across the film.md admin panel. Previously, images were converted to base64 data URLs on the client and embedded inline in JSON payloads — no real file upload existed.

### Backend (Laravel — film.md-admin-api)

- **Installed** `league/flysystem-aws-s3-v3` for S3-compatible storage
- **Fixed `.env`** — separated `AWS_ENDPOINT` (R2 S3 API) from `AWS_URL` (public CDN URL). Previously only `AWS_URL` was set, pointing to the S3 API endpoint which is wrong
- **Created `MediaUploadService`** (`app/Services/MediaUploadService.php`):
  - Uploads files to R2 via `Storage::disk('s3')`
  - Validates MIME types (jpeg, png, webp, avif, gif, svg), max 10MB
  - Generates ULID-based filenames for uniqueness
  - Supports delete by public URL
  - Uses hardcoded `'s3'` disk (not global `FILESYSTEM_DISK`) to keep default disk as `local`
- **Created `UploadController`** (`app/Http/Controllers/Api/Admin/UploadController.php`):
  - `POST /api/v1/admin/upload` — single file (`file`) or batch (`files[]`), with optional `directory` param
  - `DELETE /api/v1/admin/upload` — delete by URL
  - Protected by `content.create` / `content.edit` permissions
- **Updated `StoreContentRequest`** — removed base64 data URL validation, now only accepts `https://` URLs for image fields (max 2048 chars)

### Frontend (React — film.md-admin)

- **Added upload methods to `api.ts`**: `uploadFile()`, `uploadFiles()`, `deleteUpload()`
- **Rewrote `ImageUploadField.tsx`**:
  - Removed `FileReader` / base64 conversion
  - Now uploads files via `POST /admin/upload` multipart/form-data
  - Shows loading spinner during upload
  - Displays upload errors inline
  - Accepts `uploadDirectory` prop for R2 path organization
- **Added `uploadDirectory` to all ImageUploadField usages** in `ContentEditor.tsx` and `HomeCuration.tsx`:
  - `content/posters`, `content/backdrops`, `content/heroes`, `content/previews`
  - `content/avatars` (cast + crew), `content/video-thumbnails`
  - `content/seasons`, `content/episodes`
  - `home/heroes`

### Frontend (React — film.md-client)

- **No changes needed** — already renders images from URL strings, so CDN URLs work automatically

## Files changed

### New files
- `app/Services/MediaUploadService.php`
- `app/Http/Controllers/Api/Admin/UploadController.php`

### Modified files
- `.env` — added `AWS_ENDPOINT`, fixed `AWS_URL`
- `.env.example` — updated R2 config template
- `routes/api.php` — added upload routes
- `app/Http/Requests/Admin/StoreContentRequest.php` — removed base64 validation
- `src/lib/api.ts` — added upload/delete methods
- `src/components/shared/ImageUploadField.tsx` — full rewrite for CDN upload
- `src/pages/ContentEditor.tsx` — added `uploadDirectory` props
- `src/pages/HomeCuration.tsx` — added `uploadDirectory` props
- `composer.json` / `composer.lock` — added `league/flysystem-aws-s3-v3`

## Decisions made

1. **R2 over S3** — Cloudflare R2 is S3-compatible, zero egress fees, built-in CDN
2. **Dedicated disk, not global** — `MediaUploadService` uses `'s3'` disk explicitly; `FILESYSTEM_DISK` stays `local`
3. **ULID filenames** — prevents collisions and enables chronological sorting
4. **Removed base64 support** — base64 in JSON is inefficient (33% larger), causes DB bloat, defeats CDN caching
5. **Directory-based organization** — each image type gets its own R2 prefix

## TODOs

- [ ] **Activate Public Access** on the R2 bucket in Cloudflare Dashboard (or configure custom domain like `cdn.filmmd.ro`)
- [ ] Update `AWS_URL` in `.env` with the actual public URL once public access is enabled
- [ ] Consider adding image optimization (Cloudflare Images or on-upload resize)
- [ ] Consider adding old image cleanup — when a content's poster_url changes, delete the old file from R2
- [ ] Add integration tests for `UploadController`
- [ ] Consider adding a CORS policy on the R2 bucket if direct browser uploads are ever needed
