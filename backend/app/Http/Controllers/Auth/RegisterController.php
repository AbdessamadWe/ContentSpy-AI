<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'workspace_name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            $workspace = Workspace::create([
                'owner_id' => $user->id,
                'name'     => $validated['workspace_name'],
                'plan'     => 'starter',
                'credits_balance' => 50, // welcome credits
            ]);

            // Add owner to workspace_users pivot
            $workspace->members()->attach($user->id, ['role' => 'owner', 'accepted_at' => now()]);

            // Grant welcome credits
            \App\Models\CreditTransaction::create([
                'workspace_id'  => $workspace->id,
                'user_id'       => $user->id,
                'type'          => 'plan_grant',
                'amount'        => 50,
                'balance_after' => 50,
                'description'   => 'Welcome credits',
            ]);

            return $user;
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $user->only(['id', 'name', 'email', 'timezone']),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }
}
