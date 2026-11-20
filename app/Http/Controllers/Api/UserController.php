<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('assignedCategory')->get();
        $allCategories = ProgramCategory::all()->keyBy('id');

        $result = [];
        foreach ($users as $u) {
            $catsIds = [];
            if (!empty($u->assigned_categories)) {
                $decoded = json_decode($u->assigned_categories, true);
                if (is_array($decoded)) {
                    $catsIds = $decoded;
                } else {
                    $parts = explode(',', $u->assigned_categories);
                    foreach ($parts as $p) {
                        $trimmed = trim($p);
                        if (is_numeric($trimmed)) {
                            $catsIds[] = (int) $trimmed;
                        }
                    }
                }
            } elseif ($u->assigned_category_id) {
                $catsIds = [$u->assigned_category_id];
            }

            $posLabels = [];
            foreach ($catsIds as $cid) {
                if (isset($allCategories[$cid])) {
                    $posLabels[] = "[{$allCategories[$cid]->code}] {$allCategories[$cid]->name}";
                }
            }

            $result[] = [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'access_level' => $u->access_level ?? 'full',
                'assigned_category_id' => $u->assigned_category_id,
                'assigned_categories' => $catsIds,
                'assigned_category_name' => $u->assignedCategory?->name ?? '-',
                'assigned_category_code' => $u->assignedCategory?->code ?? '-',
                'pos_labels' => $posLabels,
                'created_at' => $u->created_at ? $u->created_at->format('Y-m-d') : '-',
            ];
        }

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string',
            'role' => 'nullable|string',
            'assigned_category_id' => 'nullable|integer',
            'assigned_categories' => 'nullable|array',
            'access_level' => 'nullable|string',
        ]);

        $catsJson = null;
        $primaryCatId = $validated['assigned_category_id'] ?? null;
        if (!empty($validated['assigned_categories'])) {
            $catsJson = json_encode($validated['assigned_categories']);
            if (!$primaryCatId) {
                $primaryCatId = $validated['assigned_categories'][0];
            }
        } elseif ($primaryCatId) {
            $catsJson = json_encode([$primaryCatId]);
        }

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => trim($validated['email']),
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? 'admin_keuangan',
            'assigned_category_id' => $primaryCatId,
            'assigned_categories' => $catsJson,
            'access_level' => $validated['access_level'] ?? 'full',
        ]);

        return response()->json([
            'id' => $user->id,
            'message' => "Petugas '{$user->name}' berhasil ditambahkan",
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string',
            'password' => 'nullable|string',
            'role' => 'nullable|string',
            'assigned_category_id' => 'nullable|integer',
            'assigned_categories' => 'nullable|array',
            'access_level' => 'nullable|string',
        ]);

        $catsJson = null;
        $primaryCatId = $validated['assigned_category_id'] ?? null;
        if (!empty($validated['assigned_categories'])) {
            $catsJson = json_encode($validated['assigned_categories']);
            if (!$primaryCatId) {
                $primaryCatId = $validated['assigned_categories'][0];
            }
        } elseif ($primaryCatId) {
            $catsJson = json_encode([$primaryCatId]);
        }

        $data = [
            'name' => trim($validated['name']),
            'role' => $validated['role'] ?? $user->role,
            'assigned_category_id' => $primaryCatId,
            'assigned_categories' => $catsJson,
            'access_level' => $validated['access_level'] ?? $user->access_level,
        ];

        if (!empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        return response()->json([
            'id' => $user->id,
            'message' => "Data petugas '{$user->name}' berhasil diperbarui",
        ]);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(['message' => 'Petugas berhasil dihapus']);
    }
}
