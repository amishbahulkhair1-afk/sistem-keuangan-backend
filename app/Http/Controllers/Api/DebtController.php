<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DebtTransaction;
use App\Models\PosTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DebtController extends Controller
{
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
            $pName = trim($t->party_name);
            if (in_array($t->type, ['hutang_pinjam', 'hutang_bayar'])) {
                if (!isset($hutangRecap[$pName])) {
                    $hutangRecap[$pName] = ['party_name' => $pName, 'total_pinjam' => 0.0, 'total_bayar' => 0.0, 'saldo' => 0.0];
                }
                if ($t->type === 'hutang_pinjam') {
                    $hutangRecap[$pName]['total_pinjam'] += (float) $t->amount;
                } else {
                    $hutangRecap[$pName]['total_bayar'] += (float) $t->amount;
                }
                $hutangRecap[$pName]['saldo'] = $hutangRecap[$pName]['total_pinjam'] - $hutangRecap[$pName]['total_bayar'];
            } elseif (in_array($t->type, ['piutang_pinjam', 'piutang_bayar'])) {
                if (!isset($piutangRecap[$pName])) {
                    $piutangRecap[$pName] = ['party_name' => $pName, 'total_piutang' => 0.0, 'total_bayar' => 0.0, 'saldo' => 0.0];
                }
                if ($t->type === 'piutang_pinjam') {
                    $piutangRecap[$pName]['total_piutang'] += (float) $t->amount;
                } else {
                    $piutangRecap[$pName]['total_bayar'] += (float) $t->amount;
                }
                $piutangRecap[$pName]['saldo'] = $piutangRecap[$pName]['total_piutang'] - $piutangRecap[$pName]['total_bayar'];
            }
        }

        $totalSisaHutang = array_sum(array_column($hutangRecap, 'saldo'));
        $totalSisaPiutang = array_sum(array_column($piutangRecap, 'saldo'));

        return response()->json([
            'hutang_list' => array_values($hutangRecap),
            'piutang_list' => array_values($piutangRecap),
            'total_sisa_hutang' => (float) $totalSisaHutang,
            'total_sisa_piutang' => (float) $totalSisaPiutang,
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

        $txDate = $request->transaction_date ? Carbon::parse($request->transaction_date)->toDateString() : Carbon::today()->toDateString();

        $photoPath = null;
        if ($request->hasFile('receipt_photo')) {
            $file = $request->file('receipt_photo');
            $filename = 'debt_' . date('Ymd_His') . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads'), $filename);
            $photoPath = public_path('uploads/' . $filename);
        }

        $dt = DebtTransaction::create([
            'category_id' => $validated['category_id'],
            'transaction_date' => $txDate,
            'type' => $validated['type'],
            'party_name' => trim($validated['party_name']),
            'amount' => (float) $validated['amount'],
            'description' => $request->description ? trim($request->description) : null,
            'receipt_photo_path' => $photoPath,
        ]);

        return response()->json([
            'id' => $dt->id,
            'message' => 'Transaksi hutang/piutang berhasil disimpan',
        ], 201);
    }

    public function destroy($id)
    {
        $dt = DebtTransaction::findOrFail($id);
        $dt->delete();
        return response()->json(['message' => 'Transaksi hutang/piutang berhasil dihapus']);
    }
}
