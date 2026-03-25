<?php
namespace App\Http\Controllers;

use App\Models\ContentSuggestion;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentSuggestionController extends Controller
{
    public function index(Request $request, int $workspace): JsonResponse
    {
        $suggestions = ContentSuggestion::where('workspace_id', $workspace)
            ->when($request->get('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->get('site_id'), fn($q, $s) => $q->where('site_id', $s))
            ->orderByDesc('opportunity_score')
            ->paginate(20);

        return response()->json($suggestions);
    }

    public function accept(Request $request, int $workspace, ContentSuggestion $suggestion): JsonResponse
    {
        if ($suggestion->workspace_id !== $workspace) abort(403);
        if ($suggestion->status !== 'pending') {
            return response()->json(['message' => 'Suggestion is not in pending state.'], 422);
        }

        $suggestion->update(['status' => 'accepted']);

        return response()->json(['message' => 'Suggestion accepted.', 'suggestion' => $suggestion->fresh()]);
    }

    public function reject(Request $request, int $workspace, ContentSuggestion $suggestion): JsonResponse
    {
        if ($suggestion->workspace_id !== $workspace) abort(403);

        $reason = $request->input('reason', '');
        $suggestion->update(['status' => 'rejected', 'rejected_reason' => $reason]);

        return response()->json(['message' => 'Suggestion rejected.']);
    }
}
