<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Donor;
use App\Models\PosTransaction;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use App\Traits\HandlesSecureUploads;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TransactionController extends Controller
{
    use HandlesSecureUploads;
    private function sanitizeName(?string $raw): string
    {
        if (empty($raw)) {
            return "Hamba Allah";
        }
        $cleaned = preg_replace('/[*_]/', ' ', $raw);
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));
        return ucwords(strtolower($cleaned));
    }

    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 500);
        $categoryId = $request->query('category_id');
        $txType = $request->query('tx_type');

        $query = Transaction::with(['category', 'account', 'donor']);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        if ($txType) {
            $query->where('type', $txType);
        }

        $txs = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($txs->map(fn ($t) => [
            'id' => $t->id,
            'tanggal' => $t->transaction_date ? $t->transaction_date->format('Y-m-d') : '-',
            'jenis' => $t->type,
            'category_id' => $t->category_id,
            'pos_program' => $t->category?->name ?? '-',
            'pos_code' => $t->category?->code ?? '-',
            'metode' => $t->account?->name ?? '-',
            'donatur' => $t->donor?->name ?? ($t->donor_name_raw ?: 'Hamba Allah'),
            'nominal' => (float) $t->amount,
            'keterangan' => $t->description,
            'bukti_foto' => $t->receipt_photo_path ? '/uploads/' . basename($t->receipt_photo_path) : null,
            'qris_reff_id' => $t->qris_reff_id,
        ]));
    }

    public function store(Request $request)
    {
        $request->validate([
            'tanggal' => 'required',
            'jenis' => 'required',
            'metode' => 'required',
            'category_id' => 'required|integer',
            'nominal' => 'required|numeric',
        ]);

        $this->validateSecureFile($request, 'bukti_foto');

        $rawJenis = strtolower($request->jenis);
        $txType = in_array($rawJenis, ['income', 'umum_masuk', 'masuk']) ? 'income' : 'expense';

        // Find Account
        $account = null;
        if (is_numeric($request->metode)) {
            $account = Account::find((int) $request->metode);
        }
        if (!$account) {
            $accCode = (stripos($request->metode, 'kas') !== false && stripos($request->metode, 'non') === false)
                ? 'kas_tunai'
                : 'bank_bni';
            $account = Account::where('code', $accCode)->first();
        }
        if (!$account) {
            $account = Account::first();
        }

        // Handle Donor
        $donorId = null;
        if ($txType === 'income' && !empty($request->nama_donatur)) {
            $cleanName = $this->sanitizeName($request->nama_donatur);
            $donor = Donor::firstOrCreate(['name' => $cleanName]);
            $donorId = $donor->id;
        }

        // Handle Photo Upload Secara Aman
        $photoPath = $this->uploadSecureFile($request->file('bukti_foto'), 'bukti');

        $txDate = Carbon::parse($request->tanggal)->toDateString();

        $tx = DB::transaction(function () use ($account, $txType, $txDate, $request, $donorId, $photoPath) {
            $newTx = Transaction::create([
                'transaction_date' => $txDate,
                'type' => $txType,
                'account_id' => $account->id,
                'category_id' => $request->category_id,
                'donor_id' => $donorId,
                'donor_name_raw' => $request->nama_donatur,
                'amount' => (float) $request->nominal,
                'description' => $request->keterangan,
                'receipt_photo_path' => $photoPath,
            ]);

            // Update balance
            if ($txType === 'income') {
                $account->increment('current_balance', $newTx->amount);
            } else {
                $account->decrement('current_balance', $newTx->amount);

                // OTOMATIS: Pengeluaran pusat yang dialokasikan ke pos program
                // menjadi penambah saldo Kas Masuk/Aktiva bagi Pos Program tersebut.
                PosTransaction::create([
                    'category_id' => $request->category_id,
                    'transaction_date' => $txDate,
                    'type' => 'income',
                    'category_tx_type' => 'alokasi_pusat',
                    'source_transaction_id' => $newTx->id,
                    'amount' => $newTx->amount,
                    'recipient_or_sender' => 'Alokasi Pusat Donasi',
                    'description' => 'Turunan alokasi dana dari kas pusat: ' . ($request->keterangan ?: 'Alokasi Kas'),
                    'receipt_photo_path' => $photoPath,
                ]);
            }

            return $newTx;
        });

        return response()->json([
            'id' => $tx->id,
            'message' => 'Transaksi berhasil dicatat',
        ], 201);
    }

    public function show($id)
    {
        $tx = Transaction::with(['category', 'account', 'donor'])->findOrFail($id);
        return response()->json([
            'id' => $tx->id,
            'tanggal' => $tx->transaction_date ? $tx->transaction_date->format('Y-m-d') : '-',
            'jenis' => $tx->type,
            'category_id' => $tx->category_id,
            'nominal' => (float) $tx->amount,
            'keterangan' => $tx->description,
            'pos_program' => $tx->category?->name,
            'metode' => $tx->account?->name,
            'donatur' => $tx->donor?->name ?? $tx->donor_name_raw,
        ]);
    }

    public function update(Request $request, $id)
    {
        $tx = Transaction::findOrFail($id);
        $oldType = $tx->type;
        $oldAmount = $tx->amount;
        $oldAccountId = $tx->account_id;

        // Revert old balance
        $oldAccount = Account::find($oldAccountId);
        if ($oldAccount) {
            if ($oldType === 'income') {
                $oldAccount->decrement('current_balance', $oldAmount);
            } else {
                $oldAccount->increment('current_balance', $oldAmount);
            }
        }

        $rawJenis = strtolower($request->jenis ?? $tx->type);
        $txType = in_array($rawJenis, ['income', 'umum_masuk', 'masuk']) ? 'income' : 'expense';

        $this->validateSecureFile($request, 'bukti_foto');

        $nominal = (float) ($request->nominal ?? $tx->amount);
        $txDate = $request->tanggal ? Carbon::parse($request->tanggal)->toDateString() : $tx->transaction_date;

        $updateData = [
            'transaction_date' => $txDate,
            'type' => $txType,
            'category_id' => $request->category_id ?? $tx->category_id,
            'amount' => $nominal,
            'description' => $request->keterangan ?? $tx->description,
        ];

        // Handle update bukti foto
        if ($request->hasFile('bukti_foto')) {
            $this->deleteSecureFile($tx->receipt_photo_path);
            $updateData['receipt_photo_path'] = $this->uploadSecureFile($request->file('bukti_foto'), 'bukti');
        }

        DB::transaction(function () use ($tx, $request, $oldType, $oldAmount, $oldAccountId, $txType, $nominal, $txDate, $updateData) {
            // Revert old balance
            $oldAccount = Account::find($oldAccountId);
            if ($oldAccount) {
                if ($oldType === 'income') {
                    $oldAccount->decrement('current_balance', $oldAmount);
                } else {
                    $oldAccount->increment('current_balance', $oldAmount);
                }
            }

            $tx->update($updateData);

            // Apply new balance
            $newAccount = Account::find($tx->account_id);
            if ($newAccount) {
                if ($txType === 'income') {
                    $newAccount->increment('current_balance', $nominal);
                } else {
                    $newAccount->decrement('current_balance', $nominal);
                }
            }

            // Sync linked PosTransaction if applicable
            $linkedPos = PosTransaction::where('source_transaction_id', $tx->id)->first();
            if ($txType === 'expense') {
                if ($linkedPos) {
                    $linkedPos->update([
                        'category_id' => $tx->category_id,
                        'transaction_date' => $txDate,
                        'amount' => $nominal,
                        'description' => 'Turunan alokasi dana dari kas pusat: ' . ($request->keterangan ?: 'Alokasi Kas'),
                        'receipt_photo_path' => $tx->receipt_photo_path,
                    ]);
                } else {
                    PosTransaction::create([
                        'category_id' => $tx->category_id,
                        'transaction_date' => $txDate,
                        'type' => 'income',
                        'category_tx_type' => 'alokasi_pusat',
                        'source_transaction_id' => $tx->id,
                        'amount' => $nominal,
                        'recipient_or_sender' => 'Alokasi Pusat Donasi',
                        'description' => 'Turunan alokasi dana dari kas pusat: ' . ($request->keterangan ?: 'Alokasi Kas'),
                        'receipt_photo_path' => $tx->receipt_photo_path,
                    ]);
                }
            } elseif ($linkedPos) {
                $linkedPos->delete();
            }
        });

        return response()->json(['message' => 'Transaksi berhasil diperbarui']);
    }

    public function destroy($id)
    {
        $tx = Transaction::findOrFail($id);

        DB::transaction(function () use ($tx) {
            $account = Account::find($tx->account_id);
            if ($account) {
                if ($tx->type === 'income') {
                    $account->decrement('current_balance', $tx->amount);
                } else {
                    $account->increment('current_balance', $tx->amount);
                }
            }

            // Delete linked pos transaction if any
            PosTransaction::where('source_transaction_id', $tx->id)->delete();

            // Hapus file foto dari disk jika ada
            $this->deleteSecureFile($tx->receipt_photo_path);

            $tx->delete();

            if ($account) {
                $remainingTx = Transaction::where('account_id', $account->id)->count();
                if ($remainingTx === 0) {
                    $account->current_balance = (float) $account->initial_balance;
                    $account->save();
                }
            }
        });

        return response()->json(['message' => 'Transaksi berhasil dihapus']);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ], [
            'ids.required' => 'Daftar ID transaksi tidak boleh kosong',
            'ids.array' => 'Format ID transaksi tidak valid',
        ]);

        $ids = $request->input('ids', []);
        $deletedCount = 0;

        DB::transaction(function () use ($ids, &$deletedCount) {
            $txs = Transaction::whereIn('id', $ids)->get();
            $affectedAccountIds = $txs->pluck('account_id')->unique();

            foreach ($txs as $tx) {
                $account = Account::find($tx->account_id);
                if ($account) {
                    if ($tx->type === 'income') {
                        $account->decrement('current_balance', $tx->amount);
                    } else {
                        $account->increment('current_balance', $tx->amount);
                    }
                }

                // Delete linked pos transaction if any
                PosTransaction::where('source_transaction_id', $tx->id)->delete();

                // Hapus file foto dari disk jika ada
                $this->deleteSecureFile($tx->receipt_photo_path);

                $tx->delete();
                $deletedCount++;
            }

            // Jika semua transaksi pada akun telah dihapus, reset saldo ke saldo awal
            foreach ($affectedAccountIds as $accId) {
                if ($acc = Account::find($accId)) {
                    if (Transaction::where('account_id', $accId)->count() === 0) {
                        $acc->current_balance = (float) $acc->initial_balance;
                        $acc->save();
                    }
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "{$deletedCount} transaksi berhasil dihapus",
            'deleted_count' => $deletedCount,
        ]);
    }

    public function importQris(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ], [
            'file.required' => 'File tidak ditemukan',
            'file.file' => 'Berkas yang diunggah harus berupa file yang valid',
            'file.mimes' => 'Harap unggah file Excel (.xlsx, .xls)',
            'file.max' => 'Ukuran file Excel maksimal 10 MB',
        ]);

        $file = $request->file('file');
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Format file Excel tidak dapat dibaca: ' . $e->getMessage(),
            ], 422);
        }

        $ws = $spreadsheet->getActiveSheet();
        $highestRow = $ws->getHighestRow();
        $highestCol = $ws->getHighestColumn();
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

        $accountBni = Account::where('code', 'bank_bni')->first() ?: Account::first();
        if (!$accountBni) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun Kas/Bank BNI tidak ditemukan di database.',
            ], 422);
        }

        $categories = ProgramCategory::all()->keyBy('code');
        // Default / Kas Umum: Pengembangan Umum (UM)
        $defaultCat = $categories->get('UM')
            ?: ($categories->first(fn ($c) => stripos($c->name, 'Pengembangan Umum') !== false || stripos($c->name, 'Umum') !== false)
            ?: ($categories->get('STHL') ?: ProgramCategory::first()));

        // Pos program dengan digit unik terdaftar (kecuali digit '0' dan kategori default umum)
        $catByDigit = ProgramCategory::whereNotNull('qris_unit_digit')
            ->where('qris_unit_digit', '!=', '')
            ->where('qris_unit_digit', '!=', '0')
            ->where('id', '!=', $defaultCat->id)
            ->get()
            ->keyBy(fn ($c) => (string) trim($c->qris_unit_digit));

        $headerRow = 2;
        $colMap = [
            'bill_no' => 6,
            'reff_id' => 7,
            'name' => 14,
            'amount' => 17,
            'date' => 20,
        ];

        for ($r = 1; $r <= min(6, $highestRow); $r++) {
            for ($c = 1; $c <= $highestColIndex; $c++) {
                $val = strtolower(trim((string) $ws->getCell([$c, $r])->getValue()));
                if ($val === 'customer name') {
                    $colMap['name'] = $c;
                    $headerRow = $r;
                } elseif ($val === 'amount') {
                    $colMap['amount'] = $c;
                    $headerRow = $r;
                } elseif ($val === 'reff id' || $val === 'ref id') {
                    $colMap['reff_id'] = $c;
                } elseif ($val === 'bill number' || $val === 'bill no') {
                    $colMap['bill_no'] = $c;
                } elseif ($val === 'trx date') {
                    $colMap['date'] = $c;
                }
            }
        }

        $importedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use ($ws, $highestRow, $colMap, $headerRow, $accountBni, $catByDigit, $defaultCat, &$importedCount, &$skippedCount) {
            for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
                $rawName = $ws->getCell([$colMap['name'], $r])->getValue();
                $rawAmount = $ws->getCell([$colMap['amount'], $r])->getValue();
                $rawReff = $ws->getCell([$colMap['reff_id'], $r])->getValue();
                $rawBill = $ws->getCell([$colMap['bill_no'], $r])->getValue();
                $rawDate = $ws->getCell([$colMap['date'], $r])->getValue();

                if (empty($rawAmount) || empty($rawReff)) {
                    continue;
                }

                $amountStr = preg_replace('/[^\d]/', '', (string) $rawAmount);
                if (empty($amountStr)) {
                    continue;
                }
                $amount = (float) $amountStr;

                $reffId = trim((string) $rawReff);
                if (Transaction::where('qris_reff_id', $reffId)->exists()) {
                    $skippedCount++;
                    continue;
                }

                // Detect category by unit digit
                $lastDigit = substr($amountStr, -1);
                $lastTwoDigits = strlen($amountStr) >= 2 ? substr($amountStr, -2) : $lastDigit;

                $targetCat = $catByDigit->get($lastTwoDigits) ?: ($catByDigit->get($lastDigit) ?: $defaultCat);

                // Donor name
                $cleanName = $this->sanitizeName((string) $rawName);
                $donor = Donor::firstOrCreate(['name' => $cleanName]);

                // Date parse
                $txDate = Carbon::today()->toDateString();
                if (!empty($rawDate)) {
                    try {
                        $txDate = Carbon::parse($rawDate)->toDateString();
                    } catch (\Exception $e) {
                    }
                }

                $tx = Transaction::create([
                    'transaction_date' => $txDate,
                    'type' => 'income',
                    'account_id' => $accountBni->id,
                    'category_id' => $targetCat->id,
                    'donor_id' => $donor->id,
                    'donor_name_raw' => $cleanName,
                    'amount' => $amount,
                    'description' => "Import QRIS [Ref: {$reffId}] [Bill: {$rawBill}]",
                    'qris_reff_id' => $reffId,
                    'qris_bill_no' => (string) $rawBill,
                ]);

                $accountBni->increment('current_balance', $amount);
                $importedCount++;
            }
        });

        if ($importedCount === 0 && $skippedCount === 0) {
            $msg = "Tidak ditemukan transaksi QRIS baru pada baris data Excel. Pastikan format kolom sesuai template QRIS.";
        } elseif ($importedCount === 0 && $skippedCount > 0) {
            $msg = "Semua transaksi ({$skippedCount}) dilewati karena sudah pernah diimpor sebelumnya.";
        } else {
            $msg = "Berhasil mengimpor {$importedCount} transaksi QRIS. ({$skippedCount} dilewati karena sudah ada)";
        }

        return response()->json([
            'status' => 'success',
            'message' => $msg,
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
        ]);
    }

    public function reallocateQris(Request $request)
    {
        $categories = ProgramCategory::all()->keyBy('code');
        // Default / Kas Umum: Pengembangan Umum (UM)
        $defaultCat = $categories->get('UM')
            ?: ($categories->first(fn ($c) => stripos($c->name, 'Pengembangan Umum') !== false || stripos($c->name, 'Umum') !== false)
            ?: ($categories->get('STHL') ?: ProgramCategory::first()));

        // Pos program dengan digit unik terdaftar (kecuali digit '0' dan kategori default umum)
        $catByDigit = ProgramCategory::whereNotNull('qris_unit_digit')
            ->where('qris_unit_digit', '!=', '')
            ->where('qris_unit_digit', '!=', '0')
            ->where('id', '!=', $defaultCat->id)
            ->get()
            ->keyBy(fn ($c) => (string) trim($c->qris_unit_digit));

        $qrisTxs = Transaction::whereNotNull('qris_reff_id')->get();
        $totalCount = $qrisTxs->count();
        $updatedCount = 0;
        $unchangedCount = 0;
        $breakdown = [];

        DB::transaction(function () use ($qrisTxs, $catByDigit, $defaultCat, &$updatedCount, &$unchangedCount, &$breakdown) {
            foreach ($qrisTxs as $tx) {
                $amountStr = preg_replace('/[^\d]/', '', (string) $tx->amount);
                $lastDigit = substr($amountStr, -1);
                $lastTwoDigits = strlen($amountStr) >= 2 ? substr($amountStr, -2) : $lastDigit;

                $targetCat = $catByDigit->get($lastTwoDigits) ?: ($catByDigit->get($lastDigit) ?: $defaultCat);

                $breakdown[$targetCat->code] = ($breakdown[$targetCat->code] ?? 0) + 1;

                if ($tx->category_id !== $targetCat->id) {
                    $tx->update(['category_id' => $targetCat->id]);
                    $updatedCount++;
                } else {
                    $unchangedCount++;
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "Berhasil memetakan ulang {$totalCount} transaksi QRIS. ({$updatedCount} transaksi disesuaikan posnya, {$unchangedCount} sudah sesuai)",
            'total_qris' => $totalCount,
            'updated_count' => $updatedCount,
            'unchanged_count' => $unchangedCount,
            'breakdown' => $breakdown,
        ]);
    }
}
