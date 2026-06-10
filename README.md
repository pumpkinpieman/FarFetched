<p align="center">
  <img src="assets/logo-wordmark.svg" alt="FarFetched" width="360">
</p>

# FarFetched

A small, single-user, **personal-use** tool for Linux (Apache + PHP):
browse Printables by category, select models, queue them, and a paced
background worker downloads the STL/3MF files to a folder you choose
(default `/mnt/user/Downloads/models`).

It is **not** a site mirror. It downloads what you'd download by hand, slowly.
This slow method ensures your account isn't marked as a bot. You *will* get banned
for processing files too quickly!
(default 120s between files), one logged-in session, respecting per-model
licenses. Pace + filters keep it on the right side of Printables' ToS.


## Run with Docker (recommended on localhost)

Single container: Apache + PHP 8.3 + cron, all inside. No host PHP version
fights, no `nobody:users` permission dance.

```bash
# 1. clone
git clone https://github.com/pumpkinpieman/farfetched.git
cd farfetched

# 2. point the downloads volume at a real host folder
#    (edit docker-compose.yml: /mnt/user/Downloads/models/)
#    (create folders if they do not exist)
mkdir /mnt/user/Downloads
mkdir /mnt/user/Downloads/models

# 3. build + run
docker compose up -d --build

# 4. browse
open http://192.168.1.50:8088/
```

Then: **Settings** (paste token, confirm download dir is `/downloads`) →
**Verify Seams** → **Browse** → watch **Queue**. The worker runs on cron
inside the container every 5 min, self-paced at `FETCHER_DOWNLOAD_DELAY`
seconds per file.

Persistence:
- `fetcher_private` named volume holds the token, SQLite queue, and logs —
  survives rebuilds.
- Your chosen host folder (bind-mounted to `/downloads`) is where STL/3MF
  files land.

Env knobs (in `docker-compose.yml`):
- `FETCHER_DOWNLOAD_DELAY` — seconds between files (default 120; raise to be gentler).
- `FETCHER_DOWNLOAD_DIR` — in-container download path (default `/downloads`; leave as-is).

### Unraid
Add via **Docker → Add Container** (or Compose Manager): map a host port to
container `80`, bind a host share to `/downloads`, and create/keep the
`private` volume. No template needed beyond that.

## Layout

```
farfetched/
├── webroot/                  <- Apache DocumentRoot points HERE
│   ├── bootstrap.php          shared: paths, SQLite PDO, config stores, helpers
│   ├── index.php              browse + filter + select + queue
│   ├── jobs.php               live queue status
│   ├── settings.php           token + download-location config
│   ├── verify.php             API-seam probe (raw JSON; delete after verifying) (delete when done w/ setup!)
│   ├── enqueue.php            POST: selection -> queued jobs
│   ├── PrintablesService.php  live GraphQL client
│   ├── worker.php             CLI-only paced queue drainer (cron)
│   └── .htaccess              blocks worker.php, db, dotfiles
├── deploy/
│   ├── fetcher.conf           Apache 2.4 virtual host
│   └── hosts-entry.txt        hosts-file line for fetcher.local
├── private/                   created at runtime, OUTSIDE webroot:
│   ├── printables_token.php     your session token (chmod 600)
│   ├── download_dir.php         chosen download path
│   ├── fetcher.db               SQLite queue (WAL)
│   └── *.log                    worker + apache logs
└── schema.sql                 reference (auto-applied by bootstrap)
```

`private/` MUST sit one level above `webroot/`. The code resolves it as
`dirname(webroot)/private`, so deploy the whole folder and point Apache at
`webroot/`.

## 1. Deploy + Apache vhost

1. Copy the project somewhere on your Linux server, e.g.
   `/mnt/user/appdata/farfetched/`. (If you use a different path, edit
   the two paths in `deploy/fetcher.conf`.)
