<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\DonorController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\DonationItemController;
use App\Http\Controllers\Api\PosTransactionController;
use App\Http\Controllers\Api\DebtController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\ReportController;

// Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Backend Donasi API is healthy',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Auth Public
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/login', [AuthController::class, 'login']);

// Dokumen Cetak / PDF Resmi (Dapat dibuka via tab baru browser dengan token parameter)
Route::get('/reports/pdf/{type}', [ReportController::class, 'exportPdf']);

// Clear Cache Endpoint untuk hosting cPanel
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

// Authenticated Routes (Dilindungi Autentikasi Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    // Profil & Logout
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Categories (Pos Program)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::post('/categories/bulk-delete', [CategoryController::class, 'bulkDestroy']);
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->whereNumber('id');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->whereNumber('id');

    // Accounts (Kas & Bank)
    Route::get('/accounts', [AccountController::class, 'index']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::post('/accounts/recalculate', [AccountController::class, 'recalculate']);
    Route::post('/accounts/bulk-delete', [AccountController::class, 'bulkDestroy']);
    Route::post('/accounts/{id}/reset-balance', [AccountController::class, 'resetBalance'])->whereNumber('id');
    Route::put('/accounts/{id}', [AccountController::class, 'update'])->whereNumber('id');
    Route::delete('/accounts/{id}', [AccountController::class, 'destroy'])->whereNumber('id');

    // Donors
    Route::get('/donors', [DonorController::class, 'index']);
    Route::post('/donors', [DonorController::class, 'store']);
    Route::post('/donors/bulk-delete', [DonorController::class, 'bulkDestroy']);
    Route::put('/donors/{id}', [DonorController::class, 'update'])->whereNumber('id');
    Route::delete('/donors/{id}', [DonorController::class, 'destroy'])->whereNumber('id');

    // Users
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/bulk-delete', [UserController::class, 'bulkDestroy']);
    Route::put('/users/{id}', [UserController::class, 'update'])->whereNumber('id');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');

    // Transactions
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::post('/transactions/bulk-delete', [TransactionController::class, 'bulkDestroy']);
    Route::post('/transactions/import-qris', [TransactionController::class, 'importQris']);
    Route::post('/transactions/reallocate-qris', [TransactionController::class, 'reallocateQris']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show'])->whereNumber('id');
    Route::put('/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy'])->whereNumber('id');

    // Donation Items (Natura / Barang)
    Route::get('/donation-items', [DonationItemController::class, 'index']);
    Route::post('/donation-items', [DonationItemController::class, 'store']);
    Route::post('/donation-items/bulk-delete', [DonationItemController::class, 'bulkDestroy']);
    Route::delete('/donation-items/{id}', [DonationItemController::class, 'destroy'])->whereNumber('id');

    // Pos Transactions (Kas Internal Lapangan)
    Route::get('/pos-transactions', [PosTransactionController::class, 'index']);
    Route::post('/pos-transactions', [PosTransactionController::class, 'store']);
    Route::post('/pos-transactions/bulk-delete', [PosTransactionController::class, 'bulkDestroy']);
    Route::delete('/pos-transactions/{id}', [PosTransactionController::class, 'destroy'])->whereNumber('id');

    // Debts (Hutang & Piutang)
    Route::get('/debts', [DebtController::class, 'index']);
    Route::get('/debts/recap', [DebtController::class, 'recap']);
    Route::post('/debts', [DebtController::class, 'store']);
    Route::post('/debts/bulk-delete', [DebtController::class, 'bulkDestroy']);
    Route::delete('/debts/{id}', [DebtController::class, 'destroy'])->whereNumber('id');

    // Materials
    Route::get('/materials', [MaterialController::class, 'index']);
    Route::post('/materials', [MaterialController::class, 'store']);
    Route::post('/materials/bulk-delete', [MaterialController::class, 'bulkDestroy']);
    Route::get('/materials/transactions', [MaterialController::class, 'transactions']);
    Route::post('/materials/transactions', [MaterialController::class, 'storeTransaction']);
    Route::post('/materials/transactions/bulk-delete', [MaterialController::class, 'bulkDestroyTransactions']);
    Route::delete('/materials/transactions/{id}', [MaterialController::class, 'destroyTransaction'])->whereNumber('id');
    Route::put('/materials/{id}', [MaterialController::class, 'update'])->whereNumber('id');
    Route::delete('/materials/{id}', [MaterialController::class, 'destroy'])->whereNumber('id');

    // Reports & Dashboard
    Route::get('/dashboard/stats', [ReportController::class, 'stats']);
    Route::get('/reports/buku-bantu', [ReportController::class, 'bukuBantu']);
    Route::get('/reports/neraca', [ReportController::class, 'neraca']);
    Route::post('/reports/neraca/items', [ReportController::class, 'storeNeracaItem']);
    Route::put('/reports/neraca/items/{itemId}', [ReportController::class, 'updateNeracaItem'])->whereNumber('itemId');
    Route::delete('/reports/neraca/items/{itemId}', [ReportController::class, 'destroyNeracaItem'])->whereNumber('itemId');
    Route::get('/reports/neraca-pos/{categoryId}', [ReportController::class, 'neracaPos'])->whereNumber('categoryId');
    Route::post('/reports/neraca-pos/{categoryId}/items', [ReportController::class, 'storeNeracaPosItem'])->whereNumber('categoryId');
    Route::put('/reports/neraca-pos/items/{itemId}', [ReportController::class, 'updateNeracaPosItem'])->whereNumber('itemId');
    Route::delete('/reports/neraca-pos/items/{itemId}', [ReportController::class, 'destroyNeracaPosItem'])->whereNumber('itemId');
});
