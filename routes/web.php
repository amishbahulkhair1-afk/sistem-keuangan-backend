<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ReportController;

Route::get('/', function () {
    return view('welcome');
});

// Cadangan rute cetak dokumen via Web & API
Route::get('/reports/pdf/{type}', [ReportController::class, 'exportPdf']);
Route::get('/api/reports/pdf/{type}', [ReportController::class, 'exportPdf']);

// Endpoint pembersih cache untuk shared hosting / cPanel tanpa akses terminal SSH
Route::get('/clear-cache', function () {
    $routesCache = base_path('bootstrap/cache/routes-v7.php');
    $configCache = base_path('bootstrap/cache/config.php');
    if (file_exists($routesCache)) @unlink($routesCache);
    if (file_exists($configCache)) @unlink($configCache);
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    return response()->json([
        'status' => 'success',
        'message' => 'Cache dan route Laravel berhasil dibersihkan!',
        'artisan_output' => trim(\Illuminate\Support\Facades\Artisan::output()),
    ]);
});

Route::get('/api/clear-cache', function () {
    $routesCache = base_path('bootstrap/cache/routes-v7.php');
    $configCache = base_path('bootstrap/cache/config.php');
    if (file_exists($routesCache)) @unlink($routesCache);
    if (file_exists($configCache)) @unlink($configCache);
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    return response()->json([
        'status' => 'success',
        'message' => 'Cache dan route Laravel berhasil dibersihkan!',
        'artisan_output' => trim(\Illuminate\Support\Facades\Artisan::output()),
    ]);
});
