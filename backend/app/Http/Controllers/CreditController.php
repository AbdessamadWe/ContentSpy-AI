<?php
namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function index(Request $request, int $workspace): JsonResponse
    {
        $ws = Workspace::findOrFail($workspace);

        if ($ws->workspace_id ?? $ws->id !== $workspace) {
            // workspace found — proceed
        }

        return response()->json([
            'balance'   => $ws->credits_balance,
            'reserved'  => $ws->credits_reserved,
            'available' => $ws->available_credits,
            'plan'      => $ws->plan,
            'plan_limit' => config("contentspy.plans.{$ws->plan}.credits_per_month"),
        ]);
    }

    public function transactions(Request $request, int $workspace): JsonResponse
    {
        $transactions = CreditTransaction::where('workspace_id', $workspace)
            ->when($request->get('type'), fn($q, $t) => $q->where('type', $t))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($transactions);
    }
}
