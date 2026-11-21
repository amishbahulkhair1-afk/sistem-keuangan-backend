<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::with('assignedCategory')->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Also allow plain check for legacy testing if hash check fails
            if (!$user || $user->password !== $request->password) {
                return response()->json(['detail' => 'Email atau password salah'], 401);
            }
        }

        $role = $user->role ?? 'admin_keuangan';
        $isAdminOrPimpinan = in_array($role, ['admin', 'admin_keuangan', 'pimpinan']);
        $clientPlatform = $request->header('X-Client-Platform') ?? $request->input('client');

        // Jika login dilakukan dari portal web (desktop atau mobile web), tolak selain admin dan pimpinan
        if ($clientPlatform === 'web' && !$isAdminOrPimpinan) {
            return response()->json([
                'detail' => 'Akses Ditolak: Portal web (desktop & mobile) hanya dapat diakses oleh Admin dan Pimpinan. Petugas lapangan wajib login melalui aplikasi Android (APK Donasi Latee).'
            ], 403);
        }

        $assignedCatsList = [];
        if (!empty($user->assigned_categories)) {
            $decoded = json_decode($user->assigned_categories, true);
            if (is_array($decoded)) {
                $assignedCatsList = $decoded;
            } else {
                $parts = explode(',', $user->assigned_categories);
                foreach ($parts as $p) {
                    $trimmed = trim($p);
                    if (is_numeric($trimmed)) {
                        $assignedCatsList[] = (int) $trimmed;
                    }
                }
            }
        } elseif ($user->assigned_category_id) {
            $assignedCatsList = [$user->assigned_category_id];
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'admin_keuangan',
                'access_level' => $user->access_level ?? 'full',
                'assigned_category_id' => $user->assigned_category_id,
                'assigned_categories' => $assignedCatsList,
                'assigned_category_code' => $user->assignedCategory?->code,
                'assigned_category_name' => $user->assignedCategory?->name,
            ],
        ]);
    }
}
