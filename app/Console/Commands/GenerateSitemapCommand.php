<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\VideoChannel;
use Illuminate\Console\Command;

class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Generate sitemap.xml with all public pages and service areas';

    public function handle(): int
    {
        $baseUrl = config('app.url');

        $urls = collect();

        // Static pages
        $urls->push(['loc' => $baseUrl, 'changefreq' => 'weekly', 'priority' => '1.0']);
        $urls->push(['loc' => $baseUrl.'/book', 'changefreq' => 'weekly', 'priority' => '0.9']);
        $urls->push(['loc' => $baseUrl.'/areas', 'changefreq' => 'weekly', 'priority' => '0.8']);
        $urls->push(['loc' => $baseUrl.'/calculator', 'changefreq' => 'monthly', 'priority' => '0.8']);
        $urls->push(['loc' => $baseUrl.'/careers', 'changefreq' => 'monthly', 'priority' => '0.6']);
        $urls->push(['loc' => $baseUrl.'/watch', 'changefreq' => 'weekly', 'priority' => '0.5']);
        $urls->push(['loc' => $baseUrl.'/terms', 'changefreq' => 'yearly', 'priority' => '0.3']);
        $urls->push(['loc' => $baseUrl.'/privacy', 'changefreq' => 'yearly', 'priority' => '0.3']);
        $urls->push(['loc' => $baseUrl.'/referral-terms', 'changefreq' => 'yearly', 'priority' => '0.3']);

        // Services index and guides
        $urls->push(['loc' => $baseUrl.'/services', 'changefreq' => 'weekly', 'priority' => '0.8']);
        $urls->push(['loc' => $baseUrl.'/faq', 'changefreq' => 'monthly', 'priority' => '0.7']);
        $urls->push(['loc' => $baseUrl.'/how-it-works', 'changefreq' => 'monthly', 'priority' => '0.7']);
        $urls->push(['loc' => $baseUrl.'/visualize', 'changefreq' => 'monthly', 'priority' => '0.7']);

        // Individual service guide pages (sourced from database)
        $activeServices = Service::active()->orderBy('sort_order')->get();
        foreach ($activeServices as $service) {
            $urls->push([
                'loc' => $baseUrl.'/services/'.$service->slug,
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ]);
        }

        // Dynamic service area pages
        $activeAreas = ServiceArea::active()->orderBy('city')->get();
        $activeAreas->each(function (ServiceArea $area) use ($urls, $baseUrl) {
            $urls->push([
                'loc' => $baseUrl.'/areas/'.$area->slug,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ]);
        });

        // Area + service combo pages
        foreach ($activeAreas as $area) {
            foreach ($activeServices as $service) {
                $urls->push([
                    'loc' => $baseUrl.'/areas/'.$area->slug.'/'.$service->slug,
                    'changefreq' => 'monthly',
                    'priority' => '0.7',
                ]);
            }
        }

        // Watch channels and their videos
        VideoChannel::query()
            ->public()
            ->active()
            ->with('videos')
            ->each(function (VideoChannel $channel) use ($urls, $baseUrl) {
                $urls->push([
                    'loc' => $baseUrl.'/watch/'.$channel->slug,
                    'changefreq' => 'weekly',
                    'priority' => '0.5',
                ]);

                $channel->videos->each(function ($video) use ($urls, $baseUrl, $channel) {
                    $urls->push([
                        'loc' => $baseUrl.'/watch/'.$channel->slug.'/'.$video->id,
                        'changefreq' => 'monthly',
                        'priority' => '0.4',
                    ]);
                });
            });

        // Build XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.htmlspecialchars($url['loc']).'</loc>'."\n";
            $xml .= '    <changefreq>'.$url['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$url['priority'].'</priority>'."\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>'."\n";

        file_put_contents(public_path('sitemap.xml'), $xml);

        $this->info('Sitemap generated with '.$urls->count().' URLs.');

        return self::SUCCESS;
    }
}
