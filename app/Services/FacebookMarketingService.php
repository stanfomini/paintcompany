<?php

namespace App\Services;

use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookMarketingService
{
    protected const GRAPH_API_URL = 'https://graph.facebook.com/v19.0';

    /**
     * Fetch all ads from the configured ad account.
     *
     * @return array<int, array{id: string, name: string, status: string, campaign: array, adset: array}>
     */
    public function fetchAds(): array
    {
        $settings = MessengerSetting::getInstance();

        if (empty($settings->ad_account_id) || empty($settings->page_access_token)) {
            return [];
        }

        $ads = [];
        $url = self::GRAPH_API_URL.'/'.$settings->ad_account_id.'/ads';
        $params = [
            'fields' => 'id,name,status,campaign{id,name},adset{id,name}',
            'access_token' => $settings->page_access_token,
            'limit' => 100,
        ];

        try {
            $response = Http::get($url, $params);

            if (! $response->successful()) {
                Log::warning('FacebookMarketing: Failed to fetch ads', [
                    'status' => $response->status(),
                    'error' => $response->json('error.message', 'Unknown error'),
                ]);

                return [];
            }

            $ads = array_merge($ads, $response->json('data', []));

            $nextUrl = $response->json('paging.next');
            $maxPages = 10;
            $page = 1;

            while ($nextUrl && $page < $maxPages) {
                $response = Http::get($nextUrl);

                if (! $response->successful()) {
                    break;
                }

                $ads = array_merge($ads, $response->json('data', []));
                $nextUrl = $response->json('paging.next');
                $page++;
            }
        } catch (\Throwable $e) {
            Log::error('FacebookMarketing: Exception fetching ads', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $ads;
    }

    /**
     * Sync ads from the Marketing API into local mappings table.
     *
     * @return int Number of ads synced
     */
    public function syncAds(): int
    {
        $ads = $this->fetchAds();

        foreach ($ads as $ad) {
            MessengerAdFlowMapping::updateOrCreate(
                ['facebook_ad_id' => $ad['id']],
                [
                    'facebook_ad_name' => $ad['name'] ?? null,
                    'facebook_campaign_name' => $ad['campaign']['name'] ?? null,
                    'facebook_adset_name' => $ad['adset']['name'] ?? null,
                    'facebook_ad_status' => $ad['status'] ?? null,
                    'last_synced_at' => now(),
                ]
            );
        }

        return count($ads);
    }
}
