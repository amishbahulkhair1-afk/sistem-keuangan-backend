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

// Authenticated Routes (Dilindungi Autentikasi Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    // Profil & Logout
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Categories (Pos Program)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
    Route::post('/categories/bulk-delete', [CategoryController::class, 'bulkDestroy']);

    // Accounts (Kas & Bank)
    Route::get('/accounts', [AccountController::class, 'index']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::post('/accounts/recalculate', [AccountController::class, 'recalculate']);
    Route::post('/accounts/{id}/reset-balance', [AccountController::class, 'resetBalance']);
    Route::put('/accounts/{id}', [AccountController::class, 'update']);
    Route::delete('/accounts/{id}', [AccountController::class, 'destroy']);
    Route::post('/accounts/bulk-delete', [AccountController::class, 'bulkDestroy']);

    // Donors
    Route::get('/donors', [DonorController::class, 'index']);
    Route::post('/donors', [DonorController::class, 'store']);
    Route::put('/donors/{id}', [DonorController::class, 'update']);
    Route::delete('/donors/{id}', [DonorController::class, 'destroy']);
    Route::post('/donors/bulk-delete', [DonorController::class, 'bulkDestroy']);

    // Users
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/bulk-delete', [UserController::class, 'bulkDestroy']);

    // Transactions
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    Route::put('/transactions/{id}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);
    Route::post('/transactions/bulk-delete', [TransactionController::class, 'bulkDestroy']);
    Route::post('/transactions/import-qris', [TransactionController::class, 'importQris']);
    Route::post('/transactions/reallocate-qris', [TransactionController::class, 'reallocateQris']);

    // Donation Items (Natura / Barang)
    Route::get('/donation-items', [DonationItemController::class, 'index']);
    Route::post('/donation-items', [DonationItemController::class, 'store']);
    Route::delete('/donation-items/{id}', [DonationItemController::class, 'destroy']);
    Route::post('/donation-items/bulk-delete', [DonationItemController::class, 'bulkDestroy']);

    // Pos Transactions (Kas Internal Lapangan)
    Route::get('/pos-transactions', [PosTransactionController::class, 'index']);
    Route::post('/pos-transactions', [PosTransactionController::class, 'store']);
    Route::delete('/pos-transactions/{id}', [PosTransactionController::class, 'destroy']);
    Route::post('/pos-transactions/bulk-delete', [PosTransactionController::class, 'bulkDestroy']);

    // Debts (Hutang & Piutang)
    Route::get('/debts', [DebtController::class, 'index']);
    Route::get('/debts/recap', [DebtController::class, 'recap']);
    Route::post('/debts', [DebtController::class, 'store']);
    Route::delete('/debts/{id}', [DebtController::class, 'destroy']);
    Route::post('/debts/bulk-delete', [DebtController::class, 'bulkDestroy']);

    // Materials
    Route::get('/materials', [MaterialController::class, 'index']);
    Route::post('/materials', [MaterialController::class, 'store']);
    Route::put('/materials/{id}', [MaterialController::class, 'update']);
    Route::delete('/materials/{id}', [MaterialController::class, 'destroy']);
    Route::post('/materials/bulk-delete', [MaterialController::class, 'bulkDestroy']);
    Route::get('/materials/transactions', [MaterialController::class, 'transactions']);
    Route::post('/materials/transactions', [MaterialController::class, 'storeTransaction']);
    Route::delete('/materials/transactions/{id}', [MaterialController::class, 'destroyTransaction']);
    Route::post('/materials/transactions/bulk-delete', [MaterialController::class, 'bulkDestroyTransactions']);

    // Reports & Dashboard
    Route::get('/dashboard/stats', [ReportController::class, 'stats']);
    Route::get('/reports/buku-bantu', [ReportController::class, 'bukuBantu']);
    Route::get('/reports/neraca', [ReportController::class, 'neraca']);
    Route::post('/reports/neraca/items', [ReportController::class, 'storeNeracaItem']);
    Route::put('/reports/neraca/items/{itemId}', [ReportController::class, 'updateNeracaItem']);
    Route::delete('/reports/neraca/items/{itemId}', [ReportController::class, 'destroyNeracaItem']);
    Route::get('/reports/neraca-pos/{categoryId}', [ReportController::class, 'neracaPos']);
    Route::post('/reports/neraca-pos/{categoryId}/items', [ReportController::class, 'storeNeracaPosItem']);
    Route::put('/reports/neraca-pos/items/{itemId}', [ReportController::class, 'updateNeracaPosItem']);
    Route::delete('/reports/neraca-pos/items/{itemId}', [ReportController::class, 'destroyNeracaPosItem']);
});
