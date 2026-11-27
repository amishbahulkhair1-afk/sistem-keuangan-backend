<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Otomatis hapus stale route cache di shared hosting jika berkas rute telah diperbarui
if (file_exists($cachedRoutes = dirname(__DIR__).'/bootstrap/cache/routes-v7.php')) {
    $apiRoutesTime = @filemtime(dirname(__DIR__).'/routes/api.php') ?: 0;
    $webRoutesTime = @filemtime(dirname(__DIR__).'/routes/web.php') ?: 0;
    $cachedTime = @filemtime($cachedRoutes) ?: 0;
    if ($apiRoutesTime > $cachedTime || $webRoutesTime > $cachedTime || isset($_GET['clear_cache'])) {
        @unlink($cachedRoutes);
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
