<?php

namespace App\Http\Controllers;

use App\Models\MessengerSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookOAuthController extends Controller
{
    protected const GRAPH_API_URL = 'https://graph.facebook.com/v19.0';

    /**
     * Redirect to Facebook OAuth dialog.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $settings = MessengerSetting::getInstance();

        if (empty($settings->app_id) || empty($settings->app_secret)) {
            return redirect()->route('messenger.edit')
                ->with('error', 'Please enter your App ID and App Secret first.');
        }

        $state = csrf_token();
        session(['fb_oauth_state' => $state]);

        $callbackUrl = $request->schemeAndHttpHost().'/settings/messenger/callback';

        $params = http_build_query([
            'client_id' => $settings->app_id,
            'redirect_uri' => $callbackUrl,
            'scope' => 'pages_messaging,pages_manage_metadata,pages_show_list',
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect("https://www.facebook.com/v19.0/dialog/oauth?{$params}");
    }

    /**
     * Handle OAuth callback from Facebook.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('messenger.edit')
                ->with('error', 'Facebook authorization was denied: '.($request->input('error_description', 'Unknown error')));
        }

        $state = $request->input('state');
        if ($state !== session('fb_oauth_state')) {
            return redirect()->route('messenger.edit')
                ->with('error', 'Invalid OAuth state. Please try again.');
        }

        session()->forget('fb_oauth_state');

        $code = $request->input('code');
        if (! $code) {
            return redirect()->route('messenger.edit')
                ->with('error', 'No authorization code received from Facebook.');
        }

        $settings = MessengerSetting::getInstance();
        $callbackUrl = $request->schemeAndHttpHost().'/settings/messenger/callback';

        // Step 1: Exchange code for short-lived user access token
        $tokenResponse = Http::get(self::GRAPH_API_URL.'/oauth/access_token', [
            'client_id' => $settings->app_id,
            'client_secret' => $settings->app_secret,
            'redirect_uri' => $callbackUrl,
            'code' => $code,
        ]);

        if (! $tokenResponse->successful()) {
            Log::error('Facebook OAuth: token exchange failed', ['body' => $tokenResponse->body()]);

            return redirect()->route('messenger.edit')
                ->with('error', 'Failed to exchange authorization code: '.$tokenResponse->json('error.message', 'Unknown error'));
        }

        $shortLivedToken = $tokenResponse->json('access_token');

        // Step 2: Exchange short-lived token for long-lived user token
        $longLivedResponse = Http::get(self::GRAPH_API_URL.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $settings->app_id,
            'client_secret' => $settings->app_secret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (! $longLivedResponse->successful()) {
            Log::error('Facebook OAuth: long-lived token exchange failed', ['body' => $longLivedResponse->body()]);

            return redirect()->route('messenger.edit')
                ->with('error', 'Failed to get long-lived token: '.$longLivedResponse->json('error.message', 'Unknown error'));
        }

        $longLivedUserToken = $longLivedResponse->json('access_token');

        // Step 3: Get pages (page tokens from long-lived user tokens are automatically permanent)
        $pagesResponse = Http::get(self::GRAPH_API_URL.'/me/accounts', [
            'access_token' => $longLivedUserToken,
            'fields' => 'id,name,access_token',
        ]);

        if (! $pagesResponse->successful()) {
            Log::error('Facebook OAuth: failed to get pages', ['body' => $pagesResponse->body()]);

            return redirect()->route('messenger.edit')
                ->with('error', 'Failed to retrieve pages: '.$pagesResponse->json('error.message', 'Unknown error'));
        }

        $pages = $pagesResponse->json('data', []);

        if (empty($pages)) {
            return redirect()->route('messenger.edit')
                ->with('error', 'No Facebook Pages found. Make sure you granted access to your business page.');
        }

        // Auto-select first page (most users have one business page)
        $page = $pages[0];
        $pageId = $page['id'];
        $pageAccessToken = $page['access_token'];
        $pageName = $page['name'];

        // Step 4a: Set up app-level webhook subscription (tells Facebook WHERE to send events)
        $webhookUrl = $request->schemeAndHttpHost().'/webhook/facebook/receive';
        $appToken = $settings->app_id.'|'.$settings->app_secret;
        $appWebhookResponse = Http::post(self::GRAPH_API_URL."/{$settings->app_id}/subscriptions", [
            'access_token' => $appToken,
            'object' => 'page',
            'callback_url' => $webhookUrl,
            'verify_token' => $settings->verify_token,
            'fields' => 'messages,messaging_postbacks,messaging_optins',
        ]);

        if (! $appWebhookResponse->successful()) {
            Log::warning('Facebook OAuth: app webhook subscription failed', ['body' => $appWebhookResponse->body()]);
        }

        // Step 4b: Subscribe the page to receive events (tells Facebook WHICH page events to forward)
        $subscribeResponse = Http::post(self::GRAPH_API_URL."/{$pageId}/subscribed_apps", [
            'access_token' => $pageAccessToken,
            'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins',
        ]);

        $webhookSubscribed = $appWebhookResponse->successful() && $subscribeResponse->successful();
        if (! $subscribeResponse->successful()) {
            Log::warning('Facebook OAuth: page webhook subscription failed', ['body' => $subscribeResponse->body()]);
        }

        // Step 5: Save everything
        $settings->update([
            'page_id' => $pageId,
            'page_access_token' => $pageAccessToken,
            'is_connected' => true,
            'connected_at' => now(),
        ]);

        $message = "Connected to Facebook Page \"{$pageName}\" successfully!";
        if (! $webhookSubscribed) {
            $message .= ' Warning: webhook subscription failed — you may need to set it up manually in the Facebook App Dashboard.';
        }

        return redirect()->route('messenger.edit')
            ->with('success', $message);
    }
}
