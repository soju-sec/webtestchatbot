<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KbCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KbCategoryController extends Controller
{
    public function index()
    {
        $categories = KbCategory::withCount('templates')->orderBy('sort_order')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string',
        ]);

        $slug = Str::slug($validated['name']);

        $category = KbCategory::create([
            'name'        => $validated['name'],
            'slug'        => $slug,
            'description' => $validated['description'] ?? null,
            'icon'        => $validated['icon'] ?? 'ri-folder-line',
            'sort_order'  => (KbCategory::max('sort_order') ?? 0) + 1,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Kategori berhasil ditambahkan!',
            'data'    => $category,
        ], 201);
    }
}
