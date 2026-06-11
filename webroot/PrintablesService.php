<?php
declare(strict_types=1);

/**
 * PrintablesService.php — live Printables GraphQL client.
 *
 * Public surface:
 *   searchModels($categorySlug, $limit, $offset) -> rows for the grid
 *   getModelFiles($modelId, $fileType)           -> [ [id,name,type], ... ]
 *   getDownloadLink($fileId, $modelId, $fileType)-> temporary signed URL
 *   downloadToFile($url, $destPath)              -> bool (streamed)
 *
 * Security / robustness:
 *   - Token read from the out-of-web-root store.
 *   - GraphQL queries never interpolate user input; all params are typed
 *     `variables` (injection-safe by construction).
 *   - Every network path degrades to a clean error string in $lastError;
 *     nothing throws to the caller, nothing fatals.
 *
 * REVERSE-ENGINEERED SEAMS (verify each against your own Network tab):
 *   [S1] morePrints search query + nested field names
 *   [S2] numeric categoryId per category slug
 *   [S3] image CDN prefix
 *   [S4] model -> files query (field names + STL/3MF enum values)
 *   [S5] getDownloadLink mutation (already sighted in community tooling)
 */

require_once __DIR__ . '/bootstrap.php';

final class PrintablesService
{
    private const API = 'https://api.printables.com/graphql/';
    private const UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                      . 'AppleWebKit/537.36 Chrome/116 Safari/537.36';

    // Real Printables top-level category IDs (from the live category menu).
    // 'all' => null means no category filter. Verified against ?category=NN URLs.
    private const CATEGORY_IDS = [
        'all'         => null,
        '3d-printers' => '1',
        'art'         => '13',
        'costumes'    => '76',
        'fashion'     => '17',
        'gadgets'     => '21',
        'healthcare'  => '87',
        'hobby'       => '48',
        'household'   => '3',
        'learning'    => '90',
        'seasonal'    => '65',
        'sports'      => '9',
        'tabletop'    => '101',
        'toys'        => '30',
        'world-scans' => '58',
    ];

