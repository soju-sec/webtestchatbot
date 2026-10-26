<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class AiCapabilityController extends Controller
{
    /**
     * Mengembalikan manifest kapabilitas AI (Intent & Endpoint Definitions).
     * Endpoint ini menyajikan definisi aksi/intent untuk AI Intent Parser.
     * Data pribadi/sensitif tidak boleh ditaruh di dalam manifest.
     */
    public function manifest()
    {
        $endpoints = config('ai_registry.endpoints', []);

        $cleanEndpoints = array_map(function ($endpoint) {
            return [
                'action'       => $endpoint['action'] ?? '',
                'method'       => $endpoint['method'] ?? 'GET',
                'path'         => $endpoint['path'] ?? '',
                'description'  => $endpoint['description'] ?? '',
                'params'       => $endpoint['params'] ?? [],
                'current_data' => $endpoint['current_data'] ?? null,
            ];
        }, $endpoints);

        return response()->json([
            'status' => 'success',
            'endpoints' => $cleanEndpoints,
            'support_templates' => config('ai_registry.support_templates', [])
        ]);
    }
}
