<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Internal-API-Key') ?? $request->bearerToken();
        $expectedKey = env('INTERNAL_API_KEY', config('services.internal_api_key'));

        if (!$expectedKey || $apiKey !== $expectedKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Invalid or missing API key.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
