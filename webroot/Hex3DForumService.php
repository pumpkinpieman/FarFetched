<?php
declare(strict_types=1);

/**
 * Hex3DForumService
 *
 * Topic browsing and attachment download for hex3dpatreon.com — a phpBB3
 * forum gating access by active Patreon subscription. Every topic in an
 * accessible forum can have one or more file attachments (zips), which any
 * member with forum access can download directly — same flat-access shape as
 * Nikko Industries, just on phpBB instead of WooCommerce.
 *
 * Auth: paste the full Cookie header from a logged-in browser tab (phpBB
 * session cookie + the persistent login cookie if "Remember me" was used).
 * No API, no token — plain HTML scraping throughout.
 *
 * Structure (confirmed via live HAR + screenshots):
 *   viewforum.php?f={forumId}            -> list of topics in a forum
 *   viewtopic.php?f={forumId}&t={topicId} -> a single topic/post; attachments
 *                                            appear in a boxed "ATTACHMENTS"
 *                                            list, one or more files per topic
 *   download/file.php?id={attachmentId}   -> direct file download, HTTP 200,
 *                                            no redirect, cookie-gated
 *
 * Forum IDs are user-supplied (pasted in Settings) rather than auto-discovered
 * — the index page lists ~80 forums across nested categories, more than is
 * useful as a fixed dropdown, so the source treats forum IDs as a flat list
 * to browse instead.
 *
 * One inference, not directly confirmed: the topic-list markup on
 * viewforum.php is assumed to follow the standard phpBB3 pattern
 * (<a class="topictitle" href=".../viewtopic.php?f=X&t=Y">Name</a> inside
 * <li class="row"> blocks) — this is true for the vast majority of phpBB3
 * installs including this board's Milk_v2/prosilver-derived theme, but it's
 * the one piece of this service not verified against a real saved page. If
 * browse() returns zero topics, this is the first thing to check.
 */
final class Hex3DForumService
{
    // Bare domain (no www) — matches how the board issues its own links and
    // referers; the www host is a separate origin for session validation.
    private const BASE = 'https://hex3dpatreon.com';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0';

    // phpBB names its cookies phpbb3_{boardhash}_{u,sid,k}. The board hash is
    // fixed per-install (derived at setup, not per-session), so it's a constant
    // here — if hex3dpatreon ever reinstalls and it changes, this is the one
    // line to update. Used to reconstruct cookie names from the bare values the
    // user pastes into the three Settings fields.
    private const COOKIE_PREFIX = 'phpbb3_3ceqg_';

    public string $lastError = '';
    public int    $lastTotal = 0;

    private string $cookie;
    private string $sid = '';

    /**
     * Builds the session cookie from either:
     *   - the three bare values stored in config (hex3dforum_u/_sid/_k), which
     *     is the normal path (Settings has three labeled fields), OR
     *   - a single pre-built Cookie header passed in / stored in the legacy
     *     hex3dforum_cookie field (backward compatibility).
     */
    public function __construct(?string $cookie = null)
    {
        if ($cookie !== null && $cookie !== '') {
            // Explicit header passed in (e.g. validate-on-save with a raw value).
            $this->cookie = trim($cookie);
        } else {
            $u   = function_exists('cfg') ? trim((string) cfg('hex3dforum_u'))   : '';
            $sid = function_exists('cfg') ? trim((string) cfg('hex3dforum_sid')) : '';
            $k   = function_exists('cfg') ? trim((string) cfg('hex3dforum_k'))   : '';

            if ($u !== '' || $sid !== '' || $k !== '') {
                $parts = [];
                if ($u   !== '') $parts[] = self::COOKIE_PREFIX . 'u='   . $u;
                if ($sid !== '') $parts[] = self::COOKIE_PREFIX . 'sid=' . $sid;
                if ($k   !== '') $parts[] = self::COOKIE_PREFIX . 'k='   . $k;
                $this->cookie = implode('; ', $parts);
            } else {
                // Legacy single combined-cookie field.
                $this->cookie = function_exists('cfg') ? trim((string) cfg('hex3dforum_cookie')) : '';
            }
        }

        // This board runs phpBB's strict session mode: forum/topic/download
        // pages reject a request whose session ID is only in the cookie — the
        // SID must ALSO travel as a ?sid= URL parameter (and in the Referer),
        // exactly as the board's own links do. Pull it out of the cookie so we
        // can thread it through every content URL. Confirmed via live capture:
        // identical request login-walls without ?sid=, returns 200 with it.
        if (preg_match('/phpbb3_[a-z0-9]+_sid=([a-f0-9]+)/i', $this->cookie, $m)) {
            $this->sid = $m[1];
        }
    }

