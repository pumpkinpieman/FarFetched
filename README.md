# FarFetched

**A patient, self-hosted fetcher for your 3D-print library.**

FarFetched browses, searches, and downloads models from **Printables, MakerWorld, Thingiverse, Cults3D, and STLFlix** at a deliberate, polite pace — then keeps them in a tidy local library with a built-in STL / 3MF viewer. One container, your server.

Built by [BTCB Design](https://www.btcbdesign.com).

---

## Why

Bulk-downloading from model sites either means clicking one file at a time forever, or hammering a free API until it rate-limits you. FarFetched sits in the middle: queue everything you want, and a background worker pulls it down one file at a time with a configurable delay — courteous to the source, hands-off for you. Everything lands in a clean local folder you own, browsable in-app and ready for your slicer.

---

## Features

- **Five sources, one queue** — Printables, MakerWorld, Thingiverse, Cults3D, and STLFlix. Switch sources without losing your selection.
- **Paced downloads** — a configurable delay between every fetch keeps the source service happy. The queue shows exactly when the next file fires.
- **Live queue progress** — per-file and overall progress update in real time without reloading, including source badges (PT / MW / TV / C3D / SF) on every job.
- **Search & infinite scroll** — keyword search across all five sources, results streaming in as you scroll.
- **STL & 3MF viewer** — spin up any downloaded model in the browser. A resilient 3MF loader handles slicer exports that trip up stock parsers.
- **Model management** — delete models directly from the viewer with multi-select and a confirm step.
- **Local library** — downloads land in a clean, organized folder you own, one subfolder per source.
- **Cross-category multi-select** — build a batch across categories and sources before hitting Download.
- **Prefer-pack option** — optionally pull whole-model ZIPs and extract, instead of fetching files one at a time.
- **Paste-once auth** — drop in one token per source; FarFetched keeps itself signed in.

---

## Quick start (Docker)

```bash
docker run -d \
  --name FarFetched \
  -p 16545:80 \
  -e FETCHER_DOWNLOAD_DIR=/downloads \
  -e FETCHER_DOWNLOAD_DELAY=120 \
  -v /path/to/your/models:/downloads \
  -v /path/to/persistent/data:/var/www/html/private \
  ghcr.io/pumpkinpieman/farfetched:latest
```

Then open `http://your-server:16545` and add your source tokens under **Settings**.

### Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `FETCHER_DOWNLOAD_DIR` | `/downloads` | Container path where models are saved (bind-mount this). |
| `FETCHER_DOWNLOAD_DELAY` | `120` | Default seconds between downloads (also tunable per-source in Settings). |

### Volumes

| Mount | Purpose |
|---|---|
| `/downloads` | Where your models land. Map to a host folder you control. |
| `/var/www/html/private` | Persistent state: SQLite job queue, tokens, config, logs. |

---

## Finding your auth tokens

Each source needs a token or credentials, pulled from your browser's DevTools while logged in. The in-app **Settings → Sources** page and the home page include step-by-step guides for Chrome, Firefox, Edge, and Safari. In brief:

- **Printables / MakerWorld / Thingiverse / STLFlix** — DevTools → Network → find an API/GraphQL request → copy the `Authorization: Bearer …` header.
- **Cults3D** — DevTools → Application/Storage → Cookies → copy `user_email` and `user_token`.

Tokens expire on each platform's own schedule (Printables ~1h, STLFlix ~30 days); just re-paste when a source stops authing.

---

## Architecture

- **PHP 8.3 + Apache** in a single Docker image.
- **SQLite (WAL)** job queue in the persistent volume.
- **Background worker** invoked by cron, self-locking against overlap, self-pacing between downloads.
- **Three.js** viewer for STL/3MF preview.
- **Vanilla JS** front end — no build step.

### Notable engineering

- **Lock-free live progress** — the worker streams state to a small JSON file the UI polls, rather than fighting the job store for write locks.
- **Defensive API queries** — GraphQL queries ask for the richer shape first and fall back automatically, so a schema change degrades one feature instead of blanking the page.
- **Resilient 3MF loading** — a fallback unzips the archive and parses mesh geometry directly, then re-seats the model upright.
- **Cron-safe path resolution** — path resolution falls back through the process env, the entrypoint env file, and the conventional mount point, so downloads land correctly regardless of how the worker is launched.
- **Server-side image proxy** — thumbnails from CORS-restricted CDNs (Cults3D, Thingiverse) are streamed through an allowlisted server-side proxy.
- **Auto-format detection with fallback** — handles zip vs. bare .3mf/.stl per model, with an STL → 3MF → pack fallback when a requested format is missing.

---

## Project layout

```
webroot/                     # Apache document root
├── index.php                # Browse / search UI + router
├── home.php                 # Source picker + token guide
├── jobs.php / jobs_status.php  # Download queue (live)
├── viewer.php               # STL / 3MF 3D viewer + delete
├── settings.php             # Per-source auth, worker tuning, donate
├── worker.php               # CLI download worker (cron)
├── bootstrap.php            # Config, paths, DB, shared helpers
├── proxy.php                # Server-side image proxy
├── model_file.php           # Path-safe file streaming for the viewer
├── model_delete.php         # Model deletion endpoint
├── enqueue.php / job_action.php
└── *Service.php             # One client per source
```

---

## Security notes

- All state-changing endpoints require POST + CSRF token.
- Source slugs and model names are validated to single path segments; deletion is confirmed to resolve inside the source directory via `realpath()` before any file is touched.
- The image proxy is host-allowlisted and follows redirects only within that allowlist (SSRF-safe).
- Tokens live in the private volume, never in the web root.

---

## Support

Like the project? [Buy me a ko-fi ☕](https://ko-fi.com/bloodthirstycheeseburger90415)

---

## License

Open source. See `LICENSE` for details.