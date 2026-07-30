<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            $user?->currentAccessToken()?->delete();

            return new JsonResponse([
                'message' => 'Akun tidak aktif atau sesi sudah tidak berlaku.',
            ], 401);
        }

        if (! $user->tokenCan('mobile')) {
            return new JsonResponse([
                'message' => 'Token ini tidak diizinkan mengakses aplikasi mobile.',
            ], 403);
        }

        return $next($request);
    }
}
