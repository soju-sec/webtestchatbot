<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class AiCapabilityController extends Controller
{
    public function manifest()
    {
        return response()->json([
            'status' => 'success',
            'endpoints' => config('ai_registry.endpoints', [])
        ]);
    }
}
