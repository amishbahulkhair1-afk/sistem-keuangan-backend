<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
        $cats = ProgramCategory::all();
        return response()->json($cats->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'qris_unit_digit' => $c->qris_unit_digit,
            'qris_digit' => $c->qris_unit_digit,
            'category_type' => $c->category_type ?? 'proyek_pembangunan',
            'pic_name' => $c->pic_name,
            'description' => $c->description,
            'is_active' => (bool) $c->is_active,
        ]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:program_categories,code',
            'name' => 'required|string',
            'qris_unit_digit' => 'nullable|string',
            'category_type' => 'nullable|string',
            'pic_name' => 'nullable|string',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $category = ProgramCategory::create($validated);
        return response()->json($category, 201);
    }

    public function update(Request $request, $id)
    {
        $category = ProgramCategory::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string',
            'qris_unit_digit' => 'nullable|string',
            'qris_digit' => 'nullable|string',
            'category_type' => 'nullable|string',
            'pic_name' => 'nullable|string',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if (array_key_exists('qris_digit', $validated) && empty($validated['qris_unit_digit'])) {
            $validated['qris_unit_digit'] = $validated['qris_digit'];
        }
        unset($validated['qris_digit']);

        $category->update($validated);
        return response()->json($category);
    }

    public function destroy($id)
    {
        $category = ProgramCategory::findOrFail($id);
        $category->delete();
        return response()->json(['message' => 'Pos program berhasil dihapus']);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ], [
            'ids.required' => 'Daftar ID pos program tidak boleh kosong',
            'ids.array' => 'Format ID pos program tidak valid',
        ]);

        $ids = $request->input('ids', []);
        $deletedCount = 0;

        DB::transaction(function () use ($ids, &$deletedCount) {
            $cats = ProgramCategory::whereIn('id', $ids)->get();
            foreach ($cats as $cat) {
                $cat->delete();
                $deletedCount++;
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "{$deletedCount} pos program berhasil dihapus",
            'deleted_count' => $deletedCount,
        ]);
    }
}
