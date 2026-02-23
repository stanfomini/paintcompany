<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\WebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebAuthnController extends Controller
{
    public function __construct(private WebAuthnService $webAuthnService) {}

    public function registerOptions(Request $request): JsonResponse
    {
        $options = $this->webAuthnService->generateRegistrationOptions($request->user());

        return response()->json($options);
    }

    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|string',
            'rawId' => 'required|string',
            'type' => 'required|string|in:public-key',
            'response.attestationObject' => 'required|string',
            'response.clientDataJSON' => 'required|string',
        ]);

        try {
            $credential = $this->webAuthnService->verifyRegistration(
                $request->user(),
                $request->all()
            );

            // Update device name if provided
            if ($request->filled('device_name')) {
                $credential->update(['device_name' => $request->input('device_name')]);
            }

            return response()->json([
                'message' => 'Credential registered',
                'credential_id' => $credential->credential_id,
                'device_name' => $credential->device_name,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Registration failed: '.$e->getMessage()], 422);
        }
    }

    public function loginOptions(Request $request): JsonResponse
    {
        $user = null;

        if ($request->filled('email')) {
            $user = User::where('email', $request->input('email'))->first();
        }

        $options = $this->webAuthnService->generateAuthenticationOptions($user);

        return response()->json($options);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|string',
            'rawId' => 'required|string',
            'type' => 'required|string|in:public-key',
            'response.authenticatorData' => 'required|string',
            'response.clientDataJSON' => 'required|string',
            'response.signature' => 'required|string',
        ]);

        try {
            $user = $this->webAuthnService->verifyAuthentication($request->all());

            // Login via appropriate guard
            $guard = match ($user->type) {
                'agent' => 'agent',
                'customer' => 'customer',
                'crew' => 'crew',
                default => 'web',
            };

            Auth::guard($guard)->login($user);
            $request->session()->regenerate();

            return response()->json([
                'redirect' => $this->getRedirectUrl($user),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Authentication failed: '.$e->getMessage()], 401);
        }
    }

    public function destroy(Request $request, string $credentialId): JsonResponse
    {
        $deleted = $request->user()->webauthnCredentials()
            ->where('credential_id', $credentialId)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Credential not found'], 404);
        }

        return response()->json([], 204);
    }

    private function getRedirectUrl(User $user): string
    {
        return match ($user->type) {
            'agent' => '/agent/dashboard',
            'customer' => '/customer/dashboard',
            'crew' => '/crew/dashboard',
            default => '/dashboard',
        };
    }
}
