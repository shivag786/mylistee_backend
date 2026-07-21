<?php

namespace App\Http\Controllers;

use App\Enums\BusinessStatus;
use App\Models\Business;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Dynamic XML sitemap listing the public customer pages plus every active
 * business profile, so search engines can discover shops (Phase 1 / SEO — the
 * static public/sitemap.xml can't enumerate businesses).
 *
 * In production the web server serves `/sitemap.xml` from the backend (see
 * DEPLOYMENT.md); `robots.txt` points here. Cached 6h — freshness isn't critical.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $xml = Cache::remember('sitemap.xml', now()->addHours(6), function (): string {
            $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

            $urls = [
                ['loc' => $base.'/', 'priority' => '1.0'],
                ['loc' => $base.'/nearby', 'priority' => '0.8'],
            ];

            Business::query()
                ->where('status', BusinessStatus::Active)
                ->orderBy('id')
                ->get(['slug', 'updated_at'])
                ->each(function (Business $business) use (&$urls, $base): void {
                    $urls[] = [
                        'loc' => $base.'/b/'.$business->slug,
                        'lastmod' => $business->updated_at?->toAtomString(),
                        'priority' => '0.7',
                    ];
                });

            return $this->render($urls);
        });

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * @param  list<array<string, string|null>>  $urls
     */
    private function render(array $urls): string
    {
        $items = '';
        foreach ($urls as $url) {
            $items .= '<url><loc>'.htmlspecialchars((string) $url['loc'], ENT_XML1).'</loc>';
            if (! empty($url['lastmod'])) {
                $items .= '<lastmod>'.$url['lastmod'].'</lastmod>';
            }
            $items .= '<priority>'.$url['priority'].'</priority></url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$items
            .'</urlset>';
    }
}
