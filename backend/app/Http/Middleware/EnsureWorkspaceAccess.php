<?php
namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAccess
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $workspaceId = $request->route('workspace') instanceof Workspace
            ? $request->route('workspace')->id
            : (int) $request->route('workspace');

        if (!$workspaceId) {
            return response()->json(['message' => 'Workspace not specified.'], 400);
        }

        $user = $request->user();
        $pivot = $user->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->first()?->pivot;

        if (!$pivot) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (!empty($roles) && !in_array($pivot->role, $roles)) {
            return response()->json(['message' => 'Insufficient role.'], 403);
        }

        // Inject workspace and role into request for downstream use
        $request->merge(['_workspace_id' => $workspaceId, '_workspace_role' => $pivot->role]);

        return $next($request);
    }
}
