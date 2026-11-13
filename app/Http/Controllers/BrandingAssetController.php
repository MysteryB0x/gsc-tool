<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

/** Serves uploaded logos and the favicon from storage/app/private/branding (no storage:link needed). */
class BrandingAssetController
{
    public function __invoke(string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+$/', $file) === 1, 404);
        $path = 'branding/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // An uploaded SVG opened directly can't run scripts.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
        ]);
    }
}
