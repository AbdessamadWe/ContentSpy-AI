<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    /**
     * GET /api/workspaces
     * List all workspaces for current user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $workspaces = $user->workspaces()
            ->select('id', 'ulid', 'name', 'slug', 'plan', 'credits_balance', 'credits_reserved', 'is_active', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'workspaces' => $workspaces->items(),
            'pagination' => [
                'current_page' => $workspaces->currentPage(),
                'last_page' => $workspaces->lastPage(),
                'per_page' => $workspaces->perPage(),
                'total' => $workspaces->total(),
            ],
        ]);
    }

    /**
     * POST /api/workspaces
     * Create new workspace
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plan' => 'sometimes|in:starter,pro,agency',
        ]);

        $user = $request->user();
        
        // Check plan limits
        $currentCount = $user->workspaces()->count();
        $planLimit = match($user->currentWorkspace?->plan) {
            'starter' => 3,
            'pro' => 15,
            'agency' => PHP_INT_MAX,
            default => 1,
        };

        if ($currentCount >= $planLimit) {
            return response()->json([
                'error' => 'Workspace limit reached for your plan',
                'upgrade_url' => '/billing/upgrade',
            ], 402);
        }

        $workspace = \DB::transaction(function () use ($user, $validated) {
            $workspace = Workspace::create([
                'ulid' => Str::ulid(),
                'owner_id' => $user->id,
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . Str::random(6),
                'plan' => $validated['plan'] ?? 'starter',
                'credits_balance' => 0,
                'credits_reserved' => 0,
                'is_active' => true,
            ]);

            // Add owner to workspace
            $workspace->users()->attach($user->id, [
                'role' => 'owner',
                'accepted_at' => now(),
            ]);

            return $workspace;
        });

        return response()->json([
            'workspace' => [
                'id' => $workspace->ulid,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'plan' => $workspace->plan,
            ],
        ], 201);
    }

    /**
     * GET /api/workspaces/{ulid}
     * Get workspace details
     */
    public function show(Request $request, string $ulid): JsonResponse
    {
        $workspace = $request->user()
            ->workspaces()
            ->where('ulid', $ulid)
            ->firstOrFail();

        return response()->json([
            'workspace' => [
                'id' => $workspace->ulid,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'plan' => $workspace->plan,
                'credits_balance' => $workspace->credits_balance,
                'credits_reserved' => $workspace->credits_reserved,
                'is_active' => $workspace->is_active,
                'white_label' => $workspace->white_label,
                'custom_domain' => $workspace->custom_domain,
                'created_at' => $workspace->created_at,
                'stats' => [
                    'sites_count' => $workspace->sites()->count(),
                    'competitors_count' => $workspace->competitors()->count(),
                    'articles_count' => $workspace->articles()->count(),
                ],
            ],
        ]);
    }

    /**
     * PATCH /api/workspaces/{ulid}
     * Update workspace
     */
    public function update(Request $request, string $ulid): JsonResponse
    {
        $workspace = $request->user()
            ->workspaces()
            ->where('ulid', $ulid)
            ->firstOrFail();

        // Check admin role
        $pivot = $workspace->users()->where('user_id', $request->user()->id)->first();
        if (!in_array($pivot->role, ['owner', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'white_label' => 'sometimes|boolean',
            'custom_domain' => 'sometimes|string|max:255',
            'settings' => 'sometimes|array',
        ]);

        $workspace->update($validated);

        return response()->json([
            'workspace' => [
                'id' => $workspace->ulid,
                'name' => $workspace->name,
                'updated_at' => $workspace->updated_at,
            ],
        ]);
    }

    /**
     * DELETE /api/workspaces/{ulid}
     * Delete workspace (owner only)
     */
    public function destroy(Request $request, string $ulid): JsonResponse
    {
        $workspace = $request->user()
            ->workspaces()
            ->where('ulid', $ulid)
            ->firstOrFail();

        // Check owner role
        $pivot = $workspace->users()->where('user_id', $request->user()->id)->first();
        if ($pivot->role !== 'owner') {
            return response()->json(['error' => 'Only owner can delete workspace'], 403);
        }

        // Soft delete - mark as inactive instead of hard delete
        $workspace->update(['is_active' => false]);

        return response()->json(['message' => 'Workspace deleted']);
    }

    /**
     * POST /api/workspaces/{ulid}/switch
     * Switch current workspace
     */
    public function switch(Request $request, string $ulid): JsonResponse
    {
        $workspace = $request->user()
            ->workspaces()
            ->where('ulid', $ulid)
            ->where('is_active', true)
            ->firstOrFail();

        // Update user's current workspace session
        $request->session()->put('current_workspace_id', $workspace->id);
        $request->user()->update(['current_workspace_id' => $workspace->id]);

        return response()->json([
            'workspace' => [
                'id' => $workspace->ulid,
                'name' => $workspace->name,
                'plan' => $workspace->plan,
            ],
        ]);
    }

    /**
     * POST /api/workspaces/{ulid}/invite
     * Invite user to workspace
     */
    public function invite(Request $request, string $ulid): JsonResponse
    {
        $workspace = $request->user()
            ->workspaces()
            ->where('ulid', $ulid)
            ->firstOrFail();

        // Check admin role
        $pivot = $workspace->users()->where('user_id', $request->user()->id)->first();
        if (!in_array($pivot->role, ['owner', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
            'role' => 'required|in:admin,editor,writer,viewer',
        ]);

        $invitedUser = User::where('email', $validated['email'])->first();
        
        // Check if already member
        if ($workspace->users()->where('user_id', $invitedUser->id)->exists()) {
            return response()->json(['error' => 'User already member'], 400);
        }

        $workspace->users()->attach($invitedUser->id, [
            'role' => $validated['role'],
            'invited_by' => $request->user()->id,
            'accepted_at' => null, // Pending invitation
        ]);

        // Send invitation notification (would use Notification facade)
        
        return response()->json([
            'message' => 'Invitation sent',
            'user' => [
                'email' => $invitedUser->email,
                'name' => $invitedUser->name,
                'role' => $validated['role'],
            ],
        ], 201);
    }
}