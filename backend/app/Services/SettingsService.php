<?php

namespace App\Services;

use App\Models\AssociationSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for reading the (singleton) association settings
 * row. Callers everywhere — controllers, PDF reports, emails — must go
 * through here instead of querying AssociationSetting directly, so the
 * table is never hit more than once per cache lifetime and there is exactly
 * one place that knows how to create the default row.
 */
class SettingsService
{
    private const CACHE_KEY = 'association_settings';

    private const DEFAULTS = [
        'association_name' => 'Association',
        'address' => '',
        'phone' => '',
        'email' => 'contact@example.com',
        'website' => null,
        'annual_subscription_amount' => 0,
        'currency' => 'MAD',
        'description' => null,
    ];

    /**
     * Returns the one AssociationSetting row, creating it with sane
     * defaults on first access. Never returns null.
     *
     * Caches the row's raw attributes (not the Eloquent instance itself) —
     * this project's cache store is the database driver, and round-tripping
     * a serialized Eloquent model through it comes back as
     * __PHP_Incomplete_Class on a cache hit in a fresh process. A plain
     * array serializes/unserializes reliably regardless of cache driver.
     */
    public static function get(): AssociationSetting
    {
        $attributes = Cache::rememberForever(self::CACHE_KEY, function () {
            // Empty conditions array on purpose: there is only ever one row,
            // so "find any row" is the correct singleton lookup — never
            // matched by attribute values.
            return AssociationSetting::firstOrCreate([], self::DEFAULTS)->getAttributes();
        });

        $setting = new AssociationSetting;
        $setting->forceFill($attributes);
        $setting->exists = true;
        $setting->syncOriginal();

        return $setting;
    }

    /**
     * Clears the cached settings so the next get() reflects the latest
     * saved values. Call after every update.
     */
    public static function refresh(): AssociationSetting
    {
        Cache::forget(self::CACHE_KEY);

        return self::get();
    }
}
