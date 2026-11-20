<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Donor;
use App\Models\PosTransaction;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TransactionController extends Controller
{
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

        // Handle Photo Upload
        $photoPath = null;
        if ($request->hasFile('bukti_foto')) {
            $file = $request->file('bukti_foto');
            $filename = 'bukti_' . date('Ymd_His') . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads'), $filename);
            $photoPath = public_path('uploads/' . $filename);
        }

        $txDate = Carbon::parse($request->tanggal)->toDateString();

        $tx = Transaction::create([
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
            $account->increment('current_balance', $tx->amount);
        } else {
            $account->decrement('current_balance', $tx->amount);

            // OTOMATIS: Pengeluaran pusat yang dialokasikan ke pos program
            // menjadi penambah saldo Kas Masuk/Aktiva bagi Pos Program tersebut.
            PosTransaction::create([
                'category_id' => $request->category_id,
                'transaction_date' => $txDate,
                'type' => 'income',
                'category_tx_type' => 'alokasi_pusat',
                'source_transaction_id' => $tx->id,
                'amount' => $tx->amount,
                'recipient_or_sender' => 'Alokasi Pusat Donasi',
                'description' => 'Turunan alokasi dana dari kas pusat: ' . ($request->keterangan ?: 'Alokasi Kas'),
                'receipt_photo_path' => $photoPath,
            ]);
        }

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

        $nominal = (float) ($request->nominal ?? $tx->amount);
        $txDate = $request->tanggal ? Carbon::parse($request->tanggal)->toDateString() : $tx->transaction_date;

        $tx->update([
            'transaction_date' => $txDate,
            'type' => $txType,
            'category_id' => $request->category_id ?? $tx->category_id,
            'amount' => $nominal,
            'description' => $request->keterangan ?? $tx->description,
        ]);

        // Apply new balance
        $newAccount = Account::find($tx->account_id);
        if ($newAccount) {
            if ($txType === 'income') {
                $newAccount->increment('current_balance', $nominal);
            } else {
                $newAccount->decrement('current_balance', $nominal);
            }
        }

        return response()->json(['message' => 'Transaksi berhasil diperbarui']);
    }

    public function destroy($id)
    {
        $tx = Transaction::findOrFail($id);
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

        $tx->delete();

        return response()->json(['message' => 'Transaksi berhasil dihapus']);
    }

    public function importQris(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json(['detail' => 'File tidak ditemukan'], 400);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls'])) {
            return response()->json(['detail' => 'Harap unggah file Excel (.xlsx)'], 400);
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $ws = $spreadsheet->getActiveSheet();
        $highestRow = $ws->getHighestRow();
        $highestCol = $ws->getHighestColumn();
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

        $accountBni = Account::where('code', 'bank_bni')->first() ?: Account::first();
        $categories = ProgramCategory::all()->keyBy('code');
        $catByDigit = ProgramCategory::whereNotNull('qris_unit_digit')->get()->keyBy('qris_unit_digit');
        $defaultCat = $categories->get('STHL') ?: ProgramCategory::first();

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

        return response()->json([
            'message' => "Berhasil mengimpor {$importedCount} transaksi QRIS. ({$skippedCount} dilewati karena sudah ada)",
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
        ]);
    }
}