    public function isAuthed(): bool
    {
        return $this->cookie !== '';
    }

    /**
     * Append the session ID as a URL parameter — required by this board for
     * any authenticated content page. No-op if we couldn't parse a SID.
     */
    private function withSid(string $url): string
    {
        if ($this->sid === '') return $url;
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'sid=' . $this->sid;
    }

    /**
     * Scrape the board index for every forum the logged-in member can see,
     * returning an ordered id => name map. This is how the source enumerates
     * its catalog — there's no fixed ID list to maintain; the board's own
     * index is the source of truth, re-read live each time.
     *
     * Two markup shapes are parsed (both verified against the real index):
     *   - Top-level categories:  <a href="./viewforum.php?f=N" class="cat_title">Name</a>
     *   - Sub-forum tiles:        <a href="./viewforum.php?f=N" class="tile_inner">
     *                                ... <div class="tile_title"> Name
     *
     * @return array<string,string> forumId => display name, in page order
     */
    public function discoverForums(): array
    {
        $this->lastError = '';
        $html = $this->getHtml(self::BASE . '/index.php');
        if ($html === null) {
            return [];
        }

        $forums = [];

        // Top-level category links.
        if (preg_match_all(
            '#<a[^>]+href="\./viewforum\.php\?f=(\d+)"\s+class="cat_title">\s*([^<]+?)\s*</a>#i',
            $html,
            $cm,
            PREG_SET_ORDER
        )) {
            foreach ($cm as $m) {
                $forums[$m[1]] = trim(html_entity_decode($m[2], ENT_QUOTES));
            }
        }

        // Sub-forum tiles (name lives in a nested tile_title div).
        if (preg_match_all(
            '#<a href="\./viewforum\.php\?f=(\d+)" class="tile_inner">.*?<div class="tile_title">\s*([^<]+?)\s*<#is',
            $html,
            $sm,
            PREG_SET_ORDER
        )) {
            foreach ($sm as $m) {
                $forums[$m[1]] = trim(html_entity_decode($m[2], ENT_QUOTES));
            }
        }

        if ($forums === []) {
            $this->lastError = 'No forums found on the board index — session may be expired, or the board is empty for this account.';
        }

        return $forums;
    }

