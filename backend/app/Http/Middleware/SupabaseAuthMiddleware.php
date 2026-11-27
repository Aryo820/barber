<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class SupabaseAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null || trim($token) === '') {
            return response()->json([
                'message' => 'Token autentikasi tidak ditemukan.',
            ], 401);
        }

        $url = rtrim((string) config('supabase.url', ''), '/');
        $anonKey = trim((string) config('supabase.anon_key', ''));
        if ($url === '' || $anonKey === '') {
            return response()->json([
                'message' => 'Konfigurasi Supabase Auth belum lengkap.',
            ], 500);
        }

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => "Bearer {$token}",
                    'apikey' => $anonKey,
                ])
                ->get("{$url}/auth/v1/user");
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Layanan autentikasi Supabase sedang tidak dapat dijangkau.',
            ], 503);
        }

        if ($response->serverError()) {
            return response()->json([
                'message' => 'Layanan autentikasi Supabase sedang tidak dapat dijangkau.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Sesi login tidak valid atau telah berakhir.',
            ], 401);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return response()->json([
                'message' => 'Sesi login tidak valid atau telah berakhir.',
            ], 401);
        }

        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return response()->json([
                'message' => 'Sesi login tidak valid atau telah berakhir.',
            ], 401);
        }

        $rawEmail = isset($payload['email']) ? strtolower(trim((string) $payload['email'])) : null;
        $email = ($rawEmail !== null && $rawEmail !== '') ? $rawEmail : null;
        $appMetadata = is_array($payload['app_metadata'] ?? null) ? $payload['app_metadata'] : [];
        $role = $appMetadata['role'] ?? null;

        $adminEmails = array_values(array_filter(
            array_map(
                static fn (mixed $item): string => strtolower(trim((string) $item)),
                (array) config('supabase.admin_emails', []),
            ),
            static fn (string $item): bool => $item !== '',
        ));

        $isAdmin = ($role === 'admin') || ($email !== null && in_array($email, $adminEmails, true));
        if (! $isAdmin) {
            return response()->json([
                'message' => 'Akun ini tidak memiliki izin admin.',
            ], 403);
        }

        $request->attributes->set('supabase_admin', [
            'id' => $id,
            'email' => $email,
            'role' => 'admin',
        ]);

        return $next($request);
    }
}
