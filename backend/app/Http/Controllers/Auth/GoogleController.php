<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\CreditTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    public function callback(Request $request): JsonResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            return response()->json(['message' => 'Google authentication failed.'], 422);
        }

        $user = DB::transaction(function () use ($googleUser) {
            $existing = User::where('google_id', $googleUser->getId())
                ->orWhere('email', $googleUser->getEmail())
                ->first();

            if ($existing) {
                $existing->update([
                    'google_id' => $googleUser->getId(),
                    'avatar'    => $googleUser->getAvatar(),
                    'name'      => $existing->name ?: $googleUser->getName(),
                ]);
                return $existing;
            }

            // New user
            $user = User::create([
                'name'             => $googleUser->getName(),
                'email'            => $googleUser->getEmail(),
                'google_id'        => $googleUser->getId(),
                'avatar'           => $googleUser->getAvatar(),
                'email_verified_at' => now(),
                'password'         => null,
            ]);

            $workspace = Workspace::create([
                'owner_id'        => $user->id,
                'name'            => $googleUser->getName() . "'s Workspace",
                'plan'            => 'starter',
                'credits_balance' => 50,
            ]);

            $workspace->members()->attach($user->id, ['role' => 'owner', 'accepted_at' => now()]);

            CreditTransaction::create([
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
        $workspace = $user->workspaces()->first();

        return response()->json([
            'user'      => $user->only(['id', 'name', 'email', 'avatar', 'timezone']),
            'workspace' => $workspace?->only(['id', 'ulid', 'name', 'plan', 'credits_balance']),
            'token'     => $token,
            'token_type' => 'Bearer',
        ]);
    }
}
