<?php

use App\Models\ClinicPermission;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the landing page has a description, canonical link, social cards and structured data', function () {
    $response = $this->get(route('home'))->assertOk();

    $response
        ->assertSee('<meta name="description" content="'.e(config('seo.description')).'">', false)
        ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
        ->assertSee('<meta property="og:image" content="'.url('/og-image.png').'">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('"@type":"SoftwareApplication"', false)
        ->assertDontSee('noindex');
});

test('app pages ask search engines not to index them', function () {
    $this->get(route('login'))->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);

    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertDontSee('og:image');
});

test('robots.txt keeps crawlers out of the app and points to the sitemap in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee("Disallow: /admin\nDisallow: /api\nDisallow: /docs\nDisallow: /patient-form", false)
        ->assertSee('Sitemap: '.url('/sitemap.xml'), false);
});

test('robots.txt blocks everything outside production', function () {
    $this->get('/robots.txt')->assertOk()->assertSee("User-agent: *\nDisallow: /\n", false);
});

test('the sitemap lists the landing page', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>'.url('/').'</loc>', false);
});

test('the landing page gets its FAQ, demo email and the real permission count', function () {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public/Home')
            ->where('contactEmail', config('seo.contact_email'))
            ->where('permissionCount', ClinicPermission::query()->count())
            ->has('faqs.0', fn ($faq) => $faq->hasAll(['question', 'answer'])))
        ->assertSee('"@type":"FAQPage"', false);
});
