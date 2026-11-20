<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Donor;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use Illuminate\Http\Request;

class DonorController extends Controller
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
        $search = $request->query('search');
        $pos = $request->query('pos');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = Donor::query();
        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }
        $donors = $query->orderBy('name', 'asc')->limit(500)->get();

        $selectedCat = null;
        if ($pos) {
            $posClean = trim($pos);
            $selectedCat = ProgramCategory::where('code', $posClean)
                ->orWhere('name', $posClean)
                ->when(is_numeric($posClean), fn ($q) => $q->orWhere('id', (int) $posClean))
                ->first();
        }

        $result = [];
        foreach ($donors as $d) {
            $txQuery = Transaction::with(['category', 'account'])
                ->where('donor_id', $d->id)
                ->where('type', 'income');

            if ($selectedCat) {
                $txQuery->where('category_id', $selectedCat->id);
            }
            if ($startDate && $endDate) {
                $txQuery->whereBetween('transaction_date', [$startDate, $endDate]);
            } elseif ($startDate) {
                $txQuery->where('transaction_date', '>=', $startDate);
            } elseif ($endDate) {
                $txQuery->where('transaction_date', '<=', $endDate);
            }

            $txs = $txQuery->orderBy('transaction_date', 'desc')->get();

            if ($selectedCat && $txs->isEmpty()) {
                continue;
            }

            $totalDonasi = $txs->sum('amount');
            $frekuensi = $txs->count();

            $posMap = [];
            foreach ($txs as $t) {
                $cName = $t->category?->name ?? 'Umum';
                $cCode = $t->category?->code ?? '-';
                $posKey = "[{$cCode}] {$cName}";
                $posMap[$posKey] = ($posMap[$posKey] ?? 0) + $t->amount;
            }

            $breakdownParts = [];
            foreach ($posMap as $k => $v) {
                $breakdownParts[] = "{$k}: Rp " . number_format($v, 0, ',', '.');
            }
            $posBreakdown = !empty($breakdownParts) ? implode(', ', $breakdownParts) : '-';

            $txDetails = [];
            foreach ($txs->take(10) as $t) {
                $txDetails[] = [
                    'id' => $t->id,
                    'tanggal' => $t->transaction_date ? $t->transaction_date->format('Y-m-d') : '-',
                    'nominal' => (float) $t->amount,
                    'pos_program' => $t->category?->name ?? '-',
                    'pos_code' => $t->category?->code ?? '-',
                    'metode' => $t->account?->name ?? '-',
                    'keterangan' => $t->description ?? '-',
                ];
            }

            $result[] = [
                'id' => $d->id,
                'name' => $d->name,
                'phone' => $d->phone ?? '-',
                'address' => $d->address ?? '-',
                'total_donasi' => (float) $totalDonasi,
                'frekuensi' => $frekuensi,
                'pos_breakdown' => $posBreakdown,
                'tx_details' => $txDetails,
                'created_at' => $d->created_at ? $d->created_at->format('Y-m-d') : '-',
            ];
        }

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string']);
        $cleanName = $this->sanitizeName($request->name);

        $existing = Donor::where('name', $cleanName)->first();
        if ($existing) {
            return response()->json([
                'id' => $existing->id,
                'message' => "Donatur '{$cleanName}' sudah terdaftar",
            ]);
        }

        $donor = Donor::create([
            'name' => $cleanName,
            'phone' => $request->phone,
            'address' => $request->address,
        ]);

        return response()->json([
            'id' => $donor->id,
            'message' => "Donatur '{$donor->name}' berhasil didaftarkan",
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $donor = Donor::findOrFail($id);
        $request->validate(['name' => 'required|string']);
        $cleanName = $this->sanitizeName($request->name);

        $donor->update([
            'name' => $cleanName,
            'phone' => $request->phone,
            'address' => $request->address,
        ]);

        return response()->json(['message' => "Data donatur '{$donor->name}' berhasil diperbarui"]);
    }

    public function destroy($id)
    {
        $donor = Donor::findOrFail($id);
        $txCount = Transaction::where('donor_id', $id)->count();
        if ($txCount > 0) {
            return response()->json([
                'detail' => "Tidak dapat menghapus donatur karena memiliki {$txCount} transaksi",
            ], 400);
        }
        $donor->delete();
        return response()->json(['message' => "Donatur berhasil dihapus"]);
    }
}
