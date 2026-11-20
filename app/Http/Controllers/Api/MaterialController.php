<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialItem;
use App\Models\MaterialTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $categoryId = $request->query('category_id');

        $query = MaterialItem::with('category');
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        $items = $query->get();

        $result = [];
        $totalStockValue = 0.0;

        foreach ($items as $m) {
            $txIn = (float) MaterialTransaction::where('material_id', $m->id)->where('type', 'masuk')->sum('quantity');
            $txOut = (float) MaterialTransaction::where('material_id', $m->id)->where('type', 'keluar')->sum('quantity');

            $calculatedStock = (float) $m->initial_stock + $txIn - $txOut;
            $totalVal = $calculatedStock * (float) $m->unit_price;
            $totalStockValue += $totalVal;

            $result[] = [
                'id' => $m->id,
                'category_id' => $m->category_id,
                'pos_code' => $m->category?->code ?? '-',
                'pos_name' => $m->category?->name ?? '-',
                'code' => $m->code,
                'name' => $m->name,
                'unit' => $m->unit,
                'unit_price' => (float) $m->unit_price,
                'initial_stock' => (float) $m->initial_stock,
                'total_masuk' => $txIn,
                'total_keluar' => $txOut,
                'current_stock' => $calculatedStock,
                'total_value' => (float) $totalVal,
            ];
        }

        return response()->json([
            'materials' => $result,
            'grand_total_value' => (float) $totalStockValue,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer',
            'code' => 'required|string',
            'name' => 'required|string',
            'unit' => 'required|string',
            'unit_price' => 'nullable|numeric',
            'initial_stock' => 'nullable|numeric',
        ]);

        $item = MaterialItem::create([
            'category_id' => $validated['category_id'],
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'unit' => trim($validated['unit']),
            'unit_price' => (float) ($validated['unit_price'] ?? 0),
            'initial_stock' => (float) ($validated['initial_stock'] ?? 0),
            'current_stock' => (float) ($validated['initial_stock'] ?? 0),
        ]);

        return response()->json($item, 201);
    }

    public function update(Request $request, $id)
    {
        $item = MaterialItem::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string',
            'unit' => 'required|string',
            'unit_price' => 'nullable|numeric',
            'initial_stock' => 'nullable|numeric',
        ]);

        $item->update([
            'name' => trim($validated['name']),
            'unit' => trim($validated['unit']),
            'unit_price' => (float) ($validated['unit_price'] ?? $item->unit_price),
            'initial_stock' => (float) ($validated['initial_stock'] ?? $item->initial_stock),
        ]);

        return response()->json($item);
    }

    public function destroy($id)
    {
        $item = MaterialItem::findOrFail($id);
        $item->delete();
        return response()->json(['message' => 'Material berhasil dihapus']);
    }

    public function transactions(Request $request)
    {
        $categoryId = $request->query('category_id');
        $materialId = $request->query('material_id');

        $query = MaterialTransaction::with(['material', 'category']);
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        if ($materialId) {
            $query->where('material_id', $materialId);
        }

        $txs = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($txs->map(fn ($t) => [
            'id' => $t->id,
            'material_id' => $t->material_id,
            'material_name' => $t->material?->name ?? '-',
            'unit' => $t->material?->unit ?? 'Pcs',
            'category_id' => $t->category_id,
            'pos_name' => $t->category?->name ?? '-',
            'transaction_date' => $t->transaction_date ? $t->transaction_date->format('Y-m-d') : '-',
            'type' => $t->type,
            'quantity' => (float) $t->quantity,
            'unit_price' => (float) $t->unit_price,
            'total_price' => (float) $t->total_price,
            'supplier_or_source' => $t->supplier_or_source ?: '-',
            'usage_for' => $t->usage_for ?: '-',
            'bukti_foto' => $t->photo_path ? '/uploads/' . basename($t->photo_path) : null,
        ]));
    }

    public function storeTransaction(Request $request)
    {
        $validated = $request->validate([
            'material_id' => 'required|integer',
            'category_id' => 'required|integer',
            'type' => 'required|string',
            'quantity' => 'required|numeric',
        ]);

        $m = MaterialItem::findOrFail($validated['material_id']);
        $txDate = $request->transaction_date ? Carbon::parse($request->transaction_date)->toDateString() : Carbon::today()->toDateString();
        $qty = (float) $validated['quantity'];
        $unitPrice = (float) ($request->unit_price ?? $m->unit_price);
        $totalPrice = $qty * $unitPrice;

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'mat_' . date('Ymd_His') . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads'), $filename);
            $photoPath = public_path('uploads/' . $filename);
        }

        $mt = MaterialTransaction::create([
            'material_id' => $m->id,
            'category_id' => $validated['category_id'],
            'transaction_date' => $txDate,
            'type' => $validated['type'],
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'supplier_or_source' => $request->supplier_or_source,
            'usage_for' => $request->usage_for,
            'photo_path' => $photoPath,
        ]);

        if ($validated['type'] === 'masuk') {
            $m->increment('current_stock', $qty);
        } else {
            $m->decrement('current_stock', $qty);
        }

        $msg = $validated['type'] === 'masuk'
            ? 'Penerimaan material berhasil dicatat'
            : "Pemakaian material ({$qty} {$m->unit}) berhasil dicatat!";

        return response()->json([
            'id' => $mt->id,
            'message' => $msg,
        ], 201);
    }

    public function destroyTransaction($id)
    {
        $mt = MaterialTransaction::findOrFail($id);
        $m = MaterialItem::find($mt->material_id);
        if ($m) {
            if ($mt->type === 'masuk') {
                $m->decrement('current_stock', $mt->quantity);
            } else {
                $m->increment('current_stock', $mt->quantity);
            }
        }
        $mt->delete();
        return response()->json(['message' => 'Transaksi material berhasil dihapus']);
    }
}
