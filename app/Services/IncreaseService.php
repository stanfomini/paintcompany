<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IncreaseService
{
    protected CompanySetting $settings;

    public function __construct()
    {
        $this->settings = CompanySetting::getInstance();
    }

    /**
     * Get the base URL based on the environment setting.
     */
    public function getBaseUrl(): string
    {
        $environment = $this->settings->increase_environment ?? config('increase.environment', 'sandbox');

        return match ($environment) {
            'production' => config('increase.production_url'),
            default => config('increase.sandbox_url'),
        };
    }

    /**
     * Build the OAuth authorization URL.
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'client_id' => config('increase.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);

        return "https://increase.com/oauth/authorize?{$params}";
    }

    /**
     * Exchange an authorization code for an access token.
     *
     * @return array{access_token: string, token_type: string}
     */
    public function exchangeCodeForToken(string $code, string $redirectUri): array
    {
        $response = Http::post("{$this->getBaseUrl()}/oauth/tokens", [
            'grant_type' => 'authorization_code',
            'client_id' => config('increase.client_id'),
            'client_secret' => config('increase.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ])->throw();

        return $response->json();
    }

    /**
     * List all bank accounts.
     *
     * @return array<int, array>
     */
    public function listAccounts(): array
    {
        return $this->client()->get('/accounts')->throw()->json();
    }

    /**
     * Create a check deposit.
     *
     * @return array{id: string, status: string}
     */
    public function createCheckDeposit(
        string $accountId,
        float $amount,
        string $frontImageFileId,
        string $backImageFileId,
    ): array {
        return $this->client()->post('/check_deposits', [
            'account_id' => $accountId,
            'amount' => (int) round($amount * 100),
            'front_image_file_id' => $frontImageFileId,
            'back_image_file_id' => $backImageFileId,
        ])->throw()->json();
    }

    /**
     * Upload a file to Increase.
     *
     * @return array{id: string, purpose: string}
     */
    public function uploadFile(string $filePath, string $purpose): array
    {
        return $this->client()
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post('/files', [
                'purpose' => $purpose,
            ])->throw()->json();
    }

    /**
     * Create an ACH transfer to an external account.
     *
     * @return array{id: string, status: string}
     */
    public function createAchTransfer(
        string $accountId,
        string $routingNumber,
        string $accountNumber,
        int $amountCents,
        string $description,
    ): array {
        return $this->client()->post('/ach_transfers', [
            'account_id' => $accountId,
            'routing_number' => $routingNumber,
            'account_number' => $accountNumber,
            'amount' => $amountCents,
            'statement_descriptor' => $description,
        ])->throw()->json();
    }

    /**
     * Create an internal account-to-account transfer.
     *
     * @return array{id: string, status: string}
     */
    public function createAccountTransfer(
        string $fromAccountId,
        string $toAccountId,
        int $amountCents,
        string $description,
    ): array {
        return $this->client()->post('/account_transfers', [
            'account_id' => $fromAccountId,
            'destination_account_id' => $toAccountId,
            'amount' => $amountCents,
            'description' => $description,
        ])->throw()->json();
    }

    /**
     * Verify webhook signature using HMAC SHA256.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('increase.webhook_secret');

        if (! $secret) {
            throw new RuntimeException('Increase webhook secret is not configured.');
        }

        $computed = hash_hmac('sha256', $payload, $secret);

        return hash_equals($computed, $signature);
    }

    /**
     * Build an authenticated HTTP client for the Increase API.
     */
    protected function client(): PendingRequest
    {
        $token = $this->settings->increase_access_token;

        if (! $token) {
            throw new RuntimeException('Increase access token is not configured.');
        }

        return Http::baseUrl($this->getBaseUrl())
            ->withToken($token)
            ->acceptJson();
    }
}
