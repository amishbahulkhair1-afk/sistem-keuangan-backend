<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DonationItem;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DonationItemController extends Controller
{
    public function index()
    {
        $items = DonationItem::with('category')
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($items->map(fn ($item) => [
            'id' => $item->id,
            'tanggal' => $item->transaction_date ? $item->transaction_date->format('Y-m-d') : '-',
            'nama_donatur' => $item->donor_name,
            'kategori_barang' => $item->item_category,
            'nama_barang' => $item->item_name,
            'jumlah' => (float) $item->quantity,
            'satuan' => $item->unit,
            'estimasi_nilai' => (float) ($item->estimated_value ?? 0),
            'keterangan' => $item->description,
            'category_id' => $item->category_id,
            'pos_program' => $item->category?->name ?? 'Umum',
            'foto' => $item->photo_path ? '/uploads/' . basename($item->photo_path) : null,
        ]));
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama_donatur' => 'required',
            'nama_barang' => 'required',
            'jumlah' => 'required|numeric',
            'satuan' => 'required',
        ]);

        $photoPath = null;
        if ($request->hasFile('foto_barang')) {
            $file = $request->file('foto_barang');
            $filename = 'natura_' . date('Ymd_His') . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads'), $filename);
            $photoPath = public_path('uploads/' . $filename);
        }

        $txDate = $request->tanggal ? Carbon::parse($request->tanggal)->toDateString() : Carbon::today()->toDateString();

        $item = DonationItem::create([
            'transaction_date' => $txDate,
            'donor_name' => $request->nama_donatur,
            'item_category' => $request->kategori_barang ?: 'Material Bangunan',
            'item_name' => $request->nama_barang,
            'quantity' => (float) $request->jumlah,
            'unit' => $request->satuan,
            'estimated_value' => $request->estimasi_nilai ? (float) $request->estimasi_nilai : 0,
            'description' => $request->keterangan,
            'category_id' => $request->category_id ?: null,
            'photo_path' => $photoPath,
        ]);

        return response()->json([
            'id' => $item->id,
            'message' => 'Donasi barang berhasil dicatat',
        ], 201);
    }
}
