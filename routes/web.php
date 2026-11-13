<?php

use App\Http\Controllers\BrandingAssetController;
use Illuminate\Support\Facades\Route;

// No public website yet: the tool lives at /app (and /admin for the owner).
Route::redirect('/', '/app');

Route::get('/branding/{file}', BrandingAssetController::class)->name('branding.asset');
