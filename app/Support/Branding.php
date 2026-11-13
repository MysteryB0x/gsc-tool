<?php

namespace App\Support;

use App\Models\Setting;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Storage;

/**
 * The look of both panels, set in Admin → Branding and stored in `settings` (keys "branding.*").
 * Anything not set falls back to config/gsctool.php, so the tool looks right before anyone saves.
 * Colours derived from the sidebar colour (text, hover, active) are computed, not stored.
 */
class Branding
{
    public const FONTS = ['Inter', 'IBM Plex Sans', 'DM Sans', 'Manrope', 'Figtree', 'Source Sans 3', 'Work Sans', 'Plus Jakarta Sans'];

    public const HOMES = ['dashboard' => 'Dashboard (KPIs, trend, top opportunities)', 'inbox' => 'Opportunity inbox (one ranked to-do list)'];

    public static function defaults(): array
    {
        return [
            'name' => config('gsctool.name'),
            'primary_color' => config('gsctool.primary_color', '#BA5A00'),
            'background_color' => '#FBF7F2',
            'sidebar_style' => 'dark',
            'sidebar_color' => '#303030',
            'font' => 'Inter',
            'home' => 'dashboard',
            'logo' => null,
            'logo_dark' => null,
            'favicon' => null,
            'logo_height' => '2rem',
        ];
    }

    public static function all(): array
    {
        $stored = collect(Setting::all_())
            ->filter(fn ($v, $k) => str_starts_with($k, 'branding.') && $v !== null && $v !== '')
            ->mapWithKeys(fn ($v, $k) => [substr($k, 9) => $v])
            ->all();

        return array_merge(static::defaults(), array_intersect_key($stored, static::defaults()));
    }

    public static function get(string $key): mixed
    {
        return static::all()[$key] ?? null;
    }

    public static function save(array $values): void
    {
        foreach (array_keys(static::defaults()) as $key) {
            if (array_key_exists($key, $values)) {
                Setting::put('branding.'.$key, $values[$key]);
            }
        }
    }

    public static function name(): string
    {
        return (string) static::get('name');
    }

    public static function home(): string
    {
        return array_key_exists(static::get('home'), static::HOMES) ? static::get('home') : 'dashboard';
    }

    public static function font(): string
    {
        return in_array(static::get('font'), static::FONTS, true) ? static::get('font') : 'Inter';
    }

    public static function colors(): array
    {
        return ['primary' => Color::hex(static::hex('primary_color', '#BA5A00')), 'gray' => Color::Stone];
    }

    /** URL of an uploaded logo/favicon, or null. Files are served by the branding.asset route. */
    public static function assetUrl(string $key): ?string
    {
        $path = static::get($key);

        return $path && Storage::disk('local')->exists($path) ? route('branding.asset', ['file' => basename($path)]).'?v='.substr(md5($path), 0, 8) : null;
    }

    /** The logo for the sidebar: the dark-background version on a dark sidebar, when there is one. */
    public static function logoUrl(): ?string
    {
        $dark = static::get('sidebar_style') === 'dark' && static::luminance(static::hex('sidebar_color', '#303030')) < 0.4;

        return ($dark ? static::assetUrl('logo_dark') : null) ?? static::assetUrl('logo');
    }

    public static function hex(string $key, string $fallback): string
    {
        $v = (string) static::get($key);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $fallback;
    }

    /** The CSS that applies the look; injected into every panel page (no theme build needed). */
    public static function css(): string
    {
        $bg = static::hex('background_color', '#FBF7F2');
        $css = ":root{--gsct-bg:{$bg}}.fi-body,.fi-layout{background-color:var(--gsct-bg)}";

        if (static::get('sidebar_style') !== 'dark') {
            return $css;
        }

        $side = static::hex('sidebar_color', '#303030');
        $lightText = static::luminance($side) < 0.4;
        $text = $lightText ? '#C9CDD4' : '#303030';
        $strong = $lightText ? '#FFFFFF' : '#111111';
        $hover = static::mix($side, $lightText ? '#FFFFFF' : '#000000', 0.08);
        $active = static::mix($side, $lightText ? '#FFFFFF' : '#000000', 0.14);
        $line = static::mix($side, $lightText ? '#FFFFFF' : '#000000', 0.12);
        $s = '.fi-sidebar';

        return $css
            ."{$s},{$s}-header{background-color:{$side};color:{$text};border-color:{$line};box-shadow:none}"
            ."{$s}-header{border-bottom:1px solid {$line}}"
            ."{$s} .fi-logo,{$s}-header .fi-logo{color:{$strong}}"
            ."{$s}-group-label,{$s}-item-label,{$s}-item-icon,{$s}-group-btn,{$s} .fi-tenant-menu-trigger,{$s} .fi-tenant-menu-trigger *{color:{$text}}"
            ."{$s}-group-label{opacity:.75;font-size:.7rem;letter-spacing:.06em;text-transform:uppercase}"
            ."{$s}-item-btn:hover,{$s}-item-btn:focus-visible,{$s} .fi-tenant-menu-trigger:hover{background-color:{$hover}}"
            ."{$s}-item.fi-active>{$s}-item-btn{background-color:{$active}}"
            ."{$s}-item.fi-active {$s}-item-label,{$s}-item.fi-active {$s}-item-icon{color:{$strong}}"
            ."{$s}-item-badge-ctn .fi-badge{background-color:{$active};color:{$strong};box-shadow:none}"
            ."{$s}-footer,{$s}-footer .fi-user-menu-trigger,{$s}-footer .fi-icon-btn{color:{$text};border-color:{$line}}";
    }

    /** WCAG relative luminance, 0 (black) … 1 (white). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function ($c) {
            $c = hexdec($c) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = static::luminance($a);
        $lb = static::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function mix(string $a, string $b, float $t): string
    {
        $pa = array_map('hexdec', str_split(ltrim($a, '#'), 2));
        $pb = array_map('hexdec', str_split(ltrim($b, '#'), 2));

        return '#'.implode('', array_map(fn ($x, $y) => str_pad(dechex((int) round($x + ($y - $x) * $t)), 2, '0', STR_PAD_LEFT), $pa, $pb));
    }
}
