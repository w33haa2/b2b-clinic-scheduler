<?php

namespace App\Http\Controllers;

use App\Models\ClinicPermission;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class PublicSiteController extends Controller
{
    /**
     * The landing page. SEO tags are passed to the Blade root view so they are
     * in the HTML even when the SSR server is unavailable.
     */
    public function home(): Response
    {
        $url = url('/');
        $name = config('app.name');
        $description = config('seo.description');
        $faqs = $this->faqs();

        return Inertia::render('public/Home', [
            'contactEmail' => config('seo.contact_email'),
            'permissionCount' => ClinicPermission::query()->count(),
            'faqs' => $faqs,
        ])->withViewData([
            'seo' => [
                'description' => $description,
                'canonical' => $url,
                'image' => url(config('seo.og_image')),
                'image_alt' => "{$name}: the clinic schedule, with sessions colour-coded by staff member",
                'twitter_site' => config('seo.twitter_site'),
                'json_ld' => [
                    '@context' => 'https://schema.org',
                    '@graph' => [
                        [
                            '@type' => 'Organization',
                            '@id' => "{$url}#organization",
                            'name' => $name,
                            'url' => $url,
                            'logo' => url('/apple-touch-icon.png'),
                        ],
                        [
                            '@type' => 'WebSite',
                            '@id' => "{$url}#website",
                            'name' => $name,
                            'url' => $url,
                            'publisher' => ['@id' => "{$url}#organization"],
                        ],
                        [
                            '@type' => 'SoftwareApplication',
                            'name' => $name,
                            'url' => $url,
                            'description' => $description,
                            'applicationCategory' => 'BusinessApplication',
                            'applicationSubCategory' => 'Practice management',
                            'operatingSystem' => 'Web browser',
                            'publisher' => ['@id' => "{$url}#organization"],
                        ],
                        [
                            '@type' => 'FAQPage',
                            'mainEntity' => array_map(fn (array $faq): array => [
                                '@type' => 'Question',
                                'name' => $faq['question'],
                                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
                            ], $faqs),
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Questions shown on the landing page and published as FAQPage structured
     * data. Keep every answer true to what the product does today.
     *
     * @return list<array{question: string, answer: string}>
     */
    private function faqs(): array
    {
        return [
            [
                'question' => 'Can we bring our patients across from our current software?',
                'answer' => 'Yes. Send us an export of your patient list and your services, and we help you load them into your clinic as part of setup.',
            ],
            [
                'question' => 'Can a patient be in a session with others?',
                'answer' => 'Yes. A session can have several patients. Each one gets their own bill, payment status and invoice, so group sessions are as easy to charge for as one-to-ones.',
            ],
            [
                'question' => 'Can we limit what reception or junior staff can do?',
                'answer' => 'Yes. Clinic Admins build roles from individual permissions, such as booking sessions, recording payments or issuing invoices, and give each staff member the roles they need. Staff and role management always stays with Clinic Admins.',
            ],
            [
                'question' => 'What happens to old bills when we change a price?',
                'answer' => 'Nothing. The price is copied onto the bill when a service is billed, so changing your catalogue only affects new bills. Payments and refunds are kept in a ledger that cannot be edited.',
            ],
            [
                'question' => 'We have more than one location. Does that work?',
                'answer' => 'Yes. Each location is its own clinic with its own patients, staff and billing. Staff who work at several locations use one login and switch between them.',
            ],
            [
                'question' => 'Does it work on tablets and phones?',
                'answer' => 'Yes. Clinic App runs in the browser, so reception desks, tablets and phones all use the same up-to-date schedule. There is nothing to install.',
            ],
            [
                'question' => 'How much does it cost?',
                'answer' => 'Pricing depends on the size of your clinic. Book a demo and we will give you a quote with your setup included.',
            ],
        ];
    }

    /**
     * robots.txt: keep crawlers out of the app, the API and the API docs.
     * Outside production everything is disallowed so staging is never indexed.
     */
    public function robots(): HttpResponse
    {
        $lines = app()->isProduction()
            ? ['User-agent: *', 'Disallow: /admin', 'Disallow: /api', 'Disallow: /docs', '', 'Sitemap: '.url('/sitemap.xml')]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * sitemap.xml with the public pages.
     */
    public function sitemap(): HttpResponse
    {
        $urls = collect([route('home')])
            ->map(fn (string $url): string => '  <url><loc>'.e($url).'</loc><changefreq>monthly</changefreq><priority>1.0</priority></url>')
            ->implode("\n");

        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            {$urls}
            </urlset>
            XML;

        return response($xml."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
