<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::view('/welcome', 'welcome');

Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

Route::get('/{any?}', function () {
    $spa = public_path('index.html');

    $noCacheHeaders = [
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
    ];

    if (File::exists($spa) && File::exists(public_path('index.php'))) {
        return response()->file($spa, $noCacheHeaders);
    }

    return response()
        ->view('spa')
        ->withHeaders($noCacheHeaders);
})->where('any', '^(?!api|sanctum|up|auth).*$');
