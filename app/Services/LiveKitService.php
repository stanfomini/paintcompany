<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\IngressServiceClient;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livekit\IngressInput;
use Livekit\ParticipantPermission;
use Livekit\StreamOutput;

class LiveKitService
{
    private readonly string $url;

    private readonly string $apiKey;

    private readonly string $apiSecret;

    public function __construct()
    {
        $this->url = config('services.livekit.url') ?? '';
        $this->apiKey = config('services.livekit.api_key') ?? '';
        $this->apiSecret = config('services.livekit.api_secret') ?? '';
    }

    /**
     * Create a LiveKit room for the given event.
     */
    public function createRoom(LiveEvent $event): void
    {
        $roomClient = $this->getRoomClient();

        $options = new RoomCreateOptions([
            'name' => $event->livekit_room_name,
            'maxParticipants' => $event->max_viewers + 1,
            'metadata' => json_encode([
                'event_id' => $event->id,
                'title' => $event->title,
            ]),
            'emptyTimeout' => 300,
        ]);

        $roomClient->createRoom($options);
    }

    /**
     * Delete a LiveKit room by name. Silently handles errors if room doesn't exist.
     */
    public function deleteRoom(string $roomName): void
    {
        try {
            $roomClient = $this->getRoomClient();
            $roomClient->deleteRoom($roomName);
        } catch (\Throwable $e) {
            Log::warning('LiveKit: Failed to delete room', [
                'room' => $roomName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate a host access token for the given event and user.
     */
    public function generateHostToken(LiveEvent $event, User $user): string
    {
        $tokenOptions = new AccessTokenOptions([
            'identity' => "host-{$user->id}",
            'name' => $user->name,
            'ttl' => 4 * 60 * 60,
        ]);

        $videoGrant = new VideoGrant([
            'roomJoin' => true,
            'room' => $event->livekit_room_name,
            'canPublish' => true,
            'canSubscribe' => true,
        ]);

        $token = new AccessToken($this->apiKey, $this->apiSecret, $tokenOptions);
        $token->setGrant($videoGrant);

        return $token->toJwt();
    }

    /**
     * Generate a viewer access token for the given event.
     */
    public function generateViewerToken(LiveEvent $event, string $viewerName, ?int $userId = null): string
    {
        $identity = $userId
            ? "viewer-{$userId}"
            : 'guest-'.Str::random(8);

        $tokenOptions = new AccessTokenOptions([
            'identity' => $identity,
            'name' => $viewerName,
            'ttl' => 4 * 60 * 60,
        ]);

        $videoGrant = new VideoGrant([
            'roomJoin' => true,
            'room' => $event->livekit_room_name,
            'canPublish' => false,
            'canSubscribe' => true,
        ]);

        $token = new AccessToken($this->apiKey, $this->apiSecret, $tokenOptions);
        $token->setGrant($videoGrant);

        return $token->toJwt();
    }

    /**
     * Generate a guest access token for a remote guest participant.
     * Guests can publish (their camera) and subscribe (to see the stream preview).
     */
    public function generateGuestToken(LiveEvent $event, LiveEventGuest $guest): string
    {
        $tokenOptions = new AccessTokenOptions([
            'identity' => $guest->getLivekitIdentity(),
            'name' => $guest->name,
            'ttl' => 4 * 60 * 60,
        ]);

        $videoGrant = new VideoGrant([
            'roomJoin' => true,
            'room' => $event->livekit_room_name,
            'canPublish' => false,
            'canSubscribe' => true,
        ]);

        $token = new AccessToken($this->apiKey, $this->apiSecret, $tokenOptions);
        $token->setGrant($videoGrant);

        return $token->toJwt();
    }

    /**
     * Generate a co-producer access token for an internal user helping manage the stream.
     */
    public function generateCoproducerToken(LiveEvent $event, User $user): string
    {
        $tokenOptions = new AccessTokenOptions([
            'identity' => "coproducer-{$user->id}",
            'name' => $user->name,
            'ttl' => 4 * 60 * 60,
        ]);

        $videoGrant = new VideoGrant([
            'roomJoin' => true,
            'room' => $event->livekit_room_name,
            'canPublish' => false,
            'canSubscribe' => true,
        ]);

        $token = new AccessToken($this->apiKey, $this->apiSecret, $tokenOptions);
        $token->setGrant($videoGrant);

        return $token->toJwt();
    }

    /**
     * Get the current participant count for a room. Returns 0 if room doesn't exist or service unavailable.
     */
    public function getParticipantCount(string $roomName): int
    {
        try {
            $response = $this->getRoomClient()->listParticipants($roomName);

            return count($response->getParticipants());
        } catch (\Throwable $e) {
            Log::warning('LiveKit: Failed to get participant count', [
                'room' => $roomName,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * List all participants in a room. Returns empty array on error.
     *
     * @return array<int, \Livekit\ParticipantInfo>
     */
    public function listParticipants(string $roomName): array
    {
        try {
            $response = $this->getRoomClient()->listParticipants($roomName);

            return iterator_to_array($response->getParticipants());
        } catch (\Throwable $e) {
            Log::warning('LiveKit: Failed to list participants', [
                'room' => $roomName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Remove a participant from a room (for moderation/kick).
     */
    public function removeParticipant(string $roomName, string $identity): void
    {
        $this->getRoomClient()->removeParticipant($roomName, $identity);
    }

    /**
     * Update a participant's publish/subscribe permissions in a LiveKit room.
     */
    public function updateParticipantPermissions(string $roomName, string $identity, bool $canPublish, bool $canSubscribe = true): void
    {
        $permission = new ParticipantPermission;
        $permission->setCanPublish($canPublish);
        $permission->setCanSubscribe($canSubscribe);
        $permission->setCanPublishData($canPublish);

        $this->getRoomClient()->updateParticipant($roomName, $identity, permission: $permission);

        Log::info('LiveKit: Updated participant permissions', [
            'room' => $roomName,
            'identity' => $identity,
            'canPublish' => $canPublish,
        ]);
    }

    /**
     * Grant publish permissions to a guest (bring on air).
     */
    public function grantPublish(LiveEvent $event, LiveEventGuest $guest): void
    {
        $this->updateParticipantPermissions($event->livekit_room_name, $guest->getLivekitIdentity(), canPublish: true);
    }

    /**
     * Revoke publish permissions from a guest (take off air).
     */
    public function revokePublish(LiveEvent $event, LiveEventGuest $guest): void
    {
        $this->updateParticipantPermissions($event->livekit_room_name, $guest->getLivekitIdentity(), canPublish: false);
    }

    /**
     * Create an RTMP ingress endpoint for the given event.
     *
     * @return array{ingressId: string, url: string, streamKey: string}
     */
    public function createRtmpIngress(LiveEvent $event): array
    {
        $ingress = $this->getIngressClient();

        $result = $ingress->createIngress(
            inputType: IngressInput::RTMP_INPUT,
            name: "RTMP Ingest for {$event->title}",
            roomName: $event->livekit_room_name,
            participantIdentity: 'rtmp-ingest-'.Str::random(6),
            participantName: 'RTMP Source',
        );

        Log::info('LiveKit: RTMP ingress created', [
            'event_id' => $event->id,
            'ingress_id' => $result->getIngressId(),
        ]);

        return [
            'ingressId' => $result->getIngressId(),
            'url' => $result->getUrl(),
            'streamKey' => $result->getStreamKey(),
        ];
    }

    /**
     * Delete an RTMP ingress endpoint.
     */
    public function deleteRtmpIngress(string $ingressId): void
    {
        $this->getIngressClient()->deleteIngress($ingressId);

        Log::info('LiveKit: RTMP ingress deleted', ['ingress_id' => $ingressId]);
    }

    /**
     * Start RTMP egress (simulcast) to an external RTMP URL.
     */
    public function startRtmpEgress(LiveEvent $event, string $rtmpUrl): string
    {
        $output = new StreamOutput;
        $output->setUrls([$rtmpUrl]);
        $output->setProtocol(\Livekit\StreamProtocol::RTMP);

        $result = $this->getEgressClient()->startRoomCompositeEgress(
            roomName: $event->livekit_room_name,
            output: $output,
        );

        $egressId = $result->getEgressId();

        Log::info('LiveKit: RTMP egress started', [
            'event_id' => $event->id,
            'egress_id' => $egressId,
            'rtmp_url' => Str::before($rtmpUrl, '?'),
        ]);

        return $egressId;
    }

    /**
     * Stop an RTMP egress (simulcast).
     */
    public function stopRtmpEgress(string $egressId): void
    {
        $this->getEgressClient()->stopEgress($egressId);

        Log::info('LiveKit: RTMP egress stopped', ['egress_id' => $egressId]);
    }

    /**
     * Create a RoomServiceClient instance with config credentials.
     */
    protected function getRoomClient(): RoomServiceClient
    {
        return new RoomServiceClient(
            $this->url,
            $this->apiKey,
            $this->apiSecret,
        );
    }

    protected function getIngressClient(): IngressServiceClient
    {
        return new IngressServiceClient(
            $this->url,
            $this->apiKey,
            $this->apiSecret,
        );
    }

    protected function getEgressClient(): EgressServiceClient
    {
        return new EgressServiceClient(
            $this->url,
            $this->apiKey,
            $this->apiSecret,
        );
    }
}
