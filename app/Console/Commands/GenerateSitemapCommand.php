<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Generate the sitemap.xml file';

    public function handle(): int
    {
        $urls = [
            ['loc' => url('/'), 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['loc' => url('/calculator'), 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['loc' => url('/careers'), 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => url('/terms'), 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['loc' => url('/privacy'), 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['loc' => url('/referral-terms'), 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['loc' => url('/watch'), 'changefreq' => 'weekly', 'priority' => '0.5'],
        ];

        // Placeholder: future area pages from ServiceArea model
        // if (class_exists(\App\Models\ServiceArea::class)) {
        //     foreach (\App\Models\ServiceArea::active()->get() as $area) {
        //         $urls[] = ['loc' => url("/painting/{$area->slug}"), 'changefreq' => 'weekly', 'priority' => '0.7'];
        //     }
        // }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$url['loc']}</loc>\n";
            $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$url['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>'."\n";

        file_put_contents(public_path('sitemap.xml'), $xml);

        $this->info('Sitemap generated with '.count($urls).' URLs.');

        return self::SUCCESS;
    }
}
