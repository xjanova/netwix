<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Keep the newly-added animeruka source HIDDEN on import (owner: "ซ่อนไว้ก่อน", 2026-07-16). Merges
 * "animeruka" into the existing `hidden_sources` setting (which already holds "9nung") so ImportService
 * force-unpublishes its titles until the owner clears it. Idempotent + preserves any other hidden
 * sources. Reversible: remove "animeruka" from hidden_sources on /admin (or run down()).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sources = array_filter(array_map('trim', explode(',', (string) Setting::get('hidden_sources', ''))));
        if (! in_array('animeruka', $sources, true)) {
            $sources[] = 'animeruka';
            Setting::write('hidden_sources', implode(',', $sources));
        }
    }

    public function down(): void
    {
        $sources = array_filter(
            array_map('trim', explode(',', (string) Setting::get('hidden_sources', ''))),
            fn (string $s) => $s !== 'animeruka',
        );
        Setting::write('hidden_sources', implode(',', $sources));
    }
};
