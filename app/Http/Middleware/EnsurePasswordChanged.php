<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block POS/business endpoints until an account rotates its initial password.
 *
 * Employee accounts created by an owner/admin start with a password chosen by
 * that owner/admin and `must_change_password = true`. Until the employee sets
 * their own password, every tenant-scoped business endpoint is refused with a
 * stable code so the client can route them to the change-password screen.
 *
 * Authentication, `/api/me`, `/api/current-store`, logout, and
 * `/api/auth/change-password` remain reachable so the user can complete the
 * rotation.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password) {
            return response()->json([
                'message' => 'Anda harus mengganti kata sandi awal sebelum melanjutkan.',
                'code' => 'password_change_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
