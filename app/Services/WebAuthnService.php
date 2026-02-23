<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Support\Facades\Log;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

class WebAuthnService
{
    private const CHALLENGE_TIMEOUT_SECONDS = 60;

    private const CREDENTIAL_TIMEOUT_MS = 60000;

    /**
     * Generate registration options for credential creation.
     *
     * @return array{
     *     challenge: string,
     *     rp: array{id: string, name: string},
     *     user: array{id: string, name: string, displayName: string},
     *     pubKeyCredParams: array<int, array{type: string, alg: int}>,
     *     excludeCredentials: array<int, array{type: string, id: string, transports: array}>,
     *     authenticatorSelection: array{authenticatorAttachment: string, residentKey: string, userVerification: string},
     *     timeout: int
     * }
     */
    public function generateRegistrationOptions(User $user): array
    {
        $rpId = parse_url(config('app.url'), PHP_URL_HOST);
        $rpName = config('app.name');

        $challenge = random_bytes(32);
        $encodedChallenge = $this->base64urlEncode($challenge);

        session([
            'webauthn_challenge' => $encodedChallenge,
            'webauthn_challenge_expires' => now()->addSeconds(self::CHALLENGE_TIMEOUT_SECONDS),
        ]);

        $excludeCredentials = $user->webauthnCredentials()
            ->get()
            ->map(fn (WebauthnCredential $credential) => [
                'type' => 'public-key',
                'id' => $this->base64urlEncode($credential->credential_id),
                'transports' => $credential->transports ?? [],
            ])
            ->values()
            ->toArray();

        return [
            'challenge' => $encodedChallenge,
            'rp' => [
                'id' => $rpId,
                'name' => $rpName,
            ],
            'user' => [
                'id' => $this->base64urlEncode((string) $user->id),
                'name' => $user->email,
                'displayName' => $user->name,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257],  // RS256
            ],
            'excludeCredentials' => $excludeCredentials,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
            'timeout' => self::CREDENTIAL_TIMEOUT_MS,
        ];
    }

    /**
     * Verify a registration response from the browser and store the credential.
     *
     * @param  array{
     *     id: string,
     *     rawId: string,
     *     type: string,
     *     response: array{clientDataJSON: string, attestationObject: string, transports?: array<string>}
     * }  $data
     */
    public function verifyRegistration(User $user, array $data): WebauthnCredential
    {
        $challenge = session('webauthn_challenge');
        $challengeExpires = session('webauthn_challenge_expires');

        if (! $challenge || ! $challengeExpires) {
            throw new \RuntimeException('No registration challenge found in session.');
        }

        if (now()->isAfter($challengeExpires)) {
            session()->forget(['webauthn_challenge', 'webauthn_challenge_expires']);
            throw new \RuntimeException('Registration challenge has expired.');
        }

        $rpId = parse_url(config('app.url'), PHP_URL_HOST);
        $rpName = config('app.name');

        $rp = PublicKeyCredentialRpEntity::create($rpName, $rpId);
        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->email,
            $this->base64urlDecode($this->base64urlEncode((string) $user->id)),
            $user->name,
        );

        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: $rp,
            user: $userEntity,
            challenge: $this->base64urlDecode($challenge),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(-7),
                PublicKeyCredentialParameters::createPk(-257),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            timeout: self::CREDENTIAL_TIMEOUT_MS,
        );

        $attestationStatementSupportManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport,
        ]);

        $serializer = (new WebauthnSerializerFactory($attestationStatementSupportManager))->create();

        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $serializer->deserialize(
            json_encode($data),
            PublicKeyCredential::class,
            'json'
        );

        $authenticatorAttestationResponse = $publicKeyCredential->response;

        if (! $authenticatorAttestationResponse instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('Invalid response type. Expected attestation response.');
        }

        $ceremonyStepManagerFactory = new CeremonyStepManagerFactory;
        $ceremonyStepManagerFactory->setAllowedOrigins([config('app.url')]);
        $creationCeremony = $ceremonyStepManagerFactory->creationCeremony();

        $attestationValidator = AuthenticatorAttestationResponseValidator::create($creationCeremony);

        $publicKeyCredentialSource = $attestationValidator->check(
            $authenticatorAttestationResponse,
            $creationOptions,
            $rpId,
        );

        $transports = $data['response']['transports'] ?? $authenticatorAttestationResponse->transports;

        $credential = WebauthnCredential::create([
            'credential_id' => $publicKeyCredentialSource->publicKeyCredentialId,
            'user_id' => $user->id,
            'public_key' => Base64UrlSafe::encodeUnpadded($publicKeyCredentialSource->credentialPublicKey),
            'counter' => $publicKeyCredentialSource->counter,
            'device_name' => $data['device_name'] ?? $this->guessDeviceName(),
            'transports' => $transports,
        ]);

        session()->forget(['webauthn_challenge', 'webauthn_challenge_expires']);

        Log::info('WebAuthn credential registered', [
            'user_id' => $user->id,
            'credential_id_prefix' => substr($this->base64urlEncode($credential->credential_id), 0, 16).'...',
        ]);

        return $credential;
    }

    /**
     * Generate authentication options for login.
     *
     * @return array{
     *     challenge: string,
     *     allowCredentials: array<int, array{type: string, id: string, transports: array}>,
     *     timeout: int,
     *     userVerification: string,
     *     rpId: string
     * }
     */
    public function generateAuthenticationOptions(?User $user = null): array
    {
        $rpId = parse_url(config('app.url'), PHP_URL_HOST);

        $challenge = random_bytes(32);
        $encodedChallenge = $this->base64urlEncode($challenge);

        session([
            'webauthn_challenge' => $encodedChallenge,
            'webauthn_challenge_expires' => now()->addSeconds(self::CHALLENGE_TIMEOUT_SECONDS),
        ]);

        $allowCredentials = [];

        if ($user) {
            $allowCredentials = $user->webauthnCredentials()
                ->get()
                ->map(fn (WebauthnCredential $credential) => [
                    'type' => 'public-key',
                    'id' => $this->base64urlEncode($credential->credential_id),
                    'transports' => $credential->transports ?? [],
                ])
                ->values()
                ->toArray();
        }

        return [
            'challenge' => $encodedChallenge,
            'allowCredentials' => $allowCredentials,
            'timeout' => self::CREDENTIAL_TIMEOUT_MS,
            'userVerification' => 'preferred',
            'rpId' => $rpId,
        ];
    }

    /**
     * Verify an authentication (assertion) response and return the authenticated user.
     *
     * @param  array{
     *     id: string,
     *     rawId: string,
     *     type: string,
     *     response: array{clientDataJSON: string, authenticatorData: string, signature: string, userHandle?: string}
     * }  $data
     */
    public function verifyAuthentication(array $data): User
    {
        $challenge = session('webauthn_challenge');
        $challengeExpires = session('webauthn_challenge_expires');

        if (! $challenge || ! $challengeExpires) {
            throw new \RuntimeException('No authentication challenge found in session.');
        }

        if (now()->isAfter($challengeExpires)) {
            session()->forget(['webauthn_challenge', 'webauthn_challenge_expires']);
            throw new \RuntimeException('Authentication challenge has expired.');
        }

        $credentialId = $this->base64urlDecode($data['id']);

        $credential = WebauthnCredential::where('credential_id', $credentialId)->first();

        if (! $credential) {
            throw new \RuntimeException('Credential not found.');
        }

        $rpId = parse_url(config('app.url'), PHP_URL_HOST);

        $publicKeyCredentialSource = PublicKeyCredentialSource::create(
            publicKeyCredentialId: $credential->credential_id,
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: $credential->transports ?? [],
            attestationType: 'none',
            trustPath: new EmptyTrustPath,
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: Base64UrlSafe::decodeNoPadding($credential->public_key),
            userHandle: (string) $credential->user_id,
            counter: $credential->counter,
        );

        $allowCredentials = [
            PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: $credential->credential_id,
                transports: $credential->transports ?? [],
            ),
        ];

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            challenge: $this->base64urlDecode($challenge),
            rpId: $rpId,
            allowCredentials: $allowCredentials,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: self::CREDENTIAL_TIMEOUT_MS,
        );

        $attestationStatementSupportManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport,
        ]);

        $serializer = (new WebauthnSerializerFactory($attestationStatementSupportManager))->create();

        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $serializer->deserialize(
            json_encode($data),
            PublicKeyCredential::class,
            'json'
        );

        $authenticatorAssertionResponse = $publicKeyCredential->response;

        if (! $authenticatorAssertionResponse instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('Invalid response type. Expected assertion response.');
        }

        $ceremonyStepManagerFactory = new CeremonyStepManagerFactory;
        $ceremonyStepManagerFactory->setAllowedOrigins([config('app.url')]);
        $requestCeremony = $ceremonyStepManagerFactory->requestCeremony();

        $assertionValidator = AuthenticatorAssertionResponseValidator::create($requestCeremony);

        $updatedSource = $assertionValidator->check(
            $publicKeyCredentialSource,
            $authenticatorAssertionResponse,
            $requestOptions,
            $rpId,
            (string) $credential->user_id,
        );

        $credential->update([
            'counter' => $updatedSource->counter,
            'last_used_at' => now(),
        ]);

        session()->forget(['webauthn_challenge', 'webauthn_challenge_expires']);

        Log::info('WebAuthn authentication successful', [
            'user_id' => $credential->user_id,
            'credential_id_prefix' => substr($this->base64urlEncode($credential->credential_id), 0, 16).'...',
        ]);

        return $credential->user;
    }

    /**
     * Encode binary data to base64url (URL-safe, no padding).
     */
    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode base64url-encoded data back to binary.
     */
    private function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Attempt to guess a friendly device name from the user agent.
     */
    private function guessDeviceName(): string
    {
        $userAgent = request()->userAgent() ?? '';

        return match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows PC',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown Device',
        };
    }
}
