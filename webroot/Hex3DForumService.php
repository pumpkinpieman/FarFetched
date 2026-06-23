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
    private const BASE = 'https://www.hex3dpatreon.com';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public string $lastError = '';
    public int    $lastTotal = 0;

    private string $cookie;

    public function __construct(?string $cookie = null)
    {
        $this->cookie = trim($cookie ?? (function_exists('cfg') ? (string) cfg('hex3dforum_cookie') : ''));
    }

    public function isAuthed(): bool
    {
        return $this->cookie !== '';
    }

    /**
     * Resolve a forum's display name by scraping its own page title. Used so
     * Settings can show a friendly label next to a pasted forum ID without
     * maintaining a hardcoded id => name map.
     */
    public function forumName(string $forumId): string
    {
        $html = $this->getHtml(self::BASE . '/viewforum.php?f=' . rawurlencode($forumId));
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
    public function browse(string $forumId, int $limit = 20, int $offset = 0): array
    {
        $this->lastError = '';
        $this->lastTotal = 0;

        $url = self::BASE . '/viewforum.php?f=' . rawurlencode($forumId);
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

        $url = self::BASE . '/viewtopic.php?t=' . rawurlencode($topicId);
        if ($forumId !== '') {
            $url = self::BASE . '/viewtopic.php?f=' . rawurlencode($forumId) . '&t=' . rawurlencode($topicId);
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
            $urls[] = self::BASE . '/download/file.php?id=' . $id;
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
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => ['Cookie: ' . $this->cookie],
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
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => [
                'Cookie: ' . $this->cookie,
                'Accept: text/html,application/xhtml+xml',
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
