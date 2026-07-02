<?php
declare(strict_types=1);

/**
 * MakerWorldService
 *
 * Second model source for FarFetched, alongside PrintablesService. Mirrors that
 * class's public contract so the worker/UI can treat sources interchangeably.
 *
 * Confirmed API surface (reverse-engineered from MakerWorld web client):
 *   - Search  : GET  /api/v1/search-service/select/design2
 *               ?keyword=&limit=&offset=&orderBy=score&designType=0
 *               Returns {"total":N,"hits":[ {id,title,slug,cover,
 *                        designCreator:{name,handle}, nsfw,
 *                        designExtension:{model_files:[…]}}, … ]}
 *               No auth needed; requires the X-BBL-* client headers.
 *   - Zip link: GET  /api/v1/design-service/design/{designId}/model
 *               ?modelType=all&type=download   (needs `token` cookie)
 *               Returns JSON containing a signed makerworld.bblmw.com .zip URL.
 *   - Download: GET  https://makerworld.bblmw.com/.../all.zip?at=&exp=&key=&uid=
 *               Auth-free, ~5 min expiry. Plain GET (mirror Printables).
 *
 * MakerWorld downloads are always "whole-model ZIP" (PACK-equivalent).
 */
final class MakerWorldService
{
    private const API   = 'https://makerworld.com/api/v1';
    private const UA    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FarFetched/1.0';
    private const CDN_RE = '~https://makerworld\.bblmw\.com/[^\s"\'\\\\]+~';

    /** Max per-design cover lookups per author page (bounds request latency). */
    private const MW_AUTHOR_COVER_BACKFILL_MAX = 24;

    public string $lastError      = '';
    public int    $lastTotalCount = 0;
    /** Raw hit count of the most recent search page (before NSFW filtering). */
    public int    $lastPageHitCount = 0;
    /** Raw body of the most recent API call — surfaced for debugging empty parses. */
    public string $lastRaw        = '';

    private string $token;

    public function __construct(?string $token = null)
    {
        // Falls back to the stored MakerWorld token when not explicitly passed.
        $this->token = $token ?? (function_exists('cfg') ? (string) cfg('makerworld_token') : '');
        $this->token = trim($this->token);
    }

    public function isAuthed(): bool
    {
        return $this->token !== '';
    }

