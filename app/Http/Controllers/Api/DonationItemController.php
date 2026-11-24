<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DonationItem;
use App\Traits\HandlesSecureUploads;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DonationItemController extends Controller
{
    use HandlesSecureUploads;
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
            'bukti_foto' => $item->photo_path ? '/uploads/' . basename($item->photo_path) : null,
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

        $this->validateSecureFile($request, 'foto_barang');

        $photoPath = $this->uploadSecureFile($request->file('foto_barang'), 'natura');

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

    public function destroy($id)
    {
        $item = DonationItem::findOrFail($id);
        $this->deleteSecureFile($item->photo_path);
        $item->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Donasi barang berhasil dihapus',
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ], [
            'ids.required' => 'Daftar ID donasi barang tidak boleh kosong',
            'ids.array' => 'Format ID donasi barang tidak valid',
        ]);

        $ids = $request->input('ids', []);
        $deletedCount = 0;

        DB::transaction(function () use ($ids, &$deletedCount) {
            $items = DonationItem::whereIn('id', $ids)->get();
            foreach ($items as $item) {
                $this->deleteSecureFile($item->photo_path);
                $item->delete();
                $deletedCount++;
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "{$deletedCount} data donasi barang berhasil dihapus",
            'deleted_count' => $deletedCount,
        ]);
    }
}