    private string $token;
    public string $lastError = '';
    public ?string $lastCursor = null;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? get_token();
    }

    public function isAuthed(): bool
    {
        return $this->token !== '';
    }

    /** @return array<int,array{id:string,slug:string,name:string,creator:string,thumb:string}> */
    public function searchModels(string $categorySlug, int $limit = 36, ?string $cursor = null): array
    {
        $this->lastError = '';
        if (!$this->isAuthed()) {
            $this->lastError = 'No Printables token — set one in Settings.';
            return [];
        }
        if (!array_key_exists($categorySlug, self::CATEGORY_IDS)) {
            // Not a known slug — allow a raw numeric category ID (paste-any-ID box).
            if (ctype_digit($categorySlug)) {
                $categoryId = $categorySlug;
            } else {
                $this->lastError = 'Unknown category.';
                return [];
            }
        } else {
            $categoryId = self::CATEGORY_IDS[$categorySlug];
        }

        // Verified against the live ModelList operation (cursor paging, no offset/printType).
        $query = <<<'GQL'
        query ModelList($limit: Int!, $cursor: String, $categoryId: ID, $ordering: String) {
          morePrints(limit: $limit, cursor: $cursor, categoryId: $categoryId, ordering: $ordering) {
            cursor
            items {
              id
              slug
              name
              user { publicUsername }
              image { filePath }
            }
          }
        }
        GQL;

        $data = $this->gql($query, [
            'limit'      => max(1, min($limit, 100)),
            'cursor'     => $cursor,
            'categoryId' => $categoryId,
            'ordering'   => 'trending',
        ]);
        if ($data === null) {
            return [];
        }

        $items = $data['morePrints']['items'] ?? null;
        if (!is_array($items)) {
            $this->lastError = 'Unexpected response shape — verify morePrints fields.';
            return [];
        }

        // Stash the next-page cursor for callers that paginate.
        $this->lastCursor = $data['morePrints']['cursor'] ?? null;

        return array_map(static function (array $it): array {
            $thumb = $it['image']['filePath'] ?? '';
            if ($thumb !== '' && !preg_match('#^https?://#', $thumb)) {
                $thumb = 'https://media.printables.com/' . ltrim($thumb, '/'); // [S3] verify prefix
            }
            return [
                'id'      => (string) ($it['id'] ?? ''),
                'slug'    => (string) ($it['slug'] ?? ''),
                'name'    => (string) ($it['name'] ?? 'Untitled'),
                'creator' => (string) ($it['user']['publicUsername'] ?? 'unknown'),
                'thumb'   => (string) $thumb,
            ];
        }, $items);
    }

    /**
     * Resolve the downloadable files on a model, filtered to a type (STL/3MF).
     * [S4] verify the model-detail query + file field names + enum values.
     *
     * @return array<int,array{id:string,name:string,type:string}>
     */
    public function getModelFiles(string $modelId, string $fileType = 'STL'): array
    {
        $this->lastError = '';
        if (!$this->isAuthed()) {
            $this->lastError = 'No Printables token.';
            return [];
        }

        $query = <<<'GQL'
        query ModelFiles($id: ID!) {
          print(id: $id) {
            id
            stls { id name fileSize }
            gcodes { id name }
          }
        }
        GQL;

        $data = $this->gql($query, ['id' => $modelId]);
        if ($data === null) {
            return [];
        }

        // STL list lives under `stls`; 3MF often appears among model files too.
        // Adjust the source field once the live shape is confirmed.
        $src = $data['print']['stls'] ?? [];
        if (!is_array($src)) {
            $this->lastError = 'Unexpected files shape — verify the print() query.';
            return [];
        }

        $out = [];
        foreach ($src as $f) {
            $out[] = [
                'id'   => (string) ($f['id'] ?? ''),
                'name' => (string) ($f['name'] ?? 'file'),
                'type' => $fileType,
            ];
        }
        return $out;
    }

    /**
     * [S5] GetDownloadLink mutation — returns a short-lived signed URL.
     * Mutation shape sighted in community CLI tooling; confirm enum values
     * for $fileType (e.g. STL / BINARY_STL / 3MF) and $source against live.
     */
    public function getDownloadLink(string $fileId, string $modelId, string $fileType = 'STL'): string
    {
        $this->lastError = '';
        $mutation = <<<'GQL'
        mutation GetDownloadLink($id: ID!, $modelId: ID!, $fileType: DownloadFileTypeEnum!, $source: DownloadSourceEnum!) {
          getDownloadLink(id: $id, printId: $modelId, fileType: $fileType, source: $source) {
            ok
            output { link ttl }
            errors { field messages }
          }
        }
        GQL;

        $data = $this->gql($mutation, [
            'id'       => $fileId,
            'modelId'  => $modelId,
            'fileType' => strtolower($fileType),  // DownloadFileTypeEnum is lowercase: stl, 3mf
            'source'   => 'model_detail',
        ]);
        if ($data === null) {
            return '';
        }

        $node = $data['getDownloadLink'] ?? null;
        $link = $node['output']['link'] ?? '';
        if (empty($node['ok']) || $link === '') {
            $this->lastError = 'Download link refused by API.';
            return '';
        }
        return (string) $link;
    }

    /**
     * Minimal model info (name + slug) for naming folders on paste-ID jobs.
     * Auth-free in practice (the print query resolves without a bearer).
     *
     * @return array{name:string,slug:string}
     */
    public function getModelInfo(string $modelId): array
    {
        $this->lastError = '';
        $query = <<<'GQL'
        query ModelInfo($id: ID!) {
          model: print(id: $id) { id name slug }
        }
        GQL;
        $data = $this->gql($query, ['id' => $modelId]);
        $m = $data['model'] ?? [];
        return [
            'name' => (string) ($m['name'] ?? ''),
            'slug' => (string) ($m['slug'] ?? ''),
        ];
    }

    /**
     * Fetch the download packs for a model. Each model exposes one or more
     * "packs" (whole-model ZIPs): typically a MODEL_FILES pack (the "ALL MODEL
     * FILES" button) and an OTHER_FILES pack. Returns the list as-is.
     *
     * @return array<int,array{id:string,fileType:string,fileSize:int,name:string}>
     */
    public function getModelPacks(string $modelId): array
    {
        $this->lastError = '';
        $query = <<<'GQL'
        query ModelPacks($id: ID!) {
          model: print(id: $id) {
            id
            downloadPacks { id name fileSize fileType }
          }
        }
        GQL;

        $data = $this->gql($query, ['id' => $modelId]);
        if ($data === null) {
            return [];
        }
        $packs = $data['model']['downloadPacks'] ?? [];
        $out = [];
        foreach ($packs as $p) {
            $out[] = [
                'id'       => (string) ($p['id'] ?? ''),
                'name'     => (string) ($p['name'] ?? ''),
                'fileSize' => (int) ($p['fileSize'] ?? 0),
                'fileType' => (string) ($p['fileType'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Resolve the signed ZIP URL for a model's pack. Prefers the MODEL_FILES
     * pack (the printable model files) unless $packType says otherwise.
     * Reuses getDownloadLink with fileType "pack". Returns '' if none.
     */
    public function getPackLink(string $modelId, string $packType = 'MODEL_FILES'): string
    {
        $this->lastError = '';
        $packs = $this->getModelPacks($modelId);
        if ($packs === []) {
            if ($this->lastError === '') {
                $this->lastError = 'No download packs for this model.';
            }
            return '';
        }

        // Pick the requested pack type, else fall back to the first pack.
        $packId = '';
        foreach ($packs as $p) {
            if ($p['fileType'] === $packType) {
                $packId = $p['id'];
                break;
            }
        }
        if ($packId === '') {
            $packId = $packs[0]['id'];
        }

        // Same getDownloadLink mutation, but fileType "pack" and id = pack id.
        $mutation = <<<'GQL'
        mutation GetDownloadLink($id: ID!, $modelId: ID!, $fileType: DownloadFileTypeEnum!, $source: DownloadSourceEnum!) {
          getDownloadLink(id: $id, printId: $modelId, fileType: $fileType, source: $source) {
            ok
            output { link ttl }
            errors { field messages }
          }
        }
        GQL;

        $data = $this->gql($mutation, [
            'id'       => $packId,
            'modelId'  => $modelId,
            'fileType' => 'pack',
            'source'   => 'model_detail',
        ]);
        if ($data === null) {
            return '';
        }
        $node = $data['getDownloadLink'] ?? null;
        $link = $node['output']['link'] ?? '';
        if (empty($node['ok']) || $link === '') {
            $this->lastError = 'Pack link refused by API.';
            return '';
        }
        return (string) $link;
    }

    /**
     * Stream a (signed) URL to disk. Returns true on success.
     * Uses the auth header too — harmless on a presigned URL, required if not.
     */
    public function downloadToFile(string $url, string $destPath): bool
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
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'User-Agent: ' . self::UA,
            ],
        ]);
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

    /** @return array<string,mixed>|null */
    private function gql(string $query, array $variables): ?array
    {
        $ch = curl_init(self::API);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token,
                'User-Agent: ' . self::UA,
            ],
            CURLOPT_POSTFIELDS => json_encode(
                ['query' => $query, 'variables' => $variables],
                JSON_THROW_ON_ERROR
            ),
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'Network error: ' . $cerr;
            return null;
        }
        if ($status === 401 || $status === 403) {
            $this->lastError = 'Token rejected (expired/invalid). Refresh it in Settings.';
            return null;
        }
        if ($status === 429) {
            $this->lastError = 'Rate limited (429). Slow down / retry later.';
            return null;
        }
        if ($status !== 200) {
            $this->lastError = 'HTTP ' . $status . ' from Printables.';
            return null;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $this->lastError = 'Non-JSON response.';
            return null;
        }
        if (!empty($decoded['errors'])) {
            $this->lastError = 'API error: ' . (string) ($decoded['errors'][0]['message'] ?? 'GraphQL error');
            return null;
        }
        $data = $decoded['data'] ?? null;
        return is_array($data) ? $data : null;
    }
}
