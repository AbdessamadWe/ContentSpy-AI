<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Social\FacebookOAuthService;
use App\Services\Social\TikTokOAuthService;
use App\Services\Social\PinterestOAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly FacebookOAuthService $facebookService,
        private readonly TikTokOAuthService $tiktokService,
        private readonly PinterestOAuthService $pinterestService,
    ) {}

    /**
     * Redirect to platform OAuth
     * GET /api/social/{platform}/connect
     */
    public function connect(Request $request, string $platform): JsonResponse
    {
        $user = $request->user();
        $siteId = $request->query('site_id');
        
        if (!$siteId) {
            return response()->json(['error' => 'site_id is required'], 400);
        }

        $redirectUrl = match($platform) {
            'facebook' => $this->facebookService->getAuthUrl($user->currentWorkspace->id, (int) $siteId),
            'instagram' => $this->facebookService->getAuthUrl($user->currentWorkspace->id, (int) $siteId),
            'tiktok' => $this->tiktokService->getAuthUrl($user->currentWorkspace->id, (int) $siteId),
            'pinterest' => $this->pinterestService->getAuthUrl($user->currentWorkspace->id, (int) $siteId),
            default => null,
        };

        if (!$redirectUrl) {
            return response()->json(['error' => 'Invalid platform'], 400);
        }

        return response()->json(['redirect_url' => $redirectUrl]);
    }

    /**
     * Handle OAuth callback
     * GET /api/social/{platform}/callback
     */
    public function callback(Request $request, string $platform): JsonResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');
        
        if (!$code || !$state) {
            return response()->json(['error' => 'Missing code or state'], 400);
        }

        // Decode state to get workspace_id and site_id
        $stateData = json_decode(base64_decode($state), true);
        
        if (!$stateData || !isset($stateData['workspace_id'], $stateData['site_id'])) {
            return response()->json(['error' => 'Invalid state'], 400);
        }

        $result = match($platform) {
            'facebook', 'instagram' => $this->facebookService->handleCallback($code, $stateData['workspace_id'], $stateData['site_id']),
            'tiktok' => $this->tiktokService->handleCallback($code, $stateData['workspace_id'], $stateData['site_id']),
            'pinterest' => $this->pinterestService->handleCallback($code, $stateData['workspace_id'], $stateData['site_id']),
            default => null,
        };

        if (!$result) {
            return response()->json(['error' => 'Failed to complete OAuth'], 400);
        }

        return response()->json($result);
    }

    /**
     * Get connected accounts
     * GET /api/social/accounts
     */
    public function accounts(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id');
        
        $query = \App\Models\SocialAccount::where('workspace_id', $request->user()->currentWorkspace->id);
        
        if ($siteId) {
            $query->where('site_id', $siteId);
        }

        $accounts = $query->get()->map(function ($account) {
            return [
                'id' => $account->id,
                'platform' => $account->platform,
                'account_name' => $account->account_name,
                'is_active' => $account->is_active,
                'token_expires_at' => $account->token_expires_at,
                'created_at' => $account->created_at,
            ];
        });

        return response()->json(['accounts' => $accounts]);
    }

    /**
     * Disconnect account
     * DELETE /api/social/accounts/{id}
     */
    public function disconnect(Request $request, int $id): JsonResponse
    {
        $account = \App\Models\SocialAccount::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);
        
        $account->delete();

        return response()->json(['message' => 'Account disconnected']);
    }
}