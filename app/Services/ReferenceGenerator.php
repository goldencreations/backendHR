<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates the human-readable references the UI already displays, such as
 * LV-2024-041 and DOC-001.
 *
 * Uniqueness is enforced by the database index; this reads the current
 * maximum and increments, which is adequate for the volumes involved here
 * and avoids a per-request sequence table.
 */
class ReferenceGenerator
{
    public function next(string $table, string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $like = "{$prefix}-{$year}-%";

        $max = DB::table($table)
            ->where('reference', 'like', $like)
            ->orderByDesc('reference')
            ->value('reference');

        $sequence = $max ? ((int) substr((string) $max, -3)) + 1 : 1;

        // Guard against a collision if two requests race.
        while (DB::table($table)->where('reference', sprintf('%s-%d-%03d', $prefix, $year, $sequence))->exists()) {
            $sequence++;
        }

        return sprintf('%s-%d-%03d', $prefix, $year, $sequence);
    }

    /**
     * Short sequential reference without a year, matching the DOC-001 style
     * used by the document hub.
     */
    public function nextShort(string $table, string $prefix): string
    {
        $like = "{$prefix}-%";

        $max = DB::table($table)
            ->where('reference', 'like', $like)
            ->orderByDesc('reference')
            ->value('reference');

        $sequence = $max ? ((int) substr((string) $max, -4)) + 1 : 1;

        while (DB::table($table)->where('reference', sprintf('%s-%04d', $prefix, $sequence))->exists()) {
            $sequence++;
        }

        return sprintf('%s-%04d', $prefix, $sequence);
    }

    /**
     * Employee codes are formatted like GH-0248.
     */
    public function employeeCode(string $table = 'employees'): string
    {
        $max = DB::table($table)
            ->where('employee_code', 'like', 'GH-%')
            ->orderByDesc('employee_code')
            ->value('employee_code');

        $sequence = $max ? ((int) substr((string) $max, 3)) + 1 : 1;

        while (DB::table($table)->where('employee_code', sprintf('GH-%04d', $sequence))->exists()) {
            $sequence++;
        }

        return sprintf('GH-%04d', $sequence);
    }
}
