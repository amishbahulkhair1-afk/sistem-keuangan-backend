<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;

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
        ]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:accounts,code',
            'name' => 'required|string',
            'initial_balance' => 'nullable|numeric',
        ]);

        $initial = $validated['initial_balance'] ?? 0;
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
        ]);

        $account->update($validated);
        return response()->json($account);
    }

    public function destroy($id)
    {
        $account = Account::findOrFail($id);
        $account->delete();
        return response()->json(['message' => 'Rekening berhasil dihapus']);
    }
}
