<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function index()
    {
        $accounts = Account::all();
        return response()->json($accounts->map(fn ($a) => [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'initial_balance' => (float) $a->initial_balance,
            'current_balance' => (float) $a->current_balance,
            'balance' => (float) $a->current_balance,
        ]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:accounts,code',
            'name' => 'required|string',
            'initial_balance' => 'nullable|numeric',
        ]);

        $initial = (float) ($validated['initial_balance'] ?? 0);
        $account = Account::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'initial_balance' => $initial,
            'current_balance' => $initial,
        ]);

        return response()->json($account, 201);
    }

    public function update(Request $request, $id)
    {
        $account = Account::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string',
            'initial_balance' => 'nullable|numeric',
            'current_balance' => 'nullable|numeric',
        ]);

        $account->name = $validated['name'];
        if (array_key_exists('initial_balance', $validated)) {
            $account->initial_balance = (float) ($validated['initial_balance'] ?? 0);
        }

        if (array_key_exists('current_balance', $validated) && $validated['current_balance'] !== null) {
            $account->current_balance = (float) $validated['current_balance'];
        } else {
            $inc = (float) $account->transactions()->where('type', 'income')->sum('amount');
            $exp = (float) $account->transactions()->where('type', 'expense')->sum('amount');
            $account->current_balance = (float) $account->initial_balance + $inc - $exp;
        }

        $account->save();
        return response()->json([
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'initial_balance' => (float) $account->initial_balance,
            'current_balance' => (float) $account->current_balance,
            'balance' => (float) $account->current_balance,
            'message' => 'Rekening berhasil diperbarui',
        ]);
    }

    public function recalculate()
    {
        $accounts = Account::all();
        foreach ($accounts as $acc) {
            $inc = (float) $acc->transactions()->where('type', 'income')->sum('amount');
            $exp = (float) $acc->transactions()->where('type', 'expense')->sum('amount');
            $acc->current_balance = (float) $acc->initial_balance + $inc - $exp;
            $acc->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Seluruh saldo rekening kas & bank berhasil disinkronkan dan dihitung ulang sesuai transaksi',
        ]);
    }

    public function resetBalance($id)
    {
        $account = Account::findOrFail($id);
        $account->initial_balance = 0.0;
        $account->current_balance = 0.0;
        $account->save();

        return response()->json([
            'status' => 'success',
            'message' => "Saldo kas rekening {$account->name} berhasil di-reset menjadi Rp 0",
            'account' => $account,
        ]);
    }

    public function destroy($id)
    {
        $account = Account::findOrFail($id);
        $account->delete();
        return response()->json(['message' => 'Rekening berhasil dihapus']);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ], [
            'ids.required' => 'Daftar ID rekening tidak boleh kosong',
            'ids.array' => 'Format ID rekening tidak valid',
        ]);

        $ids = $request->input('ids', []);
        $deletedCount = 0;

        DB::transaction(function () use ($ids, &$deletedCount) {
            $accs = Account::whereIn('id', $ids)->get();
            foreach ($accs as $acc) {
                $acc->delete();
                $deletedCount++;
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "{$deletedCount} rekening berhasil dihapus",
            'deleted_count' => $deletedCount,
        ]);
    }
}
