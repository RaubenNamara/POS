<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Collision-free document numbers. Must be called inside the caller's transaction. */
final class Sequence
{
    public static function next(string $name, string $prefix, int $pad = 6): string
    {
        DB::statement('INSERT INTO sequences (name, value) VALUES (?, 0) ON DUPLICATE KEY UPDATE name = name', [$name]);
        $row = DB::table('sequences')->where('name', $name)->lockForUpdate()->first();
        $value = $row->value + 1;
        DB::table('sequences')->where('name', $name)->update(['value' => $value]);

        return $prefix.str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
    }
}
