<?php

namespace App\Http\Controllers;

use App\Services\Gmail\GmailOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GmailAuthController extends Controller
{
    public function __construct(
        protected GmailOAuthService $oauthService,
    ) {}

    /**
     * Redirect to Google OAuth consent screen.
     */
    public function redirect(Request $request): RedirectResponse
    {
        // Check if Google OAuth is configured
        if (empty(config('gmail.client_id')) || empty(config('gmail.client_secret'))) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'Gmail integration is not configured. Please add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to your .env file. Get these from the Google Cloud Console.');
        }

        // Generate a state token for CSRF protection
        $state = Str::random(40);
        $request->session()->put('gmail_oauth_state', $state);

        $authUrl = $this->oauthService->getAuthorizationUrl($state);

        return redirect()->away($authUrl);
    }

    /**
     * Handle the OAuth callback from Google.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Check for errors from Google
        if ($request->has('error')) {
            Log::error('Gmail OAuth error', [
                'error' => $request->get('error'),
                'description' => $request->get('error_description'),
            ]);

            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'Failed to connect Gmail: '.$request->get('error_description', $request->get('error')));
        }

        // Verify state token
        $state = $request->session()->pull('gmail_oauth_state');
        if (! $state || $state !== $request->get('state')) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'Invalid OAuth state. Please try again.');
        }

        // Check for authorization code
        $code = $request->get('code');
        if (! $code) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'No authorization code received. Please try again.');
        }

        try {
            // Exchange code for tokens and create connection
            $connection = $this->oauthService->handleCallback(Auth::user(), $code);

            return redirect()
                ->route('email-discovery.index')
                ->with('success', 'Gmail connected successfully as '.$connection->email);

        } catch (\Exception $e) {
            Log::error('Gmail OAuth callback error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'Failed to connect Gmail: '.$e->getMessage());
        }
    }

    /**
     * Disconnect Gmail.
     */
    public function disconnect(): RedirectResponse
    {
        $connection = Auth::user()->gmailConnection;

        if (! $connection) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'No Gmail connection found.');
        }

        try {
            $this->oauthService->disconnect($connection);

            return redirect()
                ->route('email-discovery.index')
                ->with('success', 'Gmail disconnected successfully.');

        } catch (\Exception $e) {
            Log::error('Gmail disconnect error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'Failed to disconnect Gmail: '.$e->getMessage());
        }
    }

    /**
     * Get Gmail connection status.
     */
    public function status(): JsonResponse
    {
        $user = Auth::user();
        $connection = $user->gmailConnection;

        if (! $connection) {
            return response()->json([
                'connected' => false,
            ]);
        }

        return response()->json([
            'connected' => true,
            'email' => $connection->email,
            'status' => $connection->status,
            'last_sync_at' => $connection->last_sync_at?->toISOString(),
            'is_expired' => $connection->isExpired(),
            'needs_refresh' => $connection->needsRefresh(),
        ]);
    }
}
