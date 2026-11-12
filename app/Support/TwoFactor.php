<?php

namespace App\Support;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Panel;

/**
 * Two-factor login, set in .env:
 *   TWO_FACTOR=off|optional|required        the tool (/app, default optional)
 *   TWO_FACTOR_ADMIN=off|optional|required  admin panel (default required)
 *   TWO_FACTOR_METHODS=app,email            authenticator app and/or a code by email (/app)
 *   TWO_FACTOR_ADMIN_METHODS=app            admin panel (authenticator app only, so a mailbox alone isn't admin access)
 * An unknown value fails closed for the admin panel (required) and stays optional for /app.
 */
class TwoFactor
{
    public const MODES = ['off', 'optional', 'required'];

    public static function mode(string $panel = 'app'): string
    {
        $mode = strtolower((string) config($panel === 'admin' ? 'gsctool.two_factor.admin' : 'gsctool.two_factor.mode'));

        return in_array($mode, self::MODES, true) ? $mode : ($panel === 'admin' ? 'required' : 'optional');
    }

    /** @return list<string> */
    public static function methods(string $panel = 'app'): array
    {
        $setting = $panel === 'admin' ? 'gsctool.two_factor.admin_methods' : 'gsctool.two_factor.methods';
        $methods = array_values(array_intersect(['app', 'email'], array_map('trim', explode(',', strtolower((string) config($setting))))));

        return $methods ?: ($panel === 'admin' ? ['app'] : ['app', 'email']);
    }

    public static function configure(Panel $panel): Panel
    {
        $mode = self::mode($panel->getId());
        if ($mode === 'off') {
            return $panel;
        }

        $providers = [];
        if (in_array('app', self::methods($panel->getId()), true)) {
            $providers[] = AppAuthentication::make()->brandName(config('gsctool.name'))->recoverable();
        }
        if (in_array('email', self::methods($panel->getId()), true)) {
            $providers[] = EmailAuthentication::make();
        }

        return $panel->multiFactorAuthentication($providers, isRequired: $mode === 'required');
    }
}
