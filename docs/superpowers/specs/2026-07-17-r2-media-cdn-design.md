# Serve user media from Cloudflare R2 (hybrid public CDN + private photos)

**Status:** approved design, pending implementation
**Date:** 2026-07-17

## Goal

Move all user-facing media (book covers, page illustrations, uploaded
character photos) off the container's local disk and onto Cloudflare R2, so
files survive container rebuilds and are served fast from Cloudflare's edge.

## Access model: hybrid

Two R2 buckets, chosen because R2 public access is per-bucket:

- **Public bucket** (`R2_PUBLIC_BUCKET`), attached to a custom domain
  (`R2_PUBLIC_URL`, e.g. `https://cdn.cubfable.com`). Holds generated
  illustrations and covers. Served directly by URL and cached at the edge -
  this is the CDN.
- **Private bucket** (`R2_PRIVATE_BUCKET`), no public access, no custom
  domain. Holds uploaded photos of children (AI reference inputs). Never on a
  public URL; displayed through short-lived signed URLs where needed.

Rationale: generated art is the display-heavy, cache-worthy, low-sensitivity
asset and belongs on the CDN. Uploaded children's photos are sensitive PII
and are AI inputs rather than display assets, so keeping them private costs
almost nothing and avoids exposing real children's faces on public URLs.

## What changes

### 1. Disks (`config/filesystems.php`)

Add two S3-driver disks pointed at R2:

- `r2` (public): endpoint `https://<R2_ACCOUNT_ID>.r2.cloudflarestorage.com`,
  `region = auto`, `use_path_style_endpoint = true`, bucket
  `R2_PUBLIC_BUCKET`, `url = R2_PUBLIC_URL`, `visibility = public`.
- `r2_private` (private): same endpoint/region/credentials, bucket
  `R2_PRIVATE_BUCKET`, no `url`, `visibility = private`.

Both use the same `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY`.

### 2. Two media-disk config switches (`config/cubfable.php`)

- `cubfable.media_disk` (env `MEDIA_DISK`, default `public`) - the public/CDN
  assets disk.
- `cubfable.private_media_disk` (env `PRIVATE_MEDIA_DISK`, default `local`) -
  the private photos disk.

Production sets `MEDIA_DISK=r2` and `PRIVATE_MEDIA_DISK=r2_private`. Local dev
keeps the defaults (`public` / `local`), so nothing changes locally.

### 3. Route media code through the switches

Today ~11 sites hardcode `Storage::disk('public')`. Split them by asset type:

- **Public assets** (covers, page images, generated output): use the
  `media_disk`. Affects `BookImageStorage` (generated output), `Book`,
  `Page`, `ImageVersion`, `StoryGenerator`, and the generated-image reads in
  `ReplicateProvider`/`ImageReference` for page art.
- **Private assets** (uploaded character photos): use the
  `private_media_disk`. Affects `BookImageStorage::storeDataUrl`, `Character`
  (photo display via signed URL), `ReferencePolicy`, and the photo reads used
  as AI references.

`Character`'s photo URL accessor returns a short-lived
`temporaryUrl(...)` from the private disk instead of a permanent `url(...)`.

### 4. PDF builder temp-file fix (`StorybookPdfBuilder::resolveImage`)

`resolveImage()` currently calls `Storage::disk('public')->path()` for a local
absolute path, which does not exist on remote R2. Change it to download the
object bytes from the correct disk to a temporary local file, use that temp
path for `getimagesize` and embedding, and delete the temp files after the
build completes. This works for both local and R2 disks.

## Non-goals

- No migration of existing files (production starts fresh).
- No browser-direct (presigned) uploads; uploads keep flowing through the
  Laravel backend, so no CORS configuration is needed.
- No change to the wizard, pricing, or generation logic.

## Testing

- `Storage::fake()` the media disks in affected tests.
- `BookImageStorage`: stores generated output to the public disk and photos to
  the private disk; delete works on both.
- Config resolves both R2 disks with expected settings.
- `Character` photo accessor returns a signed URL from the private disk.
- `StorybookPdfBuilder` resolves an image from a faked (remote-style) disk via
  the temp-file path and cleans up.

## Cloudflare prerequisites (done by the owner)

1. Create the public and private R2 buckets.
2. Attach a custom domain to the public bucket only (public access on).
3. Create one R2 API token (Object Read & Write) -> access key + secret.
4. Provide `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`,
   `R2_PUBLIC_BUCKET`, `R2_PRIVATE_BUCKET`, `R2_PUBLIC_URL`.

## Env summary

```
R2_ACCOUNT_ID=
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_PUBLIC_BUCKET=cubfable-media
R2_PRIVATE_BUCKET=cubfable-private
R2_PUBLIC_URL=https://cdn.cubfable.com
MEDIA_DISK=r2
PRIVATE_MEDIA_DISK=r2_private
```
