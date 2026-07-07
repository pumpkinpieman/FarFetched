<?php
declare(strict_types=1);

/**
 * FilamentService.php — data layer for the filament inventory.
 *
 * Design:
 *   - Two tables (filament_type, filament_spool); spools reference a type.
 *   - "Custom-allowed": callers pass free-form type fields; upsert_type() finds
 *     or creates a matching type so a spool can be logged in one step.
 *   - ALL writes go through db_exec_retry() (WAL-safe, serialized).
 *   - remaining_g is mutated ONLY by a single clamped atomic UPDATE — never
 *     read-modify-write — so future OctoPrint consumption hooks can't race.
 *   - Every statement is parameterized (no string interpolation into SQL).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/filament_catalog.php';

final class FilamentService
{
    /* ------------------------------------------------------------ validation */

    public static function normHex(string $hex, string $fallback = '#cccccc'): string
    {
        $hex = trim($hex);
        if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex)) {
            return '#' . strtolower(ltrim($hex, '#'));
        }
        return $fallback;
    }

    private static function clampNum($v, float $min, float $max, float $default): float
    {
        if (!is_numeric($v)) {
            return $default;
        }
        return max($min, min($max, (float) $v));
    }

    private static function normStatus(string $s): string
    {
        $s = strtolower(trim($s));
        return in_array($s, filament_statuses(), true) ? $s : 'active';
    }

    /* ------------------------------------------------------------------ types */

    /** Build a validated column set for filament_type from raw input. */
    public static function sanitizeType(array $in): array
    {
        $mats = filament_materials();
        $material = trim((string) ($in['material'] ?? 'PLA'));
        if ($material === '') {
            $material = 'PLA';
        }
        $densityDefault = $mats[$material]['density'] ?? 1.24;

        return [
            'brand'       => mb_substr(trim((string) ($in['brand'] ?? '')), 0, 80),
            'material'    => mb_substr($material, 0, 40),
            'color_name'  => mb_substr(trim((string) ($in['color_name'] ?? '')), 0, 60),
            'color_hex'   => self::normHex((string) ($in['color_hex'] ?? '#cccccc')),
            'diameter_mm' => self::clampNum($in['diameter_mm'] ?? 1.75, 0.5, 5.0, 1.75),
            'density'     => self::clampNum($in['density'] ?? $densityDefault, 0.5, 5.0, $densityDefault),
            'temp_nozzle' => (int) self::clampNum($in['temp_nozzle'] ?? 0, 0, 500, 0),
            'temp_bed'    => (int) self::clampNum($in['temp_bed'] ?? 0, 0, 200, 0),
            'cost'        => self::clampNum($in['cost'] ?? 0, 0, 100000, 0),
            'notes'       => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000),
        ];
    }

    /**
     * Find a non-deleted type matching brand+material+color_hex, or create one.
     * Enables one-step spool logging without pre-creating a type.
     */
    public static function upsertType(array $in): int
    {
        filament_init();
        $t = self::sanitizeType($in);

        $sel = db()->prepare(
            'SELECT id FROM filament_type
              WHERE deleted = 0 AND brand = :b AND material = :m AND color_hex = :c
              LIMIT 1'
        );
        $sel->execute([':b' => $t['brand'], ':m' => $t['material'], ':c' => $t['color_hex']]);
        $existing = $sel->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }
        return self::createType($in);
    }

    public static function createType(array $in): int
    {
        filament_init();
        $t = self::sanitizeType($in);
        db_exec_retry(
            'INSERT INTO filament_type
                (brand, material, color_name, color_hex, diameter_mm, density,
                 temp_nozzle, temp_bed, cost, notes)
             VALUES (:brand, :material, :color_name, :color_hex, :diameter_mm, :density,
                 :temp_nozzle, :temp_bed, :cost, :notes)',
            [
                ':brand' => $t['brand'], ':material' => $t['material'],
                ':color_name' => $t['color_name'], ':color_hex' => $t['color_hex'],
                ':diameter_mm' => $t['diameter_mm'], ':density' => $t['density'],
                ':temp_nozzle' => $t['temp_nozzle'], ':temp_bed' => $t['temp_bed'],
                ':cost' => $t['cost'], ':notes' => $t['notes'],
            ]
        );
        return (int) db()->lastInsertId();
    }

    public static function updateType(int $id, array $in): bool
    {
        filament_init();
        if ($id <= 0) {
            return false;
        }
        $t = self::sanitizeType($in);
        db_exec_retry(
            'UPDATE filament_type SET
                brand = :brand, material = :material, color_name = :color_name,
                color_hex = :color_hex, diameter_mm = :diameter_mm, density = :density,
                temp_nozzle = :temp_nozzle, temp_bed = :temp_bed, cost = :cost,
                notes = :notes, updated_at = datetime(\'now\')
              WHERE id = :id AND deleted = 0',
            [
                ':brand' => $t['brand'], ':material' => $t['material'],
                ':color_name' => $t['color_name'], ':color_hex' => $t['color_hex'],
                ':diameter_mm' => $t['diameter_mm'], ':density' => $t['density'],
                ':temp_nozzle' => $t['temp_nozzle'], ':temp_bed' => $t['temp_bed'],
                ':cost' => $t['cost'], ':notes' => $t['notes'], ':id' => $id,
            ]
        );
        return true;
    }

    /** Soft-delete a type (kept for spool history); also archives its spools. */
    public static function deleteType(int $id): bool
    {
        filament_init();
        if ($id <= 0) {
            return false;
        }
        db_exec_retry('UPDATE filament_type SET deleted = 1, updated_at = datetime(\'now\') WHERE id = :id', [':id' => $id]);
        db_exec_retry('UPDATE filament_spool SET status = \'archived\', updated_at = datetime(\'now\') WHERE type_id = :id', [':id' => $id]);
        return true;
    }

    public static function getType(int $id): ?array
    {
        filament_init();
        $s = db()->prepare('SELECT * FROM filament_type WHERE id = :id AND deleted = 0');
        $s->execute([':id' => $id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /* ----------------------------------------------------------------- spools */

    public static function createSpool(int $typeId, array $in): int
    {
        filament_init();
        $total     = self::clampNum($in['total_g'] ?? 1000, 0, 100000, 1000);
        $remaining = self::clampNum($in['remaining_g'] ?? $total, 0, $total, $total);
        db_exec_retry(
            'INSERT INTO filament_spool
                (type_id, total_g, remaining_g, spool_g, purchase_date, location, status, notes)
             VALUES (:t, :total, :rem, :spool, :pd, :loc, :st, :notes)',
            [
                ':t' => $typeId, ':total' => $total, ':rem' => $remaining,
                ':spool' => self::clampNum($in['spool_g'] ?? 0, 0, 10000, 0),
                ':pd' => mb_substr(trim((string) ($in['purchase_date'] ?? '')), 0, 20),
                ':loc' => mb_substr(trim((string) ($in['location'] ?? '')), 0, 120),
                ':st' => self::normStatus((string) ($in['status'] ?? 'active')),
                ':notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000),
            ]
        );
        return (int) db()->lastInsertId();
    }

    public static function updateSpool(int $id, array $in): bool
    {
        filament_init();
        if ($id <= 0) {
            return false;
        }
        $total     = self::clampNum($in['total_g'] ?? 1000, 0, 100000, 1000);
        $remaining = self::clampNum($in['remaining_g'] ?? $total, 0, $total, $total);
        db_exec_retry(
            'UPDATE filament_spool SET
                total_g = :total, remaining_g = :rem, spool_g = :spool,
                purchase_date = :pd, location = :loc, status = :st, notes = :notes,
                updated_at = datetime(\'now\')
              WHERE id = :id',
            [
                ':total' => $total, ':rem' => $remaining,
                ':spool' => self::clampNum($in['spool_g'] ?? 0, 0, 10000, 0),
                ':pd' => mb_substr(trim((string) ($in['purchase_date'] ?? '')), 0, 20),
                ':loc' => mb_substr(trim((string) ($in['location'] ?? '')), 0, 120),
                ':st' => self::normStatus((string) ($in['status'] ?? 'active')),
                ':notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000),
                ':id' => $id,
            ]
        );
        return true;
    }

    /**
     * Atomically adjust remaining grams by a signed delta, clamped to
     * [0, total_g]. Single UPDATE — safe under concurrent writers. Returns the
     * new remaining value (or null if the spool is gone).
     */
    public static function adjustRemaining(int $id, float $delta): ?float
    {
        filament_init();
        if ($id <= 0) {
            return null;
        }
        db_exec_retry(
            'UPDATE filament_spool
                SET remaining_g = MAX(0, MIN(total_g, remaining_g + :d)),
                    updated_at = datetime(\'now\')
              WHERE id = :id',
            [':d' => $delta, ':id' => $id]
        );
        $s = db()->prepare('SELECT remaining_g FROM filament_spool WHERE id = :id');
        $s->execute([':id' => $id]);
        $v = $s->fetchColumn();
        return $v === false ? null : (float) $v;
    }

    public static function deleteSpool(int $id): bool
    {
        filament_init();
        if ($id <= 0) {
            return false;
        }
        db_exec_retry('DELETE FROM filament_spool WHERE id = :id', [':id' => $id]);
        return true;
    }

    /* ------------------------------------------------------------------ reads */

    /**
     * Inventory grouped by type: each type with its spools + aggregate totals.
     * One query with a LEFT JOIN, assembled in PHP (no N+1).
     */
    public static function inventory(): array
    {
        filament_init();
        $rows = db()->query(
            'SELECT t.*,
                    s.id AS s_id, s.total_g, s.remaining_g, s.spool_g,
                    s.purchase_date, s.location, s.status, s.notes AS s_notes
               FROM filament_type t
               LEFT JOIN filament_spool s ON s.type_id = t.id
              WHERE t.deleted = 0
              ORDER BY t.brand, t.material, t.color_name, t.id, s.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $types = [];
        foreach ($rows as $r) {
            $tid = (int) $r['id'];
            if (!isset($types[$tid])) {
                $types[$tid] = [
                    'id' => $tid, 'brand' => $r['brand'], 'material' => $r['material'],
                    'color_name' => $r['color_name'], 'color_hex' => $r['color_hex'],
                    'diameter_mm' => (float) $r['diameter_mm'], 'density' => (float) $r['density'],
                    'temp_nozzle' => (int) $r['temp_nozzle'], 'temp_bed' => (int) $r['temp_bed'],
                    'cost' => (float) $r['cost'],
                    'notes' => $r['notes'],
                    'spools' => [], 'total_remaining' => 0.0, 'spool_count' => 0,
                ];
            }
            if ($r['s_id'] !== null) {
                $types[$tid]['spools'][] = [
                    'id' => (int) $r['s_id'], 'total_g' => (float) $r['total_g'],
                    'remaining_g' => (float) $r['remaining_g'], 'spool_g' => (float) $r['spool_g'],
                    'purchase_date' => $r['purchase_date'], 'location' => $r['location'],
                    'status' => $r['status'], 'notes' => $r['s_notes'],
                ];
                if ($r['status'] !== 'archived' && $r['status'] !== 'empty') {
                    $types[$tid]['total_remaining'] += (float) $r['remaining_g'];
                }
                $types[$tid]['spool_count']++;
            }
        }
        return array_values($types);
    }

    /** Headline stats for the inventory page. */
    public static function stats(): array
    {
        filament_init();
        $row = db()->query(
            'SELECT
                (SELECT COUNT(*) FROM filament_type WHERE deleted = 0) AS types,
                (SELECT COUNT(*) FROM filament_spool s JOIN filament_type t ON t.id = s.type_id
                   WHERE t.deleted = 0 AND s.status NOT IN (\'archived\',\'empty\')) AS spools,
                (SELECT COALESCE(SUM(s.remaining_g),0) FROM filament_spool s JOIN filament_type t ON t.id = s.type_id
                   WHERE t.deleted = 0 AND s.status NOT IN (\'archived\',\'empty\')) AS grams'
        )->fetch(PDO::FETCH_ASSOC);
        return [
            'types'  => (int) ($row['types'] ?? 0),
            'spools' => (int) ($row['spools'] ?? 0),
            'kg'     => round(((float) ($row['grams'] ?? 0)) / 1000, 2),
        ];
    }
}
