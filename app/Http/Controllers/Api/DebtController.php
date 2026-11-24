<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DebtTransaction;
use App\Models\PosTransaction;
use App\Traits\HandlesSecureUploads;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebtController extends Controller
{
    use HandlesSecureUploads;
    public function index(Request $request)
    {
        $categoryId = $request->query('category_id');
        $debtType = $request->query('debt_type');

        $query = DebtTransaction::with('category');
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        if ($debtType) {
            $query->where('type', $debtType);
        }

        $txs = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($txs->map(fn ($t) => [
            'id' => $t->id,
            'category_id' => $t->category_id,
            'pos_code' => $t->category?->code ?? '-',
            'pos_name' => $t->category?->name ?? '-',
            'transaction_date' => $t->transaction_date ? $t->transaction_date->format('Y-m-d') : '-',
            'type' => $t->type,
            'party_name' => $t->party_name,
            'amount' => (float) $t->amount,
            'description' => $t->description ?: '-',
            'bukti_foto' => $t->receipt_photo_path ? '/uploads/' . basename($t->receipt_photo_path) : null,
            'source_pos_tx_id' => $t->source_pos_tx_id,
        ]));
    }

    public function recap(Request $request)
    {
        $categoryId = $request->query('category_id');

        $query = DebtTransaction::query();
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        $allTxs = $query->get();

        $hutangRecap = [];
        $piutangRecap = [];

        foreach ($allTxs as $t) {
            $rawName = trim($t->party_name ?: 'Pihak Terkait');
            $normKey = mb_strtolower($rawName);

            if (in_array($t->type, ['hutang_pinjam', 'hutang_bayar'])) {
                if (!isset($hutangRecap[$normKey])) {
                    $hutangRecap[$normKey] = [
                        'party_name' => $rawName,
                        'total_pinjam' => 0.0,
                        'total_bayar' => 0.0,
                        'saldo' => 0.0,
                        'transaction_count' => 0,
                    ];
                }
                $hutangRecap[$normKey]['transaction_count']++;
                if ($t->type === 'hutang_pinjam') {
                    $hutangRecap[$normKey]['total_pinjam'] += (float) $t->amount;
                } else {
                    $hutangRecap[$normKey]['total_bayar'] += (float) $t->amount;
                }
                $hutangRecap[$normKey]['saldo'] = $hutangRecap[$normKey]['total_pinjam'] - $hutangRecap[$normKey]['total_bayar'];
            } elseif (in_array($t->type, ['piutang_pinjam', 'piutang_bayar'])) {
                if (!isset($piutangRecap[$normKey])) {
                    $piutangRecap[$normKey] = [
                        'party_name' => $rawName,
                        'total_piutang' => 0.0,
                        'total_bayar' => 0.0,
                        'saldo' => 0.0,
                        'transaction_count' => 0,
                    ];
                }
                $piutangRecap[$normKey]['transaction_count']++;
                if ($t->type === 'piutang_pinjam') {
                    $piutangRecap[$normKey]['total_piutang'] += (float) $t->amount;
                } else {
                    $piutangRecap[$normKey]['total_bayar'] += (float) $t->amount;
                }
                $piutangRecap[$normKey]['saldo'] = $piutangRecap[$normKey]['total_piutang'] - $piutangRecap[$normKey]['total_bayar'];
            }
        }

        $totalSisaHutang = array_sum(array_column($hutangRecap, 'saldo'));
        $totalSisaPiutang = array_sum(array_column($piutangRecap, 'saldo'));
        $totalHutangPinjam = array_sum(array_column($hutangRecap, 'total_pinjam'));
        $totalHutangBayar = array_sum(array_column($hutangRecap, 'total_bayar'));
        $totalPiutangPinjam = array_sum(array_column($piutangRecap, 'total_piutang'));
        $totalPiutangBayar = array_sum(array_column($piutangRecap, 'total_bayar'));

        return response()->json([
            'hutang_list' => array_values($hutangRecap),
            'piutang_list' => array_values($piutangRecap),
            'total_sisa_hutang' => (float) $totalSisaHutang,
            'total_sisa_piutang' => (float) $totalSisaPiutang,
            'total_hutang_pinjam' => (float) $totalHutangPinjam,
            'total_hutang_bayar' => (float) $totalHutangBayar,
            'total_piutang_pinjam' => (float) $totalPiutangPinjam,
            'total_piutang_bayar' => (float) $totalPiutangBayar,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer',
            'type' => 'required|string',
            'party_name' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        $this->validateSecureFile($request, 'receipt_photo');

        $txDate = $request->transaction_date ? Carbon::parse($request->transaction_date)->toDateString() : Carbon::today()->toDateString();
        $photoPath = $this->uploadSecureFile($request->file('receipt_photo'), 'debt');

        $dt = DB::transaction(function () use ($validated, $txDate, $photoPath, $request) {
            $createdDt = DebtTransaction::create([
                'category_id' => $validated['category_id'],
                'transaction_date' => $txDate,
                'type' => $validated['type'],
                'party_name' => trim($validated['party_name']),
                'amount' => (float) $validated['amount'],
                'description' => $request->description ? trim($request->description) : null,
                'receipt_photo_path' => $photoPath,
            ]);

            // Sinkronkan otomatis ke Kas Pos (PosTransaction)
            // Jenis arus kas:
            // 1. hutang_pinjam (Pinjam dana baru) => Kas Masuk (income)
            // 2. hutang_bayar (Bayar cicilan hutang) => Kas Keluar (expense)
            // 3. piutang_pinjam (Memberi pinjaman) => Kas Keluar (expense)
            // 4. piutang_bayar (Terima pembayaran piutang) => Kas Masuk (income)
            $posTxType = in_array($validated['type'], ['hutang_pinjam', 'piutang_bayar']) ? 'income' : 'expense';

            $descPrefix = match ($validated['type']) {
                'hutang_pinjam' => 'Penerimaan pinjaman dana dari ' . trim($validated['party_name']),
                'hutang_bayar' => 'Pembayaran cicilan hutang kepada ' . trim($validated['party_name']),
                'piutang_pinjam' => 'Pemberian pinjaman dana kepada ' . trim($validated['party_name']),
                'piutang_bayar' => 'Penerimaan pengembalian piutang dari ' . trim($validated['party_name']),
                default => 'Transaksi hutang/piutang: ' . trim($validated['party_name']),
            };

            $fullDesc = $request->description ? $descPrefix . ' (' . trim($request->description) . ')' : $descPrefix;

            $createdPt = PosTransaction::create([
                'category_id' => $validated['category_id'],
                'transaction_date' => $txDate,
                'type' => $posTxType,
                'category_tx_type' => $validated['type'],
                'amount' => (float) $validated['amount'],
                'recipient_or_sender' => trim($validated['party_name']),
                'description' => $fullDesc,
                'receipt_photo_path' => $photoPath,
                'source_debt_tx_id' => $createdDt->id,
            ]);

            $createdDt->update(['source_pos_tx_id' => $createdPt->id]);

            return $createdDt;
        });

        return response()->json([
            'id' => $dt->id,
            'message' => 'Transaksi hutang/piutang berhasil disimpan dan disinkronkan ke kas pos',
        ], 201);
    }

    public function destroy($id)
    {
        $dt = DebtTransaction::findOrFail($id);

        DB::transaction(function () use ($dt, $id) {
            // Delete linked pos transaction if any
            if ($dt->source_pos_tx_id) {
                PosTransaction::where('id', $dt->source_pos_tx_id)->delete();
            }
            PosTransaction::where('source_debt_tx_id', $id)->delete();

            $this->deleteSecureFile($dt->receipt_photo_path);
            $dt->delete();
        });

        return response()->json(['message' => 'Transaksi hutang/piutang berhasil dihapus']);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ], [
            'ids.required' => 'Daftar ID hutang/piutang tidak boleh kosong',
            'ids.array' => 'Format ID tidak valid',
        ]);

        $ids = $request->input('ids', []);
        $deletedCount = 0;

        DB::transaction(function () use ($ids, &$deletedCount) {
            $debts = DebtTransaction::whereIn('id', $ids)->get();
            foreach ($debts as $dt) {
                if ($dt->source_pos_tx_id) {
                    PosTransaction::where('id', $dt->source_pos_tx_id)->delete();
                }
                PosTransaction::where('source_debt_tx_id', $dt->id)->delete();

                $this->deleteSecureFile($dt->receipt_photo_path);
                $dt->delete();
                $deletedCount++;
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "{$deletedCount} data hutang/piutang berhasil dihapus",
            'deleted_count' => $deletedCount,
        ]);
    }
}
