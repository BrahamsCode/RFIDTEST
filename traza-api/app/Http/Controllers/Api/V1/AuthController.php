<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Acceso de la aplicación web: sesión con cookie sobre Sanctum, no tokens.
 * Ver `docs/06` §6.
 *
 * El handheld y el borde no pasan por aquí: usan token de dispositivo.
 */
final class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, remember: true)) {
            // Un mensaje genérico: decir cuál de los dos campos falla ayuda
            // a enumerar usuarios válidos.
            return Problem::make(422, 'Credenciales incorrectas',
                'El correo o la contraseña no son correctos.');
        }

        $user = Auth::user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::logout();

            return Problem::forbidden('Esta cuenta está desactivada.');
        }

        $request->session()->regenerate();

        $user?->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'id' => $user?->id,
            'name' => $user?->name,
            'email' => $user?->email,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'ok']);
    }
}