    /**
     * Keyword search. Returns a list of normalized model rows:
     *   ['id','slug','name','creator','thumb','size','nsfw','source'=>'makerworld']
     * `size` is 0 when MakerWorld doesn't expose file sizes for that hit.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchByKeyword(string $query, int $limit = 20, int $offset = 0, bool $showNsfw = false, string $categoryId = ''): array
    {
        $this->lastError = '';
        $this->lastTotalCount = 0;
        $this->lastPageHitCount = 0;

        $categoryId = preg_replace('/[^0-9]/', '', $categoryId);
        $isBrowse   = ($categoryId !== '' || $query === '');

        $params = [
            'keyword'    => $query,
            'limit'      => max(1, $limit),
            'offset'     => max(0, $offset),
            // Browsing (category / All Models) ranks by hotScore like MakerWorld's
            // own category pages; keyword search ranks by relevance (score).
            'orderBy'    => $isBrowse ? 'hotScore' : 'score',
            'designType' => 0,
        ];
        if ($isBrowse) {
            // Confirmed category filter: ?categories={numericId} (bare id, plural key).
            // Empty = All Models (popular browse). entrance=list matches the site.
            if ($categoryId !== '') {
                $params['categories'] = $categoryId;
            }
            $params['designCreateSince'] = 0;
            $params['entrance']          = 'list';
        } else {
            $params['isFromSearchList'] = 'false';
        }
        $json = $this->apiGet(self::API . '/search-service/select/design2?' . http_build_query($params));
        if ($json === null) {
            // lastError already set by apiGet
            return [];
        }

        $this->lastTotalCount = (int) ($json['total'] ?? 0);
        $hits = $json['hits'] ?? null;
        if (!is_array($hits)) {
            $this->lastError = 'Unexpected search response (no hits array).';
            return [];
        }
        // Raw hit count BEFORE NSFW filtering — drives pagination independently of
        // `total` (MakerWorld's popular/All-Models feed often returns total=0).
        $this->lastPageHitCount = count($hits);

        return $this->normalizeHits($hits, $showNsfw);
    }

    /**
     * Search a creator's PUBLISHED models by their MakerWorld numeric uid.
     *
     * Verified against live captures (2026-06-30):
     *   GET /api/v1/design-service/published/{uid}/design?limit=&offset=
     *   -> { total:int, hits:[ <same hit schema as keyword search> ] }
     * The uid in the PATH is the filter; the site also tacks on ?handle=@name but
     * that is cosmetic (hits carry an empty designCreator.handle), so we omit it.
     * Offset-based with a real `total`, so it drives the same pagination as
     * searchByKeyword(). Each normalized hit carries designCreator.uid as
     * `creator_id`, which is exactly what the "More by author" button passes here.
     *
     * NOTE: $uid must be the numeric creator id. MakerWorld's API blanks the
     * handle field, so there is no reliable name/handle -> uid path; callers that
     * only have a display name should fall back to a keyword search instead.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchByAuthor(string $uid, int $limit = 20, int $offset = 0, bool $showNsfw = false): array
    {
        $this->lastError        = '';
        $this->lastTotalCount   = 0;
        $this->lastPageHitCount = 0;

        $uid = trim($uid);
        if ($uid === '' || !ctype_digit($uid)) {
            $this->lastError = 'MakerWorld author search needs a numeric creator id.';
            return [];
        }

        $params = [
            'limit'  => max(1, $limit),
            'offset' => max(0, $offset),
            'ref_'   => 'def_MWUserDetail_Uploads',
        ];
        $url  = self::API . '/design-service/published/' . $uid . '/design?' . http_build_query($params);
        $json = $this->apiGet($url);
        if ($json === null) {
            // lastError already set by apiGet
            return [];
        }

        $this->lastTotalCount = (int) ($json['total'] ?? 0);
        $hits = $json['hits'] ?? null;
        if (!is_array($hits)) {
            $this->lastError = 'Unexpected author response (no hits array).';
            return [];
        }
        $this->lastPageHitCount = count($hits);

        $rows = $this->normalizeHits($hits, $showNsfw);

        // The author endpoint (published/{uid}/design) returns hits WITHOUT any
        // cover/gallery field, so normalizeHits yields empty thumbs. Backfill each
        // missing cover from the per-design endpoint (verified coverUrl). Bounded
        // (MW_AUTHOR_COVER_BACKFILL_MAX) so a full page can't fan out into dozens
        // of sequential API calls and blow the request timeout; each call is
        // isolated so one failure can't abort the whole result set.
        $budget = self::MW_AUTHOR_COVER_BACKFILL_MAX;
        foreach ($rows as &$row) {
            if ($budget <= 0) {
                break;
            }
            if (($row['thumb'] ?? '') !== '' || ($row['id'] ?? '') === '') {
                continue;
            }
            $budget--;
            try {
                $cover = $this->coverForModel((string) $row['id']);
                if ($cover !== '') {
                    $cover = $this->thumbTransform($cover);
                    $row['thumb']  = $cover;
                    $row['images'] = [$cover];
                }
            } catch (\Throwable $e) {
                // Non-fatal: leave this row's thumb empty and keep going.
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Normalize a list of MakerWorld design hits into the shared model-row shape
     * used across the app. Shared by searchByKeyword() and searchByAuthor() so
     * both produce byte-identical rows. NSFW hits are dropped unless $showNsfw.
     *
     * Row shape:
     *   ['id','slug','name','creator','creator_id','thumb','images','size',
     *    'nsfw','source'=>'makerworld']
     *
     * @param array<int,mixed> $hits
     * @return array<int,array<string,mixed>>
     */
    private function normalizeHits(array $hits, bool $showNsfw): array
    {
        $out = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $nsfw = (bool) ($hit['nsfw'] ?? false);
            if ($nsfw && !$showNsfw) {
                continue; // hide adult content unless explicitly allowed
            }

            $creator   = '';
            $creatorId = '';
            if (isset($hit['designCreator']) && is_array($hit['designCreator'])) {
                $creator   = (string) ($hit['designCreator']['name'] ?? $hit['designCreator']['handle'] ?? '');
                $rawUid    = $hit['designCreator']['uid'] ?? '';
                $creatorId = is_scalar($rawUid) ? (string) $rawUid : '';
                if ($creatorId === '0') {
                    $creatorId = '';
                }
            }

            $size = 0;
            if (isset($hit['designExtension']['model_files']) && is_array($hit['designExtension']['model_files'])) {
                $size = $this->sumModelFiles($hit['designExtension']['model_files']);
            }

            // Gallery images for the hover slider. design_pictures[0] is the cover,
            // so the slider opens on the same image as the static thumb. Each URL is
            // forced through the OSS transform to a small webp — this also collapses
            // multi-MB animated GIFs to a light static frame, keeping the grid cheap.
            //
            // The keyword-search and author (/published/{uid}/design) endpoints nest
            // the cover under different keys, so resolve defensively across the known
            // shapes rather than assuming one field. mwCollectImages() returns
            // [thumb, images[]] already transformed and de-duplicated.
            [$thumb, $images] = $this->mwCollectImages($hit);

            $out[] = [
                'id'         => (string) ($hit['id'] ?? ''),
                'slug'       => (string) ($hit['slug'] ?? ''),
                'name'       => (string) ($hit['title'] ?? ''),
                'creator'    => $creator,
                'creator_id' => $creatorId,
                'thumb'      => $thumb,
                'images'     => $images,
                'size'       => $size,
                'nsfw'       => $nsfw,
                'source'     => 'makerworld',
            ];
        }
        return $out;
    }

    /**
     * Mint a signed download URL for a design (auth required).
     * Always fetches the combined whole-model pack (all formats included).
     * Tries, in order:
     *   1. design/{id}/model?modelType=all&type=download  → combined pack
     *   2. design/{id}/model?type=download                → plain download doc
     *   3. instance/{id}/f3mf?type=download               → per-instance fallback
     * Returns the first signed makerworld.bblmw.com URL found, or '' on failure.
     */
    /**
     * Print stats for a design's default (or best) profile, from the same
     * design endpoint we already hit. MakerWorld stores, per instance:
     *   prediction = total print seconds, weight = total grams.
     * We pick the default instance (defaultInstanceId) when present, else the
     * first, and also surface the colour count and a couple of alternates.
     *
     * @return array{ok:bool,printSeconds?:int,weightG?:int,colors?:int,plates?:int,profiles?:array}
     */
    /**
     * Cover image URL for a single design, or '' if unavailable. Uses the same
     * design-service endpoint as getPrintStats; the design object carries the
     * cover (and/or a design_pictures gallery whose first entry is the cover).
     */
    public function coverForModel(string $designId): string
    {
        $json = $this->apiGet(self::API . '/design-service/design/' . $designId);
        if (!is_array($json)) return '';
        // The design endpoint returns fields at the top level (no data wrapper).
        // Verified field names: coverUrl (primary), coverPortrait / coverLandscape
        // as fallbacks. Values are already absolute https URLs.
        $root = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : $json;
        foreach (['coverUrl', 'coverPortrait', 'coverLandscape'] as $key) {
            $u = trim((string) ($root[$key] ?? ''));
            if ($u !== '') return $u;
        }
        return '';
    }

    /**
     * Resolve a design's creator numeric uid. MakerWorld author search is keyed
     * by this uid — the creator's name/handle are not valid search keys.
     *
     * Primary source: the public model page's embedded __NEXT_DATA__, verified to
     * carry props.pageProps.design.designCreator = {uid,name,handle}. The JSON API
     * detail endpoint (design-service/design/{id}) frequently omits designCreator
     * (region/auth dependent), which is why it's only a fallback here.
     *
     * Returns '' when it can't be determined.
     */
    public function creatorUidForModel(string $designId): string
    {
        $designId = preg_replace('/[^0-9]/', '', $designId);
        if ($designId === '') {
            return '';
        }

        // --- Primary: model-page __NEXT_DATA__ -------------------------------
        $uid = $this->uidFromModelPage($designId);
        if ($uid !== '') {
            return $uid;
        }

        // --- Fallback: JSON detail endpoint (best-effort) --------------------
        $json = $this->apiGet(self::API . '/design-service/design/' . $designId);
        if (!is_array($json)) {
            return '';
        }
        $root = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : $json;
        if (isset($root['designCreator']['uid']) && $root['designCreator']['uid']) {
            return (string) $root['designCreator']['uid'];
        }
        foreach (['createId', 'userId', 'creatorUid'] as $k) {
            if (!empty($root[$k]) && is_scalar($root[$k])) {
                return (string) $root[$k];
            }
        }
        if (isset($root['instances'][0]) && is_array($root['instances'][0])) {
            $u = $root['instances'][0]['designCreatorUid'] ?? '';
            if ($u) {
                return (string) $u;
            }
        }
        return '';
    }

    /**
     * Pull the creator uid out of a model page's __NEXT_DATA__ blob.
     * Verified path: props.pageProps.design.designCreator.uid. The model's own
     * creator appears before any relateDesigns, so the first designCreator match
     * is a safe regex fallback if the JSON shape ever shifts.
     */
    private function uidFromModelPage(string $designId): string
    {
        $html = $this->httpGetRaw('https://makerworld.com/en/models/' . $designId);
        if ($html === '') {
            return '';
        }
        if (preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m)) {
            $j = json_decode($m[1], true);
            if (is_array($j)) {
                $uid = $j['props']['pageProps']['design']['designCreator']['uid'] ?? null;
                if ($uid && is_scalar($uid)) {
                    return (string) $uid;
                }
                // Some responses expose pageProps at the top level (data route shape)
                $uid = $j['pageProps']['design']['designCreator']['uid'] ?? null;
                if ($uid && is_scalar($uid)) {
                    return (string) $uid;
                }
            }
        }
        if (preg_match('#"designCreator":\{"uid":(\d+)#', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Plain GET returning the raw response body (HTML), following the id -> slug
     * redirect. Used for scraping public pages that carry data the JSON API omits.
     */
    private function httpGetRaw(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::UA,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        if ($this->token !== '') {
            // Some pages (e.g. the makerlab customizer) render source only when
            // authenticated; harmless on public pages.
            curl_setopt($ch, CURLOPT_COOKIE, 'token=' . $this->token);
        }
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            return '';
        }
        return (string) $body;
    }

    /**
     * Recover a parametric model's OpenSCAD source. MakerWorld omits the .scad
     * from the normal download pack, but the customizer page embeds it verbatim
     * at props.pageProps.scadContent (raw text, present even when isProtected).
     *
     * Returns ['filename' => string, 'code' => string], or null when the model
     * isn't OpenSCAD-parametric (e.g. Fusion edits) or the source isn't exposed.
     */
    public function scadForModel(string $designId): ?array
    {
        $designId = preg_replace('/[^0-9]/', '', $designId);
        if ($designId === '') {
            return null;
        }
        $url  = 'https://makerworld.com/en/makerlab/parametricModelMaker?designId=' . $designId . '&modelName=model.scad';
        $html = $this->httpGetRaw($url);
        if ($html === '') {
            return null;
        }
        if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m)) {
            return null;
        }
        $j = json_decode($m[1], true);
        if (!is_array($j)) {
            return null;
        }
        $pp = $j['props']['pageProps'] ?? ($j['pageProps'] ?? []);
        if (!is_array($pp)) {
            return null;
        }
        if (!empty($pp['isFusionEdit'])) {
            return null; // Fusion parametric, not OpenSCAD — no .scad source
        }
        $code = (string) ($pp['scadContent'] ?? '');
        if (trim($code) === '') {
            return null;
        }

        // Sanitize the embedded filename to a safe basename ending in .scad.
        $fname = (string) ($pp['modelName'] ?? '');
        $fname = basename(str_replace('\\', '/', $fname));
        $fname = preg_replace('/[^\w.\- ]+/u', '_', $fname) ?? '';
        $fname = trim($fname);
        if ($fname === '') {
            $fname = 'model.scad';
        }
        if (!preg_match('/\.(scad|py)$/i', $fname)) {
            $fname .= '.scad';
        }

        return ['filename' => $fname, 'code' => $code];
    }

    /**
     * Diagnostic sibling of scadForModel(): performs the same customizer fetch
     * but returns a full step-by-step report instead of null/data, so we can see
     * exactly where recovery fails from the container (fetch vs parse vs empty).
     *
     * @return array<string,mixed>
     */
    public function probeScad(string $designId, bool $useToken = true): array
    {
        $designId = preg_replace('/[^0-9]/', '', $designId);
        $url = 'https://makerworld.com/en/makerlab/parametricModelMaker?designId=' . $designId . '&modelName=model.scad';
        $out = [
            'designId'    => $designId,
            'url'         => $url,
            'token_set'   => $this->token !== '',
            'use_token'   => $useToken && $this->token !== '',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::UA,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        if ($useToken && $this->token !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, 'token=' . $this->token);
        }
        $body = curl_exec($ch);
        $out['http_status']   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out['effective_url'] = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $out['redirect_count']= (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        $out['curl_error']    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $out['body_len'] = 0;
            $out['note'] = 'curl failed (network/DNS/TLS).';
            return $out;
        }
        $body = (string) $body;
        $out['body_len']   = strlen($body);
        $out['body_head']  = substr($body, 0, 200);
        $out['has_next_data'] = (bool) preg_match('#<script id="__NEXT_DATA__"#', $body);

        if (!$out['has_next_data']) {
            $out['note'] = 'No __NEXT_DATA__ — likely a login wall, bot check, or non-model page.';
            return $out;
        }
        preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $body, $m);
        $j = json_decode($m[1] ?? '', true);
        if (!is_array($j)) {
            $out['note'] = '__NEXT_DATA__ present but not decodable.';
            return $out;
        }
        $pp = $j['props']['pageProps'] ?? ($j['pageProps'] ?? []);
        $out['modelName']    = $pp['modelName'] ?? null;
        $out['isFusionEdit'] = $pp['isFusionEdit'] ?? null;
        $out['isProtected']  = $pp['isProtected'] ?? null;
        $out['scad_len']     = strlen((string) ($pp['scadContent'] ?? ''));
        $out['note']         = $out['scad_len'] > 0 ? 'OK — scadContent present.' : 'Page loaded but scadContent empty.';
        return $out;
    }

    public function getPrintStats(string $designId): array
    {
        $json = $this->apiGet(self::API . '/design-service/design/' . $designId);
        if (!is_array($json)) {
            return ['ok' => false];
        }
        $root = $json;
        if (isset($json['data']) && is_array($json['data'])) {
            $root = $json['data'];
        }
        $instances = $root['instances'] ?? [];
        if (!is_array($instances) || $instances === []) {
            return ['ok' => false];
        }

        $defaultId = (string) ($root['defaultInstanceId'] ?? '');

        // Choose the default instance, else the first with a prediction.
        $chosen = null;
        foreach ($instances as $inst) {
            if ($defaultId !== '' && (string) ($inst['id'] ?? '') === $defaultId) {
                $chosen = $inst;
                break;
            }
        }
        if ($chosen === null) {
            foreach ($instances as $inst) {
                if ((int) ($inst['prediction'] ?? 0) > 0) { $chosen = $inst; break; }
            }
        }
        if ($chosen === null) {
            return ['ok' => false];
        }

        $printSeconds = (int) ($chosen['prediction'] ?? 0);
        $weightG      = (int) round((float) ($chosen['weight'] ?? 0));
        $colors       = (int) ($chosen['materialColorCnt'] ?? ($chosen['materialCnt'] ?? 0));
        $plates       = is_array($chosen['plates'] ?? null) ? count($chosen['plates']) : 0;

        if ($printSeconds === 0 && $weightG === 0) {
            return ['ok' => false];
        }

        // A few alternate profiles for context (title + time + weight).
        $profiles = [];
        foreach (array_slice($instances, 0, 4) as $inst) {
            $ps = (int) ($inst['prediction'] ?? 0);
            if ($ps <= 0) continue;
            $profiles[] = [
                'title'        => (string) ($inst['title'] ?? ''),
                'printSeconds' => $ps,
                'weightG'      => (int) round((float) ($inst['weight'] ?? 0)),
            ];
        }

        return [
            'ok'           => true,
            'printSeconds' => $printSeconds,
            'weightG'      => $weightG,
            'colors'       => $colors,
            'plates'       => $plates,
            'profiles'     => $profiles,
        ];
    }

    /**
     * Force the per-instance STL/3MF download route, bypassing the combined
     * whole-model pack. Used when the combined pack is unavailable because the
     * model's *source* file (e.g. a parametric .scad/.step) is marked private —
     * MakerWorld still serves the printable STL/3MF instances. Returns a signed
     * URL or '' (with lastError set). On success, sets $this->sourcePrivateNote
     * so the caller can warn that only STL/3MF were fetched.
     */
    public string $sourcePrivateNote = '';

    public function getInstanceFileLink(string $designId): string
    {
        $this->lastError = '';
        $this->sourcePrivateNote = '';
        $designId = preg_replace('/[^0-9]/', '', $designId);
        if ($designId === '') { $this->lastError = 'Invalid MakerWorld design id.'; return ''; }
        if (!$this->isAuthed()) { $this->lastError = 'MakerWorld token not set.'; return ''; }

        $instanceId = $this->findInstanceId($designId);
        if ($instanceId === '') {
            $this->lastError = 'No printable instance found for this MakerWorld model.';
            return '';
        }
        $url = $this->mintFrom(self::API . '/design-service/instance/' . $instanceId . '/f3mf?type=download');
        if ($url !== '') {
            $this->sourcePrivateNote = 'source file is not available - stl/3mf files downloaded';
            return $url;
        }
        // last resort: per-instance STL bundle
        $url = $this->mintFrom(self::API . '/design-service/instance/' . $instanceId . '/stl?type=download');
        if ($url !== '') {
            $this->sourcePrivateNote = 'source file is not available - stl/3mf files downloaded';
            return $url;
        }
        if ($this->lastError === '') {
            $this->lastError = 'No STL/3MF instance files available for this MakerWorld model.';
        }
        return '';
    }

    /** Heuristic: does a getModelZipLink failure look like a private/forbidden source file? */
    public function looksPrivateSource(): bool
    {
        $e = strtolower($this->lastError);
        return strpos($e, 'private') !== false
            || strpos($e, 'permission') !== false
            || strpos($e, 'forbidden') !== false
            || strpos($e, '403') !== false
            || strpos($e, 'not available') !== false
            || strpos($e, 'no downloadable') !== false;
    }

    public function getModelZipLink(string $designId, string $fileType = 'PACK'): string
    {
        $this->lastError = '';        $designId = preg_replace('/[^0-9]/', '', $designId);
        if ($designId === '') {
            $this->lastError = 'Invalid MakerWorld design id.';
            return '';
        }
        if (!$this->isAuthed()) {
            $this->lastError = 'MakerWorld token not set (paste it in Settings).';
            return '';
        }

        // 1) combined whole-model pack
        $url = $this->mintFrom(self::API . '/design-service/design/' . $designId . '/model?modelType=all&type=download');
        if ($url !== '') {
            return $url;
        }
        $firstErr = $this->lastError;

        // 2) plain download doc (no modelType) — may carry per-instance links
        $url = $this->mintFrom(self::API . '/design-service/design/' . $designId . '/model?type=download');
        if ($url !== '') {
            return $url;
        }

        // 3) per-instance fallback
        $instanceId = $this->findInstanceId($designId);
        if ($instanceId !== '') {
            $url = $this->mintFrom(self::API . '/design-service/instance/' . $instanceId . '/f3mf?type=download');
            if ($url !== '') {
                return $url;
            }
        }

        $this->lastError = $firstErr !== '' ? $firstErr
            : ($this->lastError !== '' ? $this->lastError : 'No downloadable files found for this MakerWorld model.');
        return '';
    }

    /** Call a download-doc endpoint and extract a signed bblmw .zip URL (named field or regex). */
    private function mintFrom(string $url): string
    {
        $json = $this->apiGet($url);
        if (is_array($json)) {
            $candidate = $this->pick($json, 'url', 'downloadUrl', 'fileUrl', 'zipUrl');
            if ($candidate === '' && isset($json['data']) && is_array($json['data'])) {
                $candidate = $this->pick($json['data'], 'url', 'downloadUrl', 'fileUrl', 'zipUrl');
            }
            if ($candidate !== '') {
                return $candidate;
            }
        }
        // Covers all.zip AND per-instance _stls.zip / _f3mf.zip URLs embedded in the body.
        if ($this->lastRaw !== '' && preg_match(self::CDN_RE, $this->lastRaw, $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * Resolve a design's default print-profile (instance) id, needed for the
     * per-instance STL route. Tries the design-detail endpoint and the common
     * field shapes. Returns '' if it can't be determined.
     */
    private function findInstanceId(string $designId): string
    {
        $json = $this->apiGet(self::API . '/design-service/design/' . $designId);
        if (!is_array($json)) {
            return '';
        }
        // Common shapes: {instances:[{id}]}, {defaultInstanceId}, {instanceId},
        // or nested under data{}.
        $roots = [$json];
        if (isset($json['data']) && is_array($json['data'])) {
            $roots[] = $json['data'];
        }
        foreach ($roots as $r) {
            foreach (['defaultInstanceId', 'instanceId'] as $k) {
                if (!empty($r[$k]) && (is_int($r[$k]) || ctype_digit((string) $r[$k]))) {
                    return (string) $r[$k];
                }
            }
            if (isset($r['instances'][0]['id']) && (is_int($r['instances'][0]['id']) || ctype_digit((string) $r['instances'][0]['id']))) {
                return (string) $r['instances'][0]['id'];
            }
        }
        return '';
    }

    /**
     * Download a signed CDN URL to disk. The signed URL is auth-free, so this is
     * a plain GET — mirrors PrintablesService::downloadToFile (sans bearer).
     */
    public function downloadToFile(string $url, string $destPath, ?callable $onProgress = null): bool
    {
        $this->lastError = '';
        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->lastError = 'Cannot create destination dir: ' . $dir;
            return false;
        }

        $tmp = $destPath . '.part';
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) {
            $this->lastError = 'Cannot open temp file for writing.';
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::UA],
        ]);
        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($onProgress) {
                $onProgress((int) $dlTotal, (int) $dlNow);
                return 0;
            });
        }
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status >= 400) {
            @unlink($tmp);
            $this->lastError = $cerr !== '' ? ('Download error: ' . $cerr)
                                            : ('Download HTTP ' . $status);
            return false;
        }
        if (!@rename($tmp, $destPath)) {
            @unlink($tmp);
            $this->lastError = 'Could not finalize file (rename failed).';
            return false;
        }
        return true;
    }

    // ---- internals --------------------------------------------------------

    /**
     * Authenticated GET against the MakerWorld API. Returns decoded JSON array,
     * or null on transport/parse failure (lastError set). Raw body kept in lastRaw.
     *
     * @return array<string,mixed>|null
     */
    private function apiGet(string $url): ?array
    {
        $this->lastRaw = '';
        $headers = [
            'Accept: */*',
            'Content-Type: application/json',
            'User-Agent: ' . self::UA,
            'X-BBL-Client-Type: web',
            'X-BBL-Client-Version: 00.00.00.01',
            'X-BBL-App-Source: makerworld',
            'X-BBL-Client-Name: MakerWorld',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING       => '', // accept gzip/br transparently
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($this->token !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, 'token=' . $this->token);
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'Network error: ' . ($cerr !== '' ? $cerr : 'unknown');
            return null;
        }
        $this->lastRaw = (string) $body;

        if ($status >= 400) {
            // MakerWorld returns JSON errors like {"code":1,"error":"Please log in…"}
            $msg = '';
            $j = json_decode((string) $body, true);
            if (is_array($j)) {
                $msg = (string) ($j['error'] ?? $j['message'] ?? '');
            }
            $this->lastError = 'MakerWorld HTTP ' . $status . ($msg !== '' ? (': ' . $msg) : '');
            return null;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            $this->lastError = 'MakerWorld returned non-JSON (len ' . strlen((string) $body) . ').';
            return null;
        }
        return $json;
    }

    /**
     * Resolve [thumb, images[]] from a design hit across the differing shapes the
     * keyword-search and author endpoints return. Order of preference:
     *   1. designExtension.design_pictures[].url   (gallery — keyword endpoint)
     *   2. common cover keys (cover, coverUrl, cover_url, coverPic, image, ...)
     *   3. bounded recursive scan for the first plausible image URL (author endpoint)
     * All URLs pass through thumbTransform; the result is de-duplicated and capped.
     *
     * @return array{0:string,1:array<int,string>}  [thumb, images]
     */
    private function mwCollectImages(array $hit): array
    {
        $images = [];
        $push = function (string $u) use (&$images): void {
            $u = trim($u);
            if ($u === '' || !preg_match('~^https?://~i', $u)) {
                return;
            }
            $u = $this->thumbTransform($u);
            if (!in_array($u, $images, true) && count($images) < 8) {
                $images[] = $u;
            }
        };

        // 1) Explicit gallery (keyword endpoint).
        $gallery = $hit['designExtension']['design_pictures'] ?? null;
        if (is_array($gallery)) {
            foreach ($gallery as $pic) {
                if (is_array($pic)) {
                    $push((string) ($pic['url'] ?? $pic['picUrl'] ?? $pic['src'] ?? ''));
                } elseif (is_string($pic)) {
                    $push($pic);
                }
            }
        }

        // 2) Known cover keys (author endpoint uses a bare cover string/url).
        foreach (['cover', 'coverUrl', 'cover_url', 'coverPic', 'coverPicture', 'image', 'imageUrl', 'thumbnail'] as $k) {
            $v = $hit[$k] ?? null;
            if (is_string($v)) {
                $push($v);
            } elseif (is_array($v)) {
                $push((string) ($v['url'] ?? $v['src'] ?? ''));
            }
        }

        // 3) Last resort: bounded recursive scan for the first image-looking URL.
        if (!$images) {
            $found = $this->mwDeepFindImage($hit, 0);
            if ($found !== '') {
                $push($found);
            }
        }

        $thumb = $images[0] ?? '';
        return [$thumb, $images];
    }

    /** Depth-bounded scan for the first https image URL anywhere in a hit. */
    private function mwDeepFindImage($node, int $depth): string
    {
        if ($depth > 4 || !is_array($node)) {
            return '';
        }
        foreach ($node as $v) {
            if (is_string($v) && preg_match('~^https?://[^\s"]+\.(?:png|jpe?g|webp|gif|avif)(?:[?#]|$)~i', $v)) {
                return $v;
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $r = $this->mwDeepFindImage($v, $depth + 1);
                if ($r !== '') {
                    return $r;
                }
            }
        }
        return '';
    }

    /**
     * Rewrite a MakerWorld CDN image URL to a resized, webp-encoded variant via
     * the Alibaba OSS image pipeline. Non-CDN URLs are returned untouched.
     */
    private function thumbTransform(string $url, int $width = 800): string
    {
        if ($url === '' || !preg_match('~^https://makerworld\.bblmw\.com/~', $url)) {
            return $url; // leave non-CDN urls untouched
        }
        $base = explode('?', $url, 2)[0];
        return $base . '?x-oss-process=image/resize,w_' . $width . '/format,webp';
    }

    /** Recursively sum modelSize across files + nested dir children. */
    private function sumModelFiles(array $files): int
    {
        $total = 0;
        foreach ($files as $f) {
            if (!is_array($f)) {
                continue;
            }
            if (!empty($f['isDir']) && isset($f['children']) && is_array($f['children'])) {
                $total += $this->sumModelFiles($f['children']);
            } else {
                $total += (int) ($f['modelSize'] ?? 0);
            }
        }
        return $total;
    }

    /** First non-empty string value among the given keys. */
    private function pick(array $arr, string ...$keys): string
    {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && is_string($arr[$k]) && $arr[$k] !== '') {
                return $arr[$k];
            }
        }
        return '';
    }
}
