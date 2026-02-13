<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Services\IncreaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IncreaseOAuthController extends Controller
{
    public function callback(Request $request): RedirectResponse
    {
        $settings = CompanySetting::getInstance();

        // Validate state parameter
        if ($request->state !== $settings->increase_oauth_state) {
            return redirect()->route('payment-integrations.edit')
                ->with('error', __('Invalid OAuth state. Please try again.'));
        }

        if ($request->has('error')) {
            return redirect()->route('payment-integrations.edit')
                ->with('error', __('Increase connection failed: :error', ['error' => $request->error_description]));
        }

        try {
            $increaseService = app(IncreaseService::class);
            $tokenData = $increaseService->exchangeCodeForToken(
                $request->code,
                route('increase.oauth.callback')
            );

            $settings->update([
                'increase_access_token' => $tokenData['access_token'],
                'increase_oauth_state' => null,
            ]);

            return redirect()->route('payment-integrations.edit')
                ->with('success', __('Successfully connected to Increase!'));
        } catch (\Exception $e) {
            return redirect()->route('payment-integrations.edit')
                ->with('error', __('Failed to connect: :error', ['error' => $e->getMessage()]));
        }
    }
}
