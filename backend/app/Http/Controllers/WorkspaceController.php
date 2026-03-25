<?php
namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    /** List all workspaces the authenticated user belongs to */
    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()
            ->workspaces()
            ->with('owner:id,name,email')
            ->get()
            ->map(fn($ws) => [
                'id'              => $ws->id,
                'ulid'            => $ws->ulid,
                'name'            => $ws->name,
                'slug'            => $ws->slug,
                'plan'            => $ws->plan,
                'credits_balance' => $ws->credits_balance,
                'available_credits' => $ws->available_credits,
                'role'            => $ws->pivot->role,
                'owner'           => $ws->owner->only(['id', 'name', 'email']),
            ]);

        return response()->json(['workspaces' => $workspaces]);
    }

    /** Get current workspace details */
    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json([
            'workspace' => $workspace->load('sites:id,workspace_id,name,url,connection_status'),
        ]);
    }

    /** Update workspace settings */
    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'custom_logo'   => ['nullable', 'url'],
            'settings'      => ['nullable', 'array'],
        ]);

        $workspace->update($validated);

        return response()->json(['workspace' => $workspace->fresh()]);
    }
}