2. Install the vhost:
   - Debian/Ubuntu Apache: copy `deploy/fetcher.conf` to
     `/etc/apache2/sites-available/`, then `a2ensite fetcher && systemctl reload apache2`.
   - Generic httpd: drop it in `conf.d/` (or `Include` it) and reload.
   - Requires `mod_rewrite`/`AllowOverride All` so the bundled `.htaccess` applies.
3. PHP 8.1+ with `curl`, `pdo_sqlite`.

## 2. Hosts entry (so `fetcher.local` resolves)

On every machine you browse FROM, add the line in `deploy/hosts-entry.txt` to
the hosts file (replace the IP with local's LAN IP; use `192.168.1.50` if
browsing on local itself):

```
192.168.1.50    fetcher.local
```

| OS            | Hosts file path                                  |
|---------------|--------------------------------------------------|
| Linux / macOS | `/etc/hosts` (sudo)                              |
| Windows       | `C:\Windows\System32\drivers\etc\hosts` (admin)  |

Then browse to **http://fetcher.local/**.

## 3. Configure (Settings page)

- **Token:** log into printables.com → DevTools → Network → click any
  `graphql` request → copy the `Authorization` header value → paste → Save →
  Validate. (logout > login - this encapsulates your token easier)
- **Download Location:** confirm/adjust path → Save & Create. Green dot =
  writable; amber = fix share permissions (below) and re-save.

## 4. Verify the API seams (IMPORTANT — do before a real run)

The Printables API is undocumented. Open
**http://fetcher.local/verify.php** and run each probe. It prints the RAW
JSON Printables returns, so you can compare actual field names against what
`PrintablesService.php` assumes, and edit the service where they differ.

How to get the inputs from DevTools (logged into printables.com):
1. **Token** → Network tab → any `graphql` request → Request Headers →
   `Authorization`. (Already done in Settings.)
2. **categoryId [S2]** → click a left-nav category on the site → watch the new
   `graphql` request → in its **Payload**, read `variables.categoryId`. Plug
   that number into the search probe. Fill the confirmed IDs into
   `PrintablesService::CATEGORY_IDS`.
3. **model id [S4]** → open any model page; the id is the number in the URL
   (`/model/<id>-slug`). Run the files probe; confirm the field that holds the
   file list (`stls`, etc.) and each file's `id`/`name`.
4. **file id [S5]** → from the files-probe output. Run the link probe; confirm
   `getDownloadLink` returns `output.link`, and that the `fileType` enum value
   (STL vs BINARY_STL vs 3MF) and `source` are accepted.

Seams, all marked `SEAM TO VERIFY` in code:
- **[S1]** `morePrints` search query + field names
- **[S2]** numeric `categoryId` per category
- **[S3]** image CDN prefix for thumbnails
- **[S4]** `print()` model→files query + file field/enum names
- **[S5]** `getDownloadLink` mutation enum values
- **token** the `me { id publicUsername }` field

Everything falls into a clean error banner if a guess is wrong — nothing
crashes; it just won't fetch until the seams are confirmed.

**Delete or block `verify.php` once you're done** — it exposes raw API output.

## 5. Run the worker (cron on local)

```cron
*/5 * * * * /usr/bin/php /mnt/user/appdata/farfetched/webroot/worker.php >> /mnt/user/appdata/farfetched/private/worker.log 2>&1
```

A lock file prevents overlap; empty-queue runs exit instantly. The worker
self-paces at 120s/file regardless of cron frequency. Watch progress on the
**Queue** page (auto-refreshes while active).

## Unraid permissions

`/mnt/user/...` shares are owned by `nobody:users`. If the download dir shows
amber/not-writable, ensure the Apache/PHP process user can write there
(match the container user, or `chmod`/`chown` the target share), then re-save.

## Tuning

- Pace: `DOWNLOAD_DELAY_SECONDS` in `bootstrap.php` (default 120).
- Retry cap: `MAX_ATTEMPTS` in `worker.php` (default 3).
- Batch cap: 2000 models per submit (`enqueue.php`).