    /**
     * Resolve a forum's display name by scraping its own page title. Used so
     * Settings can show a friendly label next to a pasted forum ID without
     * maintaining a hardcoded id => name map.
     */
    public function forumName(int|string $forumId): string
    {
        $forumId = (string) $forumId;
        $html = $this->getHtml($this->withSid(self::BASE . '/viewforum.php?f=' . rawurlencode($forumId)));
        if ($html === null) {
            return 'Forum ' . $forumId;
        }
        if (preg_match('#<a[^>]+href="\./viewforum\.php\?f=\d+"\s+class="cat_title">\s*([^<]+?)\s*<#i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES));
        }
        if (preg_match('#<title>\s*([^<]+?)\s*</title>#i', $html, $m)) {
            // Title is usually "Board Name - Forum Name"; take the part after the dash.
            $parts = explode(' - ', $m[1]);
            return trim(html_entity_decode(end($parts), ENT_QUOTES));
        }
        return 'Forum ' . $forumId;
    }

    /**
     * Browse topics within a single forum ID, paginated phpBB-style (start
     * offset, not page number — matches the site's own ?start=N convention).
     *
     * @return array<int,array<string,mixed>>
     */
    public function browse(int|string $forumId, int $limit = 20, int $offset = 0): array
    {
        $forumId = (string) $forumId;
        $this->lastError = '';
        $this->lastTotal = 0;

        $url = $this->withSid(self::BASE . '/viewforum.php?f=' . rawurlencode($forumId));
        if ($offset > 0) {
            $url .= '&start=' . $offset;
        }

        $html = $this->getHtml($url);
        if ($html === null) {
            return [];
        }

        $this->lastTotal = $this->extractTopicCount($html);
        $items = $this->extractTopics($html, $forumId);

        return array_slice($items, 0, $limit);
    }

    /** Parse the "N Topics" count phpBB shows at the top/bottom of a forum page. */
    private function extractTopicCount(string $html): int
    {
        if (preg_match('/(\d+)\s+Topics?\b/i', $html, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Pull topic entries off a forum listing page.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractTopics(string $html, string $forumId): array
    {
        $out = [];

        if (!preg_match_all(
            '#<a[^>]+href="\./viewtopic\.php\?f=' . preg_quote($forumId, '#') . '&amp;t=(\d+)"[^>]*class="topictitle"[^>]*>\s*([^<]+?)\s*</a>#i',
            $html,
            $matches
        )) {
            return $out;
        }

        foreach ($matches[1] as $i => $topicId) {
            $name = trim(html_entity_decode($matches[2][$i], ENT_QUOTES));
            if ($name === '') {
                $name = 'Topic ' . $topicId;
            }
            $out[] = [
                'id'      => $topicId,
                'slug'    => $forumId . '-' . $topicId,
                'name'    => $name,
                'creator' => 'Hex3D',
                'thumb'   => '',
                'images'  => [],
                'size'    => 0,
                'source'  => 'hex3dforum',
            ];
        }

        return $out;
    }

    /**
     * Fetch a single topic page once and pull out both its preview thumbnail
     * and all attachment IDs — used by the background crawler so indexing a
     * topic costs ONE request, not separate calls for image and files.
     *
     * The model preview is the first content image in the post body that lives
     * under the board's own upload path (/imag2/ or /download/file.php?...&mode=view),
     * skipping theme chrome, the Hex3D logo, QR codes, and avatars.
     *
     * @return array{thumb:string, attachment_ids:string[]}|null  null if the
     *         page couldn't be loaded (caller decides whether the session died).
     */
    public function fetchTopicDetail(string $forumId, string $topicId): ?array
    {
        $this->lastError = '';

        $url = $this->withSid(self::BASE . '/viewtopic.php?f=' . rawurlencode($forumId) . '&t=' . rawurlencode($topicId));
        $html = $this->getHtml($url);
        if ($html === null) {
            return null;
        }

        // Attachment IDs (may be zero — some topics are image-only posts).
        $attachmentIds = [];
        if (preg_match_all('#(?:href|src)="\./download/file\.php\?id=(\d+)#i', $html, $am)) {
            $attachmentIds = array_values(array_unique($am[1]));
        }

        // Preview thumbnail: phpBB tags user-posted content images with
        // class="postimage" — that's the model preview. Avatars (class="avatar"),
        // the board logo, and smilies (class="smilies") never carry it, so this
        // one attribute cleanly separates the real image from all the chrome.
        // The src is often relative (e.g. "imag/preduck1.PNG"), so resolve it.
        // Confirmed against real markup:
        //   <img src="imag/preduck1.PNG" class="postimage" alt="Image">
        $thumb = '';
        if (preg_match('#<img[^>]*\bclass="[^"]*postimage[^"]*"[^>]*>#i', $html, $imgTag)) {
            if (preg_match('#src="([^"]+)"#i', $imgTag[0], $sm)) {
                $thumb = $this->absoluteUrl(html_entity_decode($sm[1], ENT_QUOTES));
            }
        }
        // Some posts put class before src in a different order, or use a
        // postimage anchor wrapping the img — fall back to any board-hosted
        // /imag/ or /imag2/ upload that isn't avatar/logo/smilie chrome.
        if ($thumb === '' && preg_match_all('#<img[^>]+src="([^"]+)"[^>]*>#i', $html, $imgs, PREG_SET_ORDER)) {
            foreach ($imgs as $img) {
                $full = $img[0];
                $src  = html_entity_decode($img[1], ENT_QUOTES);
                $low  = strtolower($full);
                if (str_contains($low, 'avatar')   || str_contains($low, 'smilies') ||
                    str_contains($low, 'logo')     || str_contains($low, '/styles/') ||
                    str_contains($low, 'patreon')) {
                    continue;
                }
                if (preg_match('#(^|/)imag2?/#i', $src)) {
                    $thumb = $this->absoluteUrl($src);
                    break;
                }
            }
        }

        return ['thumb' => $thumb, 'attachment_ids' => $attachmentIds];
    }

    /** Resolve a possibly-relative URL against the board base. */
    private function absoluteUrl(string $url): string
    {
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        if (str_starts_with($url, '//')) return 'https:' . $url;
        if (str_starts_with($url, './')) return self::BASE . '/' . substr($url, 2);
        if ($url[0] === '/') return self::BASE . $url;
        return self::BASE . '/' . $url;
    }

    /**
     * Resolve every attachment download URL for a topic. A single topic can
     * have multiple files (e.g. one zip per color variant) — confirmed
     * directly in the source UI screenshots.
     *
     * @return string[]
     */
    public function getDownloadUrls(string $modelId, string $slug): array
    {
        $this->lastError = '';

        // slug is "{forumId}-{topicId}"; modelId alone is just the topic id,
        // so prefer slug when present since it carries the forum id too.
        $forumId = '';
        $topicId = $modelId;
        if ($slug !== '' && str_contains($slug, '-')) {
            [$forumId, $topicId] = explode('-', $slug, 2);
        }

        $url = $this->withSid(self::BASE . '/viewtopic.php?t=' . rawurlencode($topicId));
        if ($forumId !== '') {
            $url = $this->withSid(self::BASE . '/viewtopic.php?f=' . rawurlencode($forumId) . '&t=' . rawurlencode($topicId));
        }

        $html = $this->getHtml($url);
        if ($html === null) {
            return [];
        }

        // Attachment links: href="./download/file.php?id=NNNN" inside the
        // ATTACHMENTS box. Confirmed directly from saved topic-page screenshots.
        if (!preg_match_all(
            '#href="\./download/file\.php\?id=(\d+)"#i',
            $html,
            $m
        )) {
            $this->lastError = 'No attachments found on this topic — it may not have any files, or access to this forum has expired.';
            return [];
        }

        $ids = array_unique($m[1]);
        $urls = [];
        foreach ($ids as $id) {
            // Download endpoint is session-gated too — SID in the URL.
            $urls[] = $this->withSid(self::BASE . '/download/file.php?id=' . $id);
        }
        return $urls;
    }

    /**
     * Stream an attachment to disk. The download endpoint is cookie-gated
     * (same session as browsing), unlike Nikko's signed-URL handoff.
     */
    public function downloadToFile(string $url, string $dest, ?callable $progressFn = null): bool
    {
        $this->lastError = '';
        if (!$this->isAuthed()) {
            $this->lastError = 'No Hex3D Forum session cookie set (paste in Settings).';
            return false;
        }

        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            $this->lastError = 'Could not open destination file: ' . $dest;
            return false;
        }

       $written = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_REFERER        => self::BASE . '/index.php' . ($this->sid !== '' ? '?sid=' . $this->sid : ''),
            CURLOPT_HTTPHEADER     => [
                'Cookie: ' . $this->cookie,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: same-origin',
                'Sec-Fetch-User: ?1',
            ],
            CURLOPT_FILE           => $fh,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use ($fh, &$written, $progressFn): int {
                $n = fwrite($fh, $data);
                if ($n === false) return -1;
                $written += $n;
                if ($progressFn !== null) $progressFn($written);
                return $n;
            },
        ]);

        curl_exec($ch);
        $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($err !== '') {
            $this->lastError = 'cURL error: ' . $err;
            @unlink($dest);
            return false;
        }
        if ($st === 401 || $st === 403) {
            $this->lastError = 'Hex3D Forum download rejected (HTTP ' . $st . ') — re-paste session cookie in Settings.';
            @unlink($dest);
            return false;
        }
        if ($st >= 400) {
            $this->lastError = 'HTTP ' . $st . ' downloading ' . basename($dest);
            @unlink($dest);
            return false;
        }
        if ($written === 0) {
            $this->lastError = 'Empty response.';
            @unlink($dest);
            return false;
        }

        return true;
    }

    public function validate(): bool
    {
        if (!$this->isAuthed()) {
            $this->lastError = 'No Hex3D Forum session cookie stored.';
            return false;
        }
        $html = $this->getHtml(self::BASE . '/index.php');
        if ($html === null) {
            return false;
        }
        // Logged-out guests are told "This board has no forums" — an active,
        // authenticated member instead sees real forum/category links.
        return stripos($html, 'This board has no forums') === false
            && stripos($html, 'viewforum.php') !== false;
    }

    // ---- Private helpers ---------------------------------------------------

    private function getHtml(string $url): ?string
    {
        if (!$this->isAuthed()) {
            $this->lastError = 'No Hex3D Forum session cookie set (paste in Settings).';
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_ENCODING       => '',
            // Referer must carry the SID-bearing index URL, and the navigation
            // Sec-Fetch headers must be present — both are part of what this
            // board checks on content pages (verified against a working browser
            // capture). Without the SID-in-referer the session is rejected.
            CURLOPT_REFERER        => self::BASE . '/index.php' . ($this->sid !== '' ? '?sid=' . $this->sid : ''),
            CURLOPT_HTTPHEADER     => [
                'Cookie: ' . $this->cookie,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: same-origin',
                'Sec-Fetch-User: ?1',
                'Upgrade-Insecure-Requests: 1',
            ],
        ]);
        $body = curl_exec($ch);
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'Hex3D Forum network error: ' . $err;
            return null;
        }
        if ($st === 401 || $st === 403) {
            $this->lastError = 'Hex3D Forum auth failed (HTTP ' . $st . ') — re-paste session cookie in Settings.';
            return null;
        }
        if ($st >= 400) {
            $this->lastError = 'Hex3D Forum HTTP ' . $st;
            return null;
        }
        if (stripos((string) $body, 'This board has no forums') !== false) {
            $this->lastError = 'Hex3D Forum session expired or lacks access — re-paste session cookie in Settings.';
            return null;
        }
        return (string) $body;
    }
}
