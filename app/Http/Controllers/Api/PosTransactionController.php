<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DebtTransaction;
use App\Models\MaterialItem;
use App\Models\MaterialTransaction;
use App\Models\PosTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PosTransactionController extends Controller
{
    public function index(Request $request)
    {
        $categoryId = $request->query('category_id');
        $txType = $request->query('tx_type');

        $query = PosTransaction::with('category');
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        if ($txType) {
            $query->where('type', $txType);
        }

        $txs = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $totalIn = $txs->where('type', 'income')->sum('amount');
        $totalOut = $txs->where('type', 'expense')->sum('amount');
        $saldoKasPos = $totalIn - $totalOut;

        return response()->json([
            'transactions' => $txs->map(fn ($t) => [
                'id' => $t->id,
                'category_id' => $t->category_id,
                'pos_code' => $t->category?->code ?? '-',
                'pos_name' => $t->category?->name ?? '-',
                'transaction_date' => $t->transaction_date ? $t->transaction_date->format('Y-m-d') : '-',
                'type' => $t->type,
                'category_tx_type' => $t->category_tx_type,
                'source_transaction_id' => $t->source_transaction_id,
                'amount' => (float) $t->amount,
                'recipient_or_sender' => $t->recipient_or_sender ?: '-',
                'description' => $t->description ?: '-',
                'bukti_foto' => $t->receipt_photo_path ? '/uploads/' . basename($t->receipt_photo_path) : null,
            ]),
            'summary' => [
                'total_income' => (float) $totalIn,
                'total_expense' => (float) $totalOut,
                'saldo_kas_pos' => (float) $saldoKasPos,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|integer',
            'amount' => 'nullable|numeric',
        ]);

        $txDate = $request->transaction_date ? Carbon::parse($request->transaction_date)->toDateString() : Carbon::today()->toDateString();
        $catType = $request->category_tx_type ?: 'operasional_lapangan';

        // Auto determine cashflow type if debt/credit
        $txType = $request->type ?: 'expense';
        if (in_array($catType, ['hutang_pinjam', 'piutang_bayar'])) {
            $txType = 'income';
        } elseif (in_array($catType, ['hutang_bayar', 'piutang_pinjam', 'belanja_material'])) {
            $txType = 'expense';
        }

        $finalAmount = (float) ($request->amount ?? 0);
        if ($finalAmount <= 0 && $request->material_quantity && $request->material_price) {
            $finalAmount = (float) $request->material_quantity * (float) $request->material_price;
        }

        $finalDesc = trim((string) ($request->description ?? ''));
        if (empty($finalDesc) && ($request->material_name || $request->material_id)) {
            $mName = $request->material_name ?: 'Material';
            $mBrand = $request->material_brand ? ' ' . trim($request->material_brand) : '';
            $mQty = $request->material_quantity ? ' ' . $request->material_quantity : '';
            $mUnit = $request->material_unit ? ' ' . $request->material_unit : '';
            $finalDesc = trim("Beli {$mName}{$mBrand}{$mQty}{$mUnit}");
        }

        $photoPath = null;
        if ($request->hasFile('receipt_photo')) {
            $file = $request->file('receipt_photo');
            $filename = 'pos_' . date('Ymd_His') . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads'), $filename);
            $photoPath = public_path('uploads/' . $filename);
        }

        $pt = PosTransaction::create([
            'category_id' => $request->category_id,
            'transaction_date' => $txDate,
            'type' => $txType,
            'category_tx_type' => $catType,
            'amount' => $finalAmount,
            'recipient_or_sender' => $request->recipient_or_sender ? trim($request->recipient_or_sender) : null,
            'description' => !empty($finalDesc) ? $finalDesc : null,
            'receipt_photo_path' => $photoPath,
        ]);

        // 1. Sync to DebtTransaction if applicable
        if (in_array($catType, ['hutang_pinjam', 'hutang_bayar', 'piutang_pinjam', 'piutang_bayar'])) {
            $party = $request->recipient_or_sender ? trim($request->recipient_or_sender) : 'Pihak Terkait';
            DebtTransaction::create([
                'category_id' => $request->category_id,
                'transaction_date' => $txDate,
                'type' => $catType,
                'party_name' => $party,
                'amount' => $finalAmount,
                'description' => !empty($finalDesc) ? $finalDesc : null,
                'source_pos_tx_id' => $pt->id,
            ]);
        }

        // 2. Material linkage if material provided
        $matToRecord = null;
        if ($request->material_id) {
            $matToRecord = MaterialItem::find($request->material_id);
        } elseif (!empty($request->material_name)) {
            $rawName = trim($request->material_name);
            $rawBrand = $request->material_brand ? trim($request->material_brand) : '';
            $fullDisplayName = preg_replace('/\s+/', ' ', trim($rawName . ' ' . $rawBrand));

            $existingMats = MaterialItem::where('category_id', $request->category_id)->get();
            $matched = null;
            foreach ($existingMats as $em) {
                if (strcasecmp(trim($em->name), $fullDisplayName) === 0 || strcasecmp(trim($em->name), $rawName) === 0) {
                    $matched = $em;
                    break;
                }
            }

            if ($matched) {
                $matToRecord = $matched;
            } else {
                $words = explode(' ', $fullDisplayName);
                $baseCode = '';
                foreach ($words as $w) {
                    if (!empty($w)) {
                        $baseCode .= strtoupper($w[0]);
                    }
                }
                $baseCode = substr($baseCode ?: 'MAT', 0, 4);

                $existingCodes = $existingMats->pluck('code')->map(fn ($c) => strtoupper($c))->toArray();
                $matCode = $baseCode;
                $counter = 1;
                while (in_array($matCode, $existingCodes)) {
                    $matCode = $baseCode . $counter;
                    $counter++;
                }

                $unitVal = $request->material_unit ? trim($request->material_unit) : 'Pcs';
                $unitPVal = (float) ($request->material_price ?? 0);
                if ($unitPVal <= 0 && $request->material_quantity && (float) $request->material_quantity > 0) {
                    $unitPVal = $finalAmount / (float) $request->material_quantity;
                }

                $matToRecord = MaterialItem::create([
                    'category_id' => $request->category_id,
                    'code' => $matCode,
                    'name' => $fullDisplayName,
                    'unit' => $unitVal,
                    'unit_price' => $unitPVal,
                    'initial_stock' => 0,
                    'current_stock' => 0,
                ]);
            }
        }

        if ($matToRecord) {
            $qty = (float) ($request->material_quantity ?? 1.0);
            $unitP = ($qty > 0 && $finalAmount > 0) ? ($finalAmount / $qty) : ((float) ($request->material_price ?? $matToRecord->unit_price));

            MaterialTransaction::create([
                'material_id' => $matToRecord->id,
                'category_id' => $request->category_id,
                'transaction_date' => $txDate,
                'type' => 'masuk',
                'quantity' => $qty,
                'unit_price' => $unitP,
                'total_price' => $finalAmount > 0 ? $finalAmount : ($qty * $unitP),
                'supplier_or_source' => $request->recipient_or_sender ? trim($request->recipient_or_sender) : null,
                'usage_for' => "Pembelian material via Kas Pos (" . ($request->description ?: $matToRecord->name) . ")",
                'source_pos_tx_id' => $pt->id,
            ]);

            $matToRecord->increment('current_stock', $qty);
            if ($unitP > 0) {
                $matToRecord->update(['unit_price' => $unitP]);
            }
        }

        return response()->json([
            'id' => $pt->id,
            'message' => 'Pencatatan transaksi kas pos berhasil disimpan',
        ], 201);
    }

    public function destroy($id)
    {
        $pt = PosTransaction::findOrFail($id);

        // Delete linked debt entry
        DebtTransaction::where('source_pos_tx_id', $id)->delete();

        // Rollback material entry
        $matTxs = MaterialTransaction::where('source_pos_tx_id', $id)->get();
        foreach ($matTxs as $mt) {
            $m = MaterialItem::find($mt->material_id);
            if ($m) {
                if ($mt->type === 'masuk') {
                    $m->decrement('current_stock', $mt->quantity);
                } else {
                    $m->increment('current_stock', $mt->quantity);
                }
            }
            $mt->delete();
        }

        $pt->delete();

        return response()->json(['message' => 'Transaksi pos berhasil dihapus']);
    }
}
