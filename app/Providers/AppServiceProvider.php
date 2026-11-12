<?php

namespace App\Providers;

use App\Google\Contracts\CredentialProvider;
use App\Google\ServiceAccountProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The app's own Google identity: the service account. Per-property OAuth goes through App\Google\Credentials.
        $this->app->bind(CredentialProvider::class, ServiceAccountProvider::class);
        $this->app->bind(ServiceAccountProvider::class, fn () => new ServiceAccountProvider);
    }

    public function boot(): void
    {
        //
    }
}
