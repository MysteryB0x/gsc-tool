<?php

use Illuminate\Support\Facades\Route;

// No public website yet: the tool lives at /app (and /admin for the owner).
Route::redirect('/', '/app');
