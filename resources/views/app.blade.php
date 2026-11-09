<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" media="(prefers-color-scheme: light)" content="#fbfaf8">
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#191816">

        @if (request()->is('admin', 'admin/*'))
            {{-- The app itself is private: keep it out of search results. --}}
            <meta name="robots" content="noindex, nofollow">
        @endif

        @isset($seo)
            <meta name="description" content="{{ $seo['description'] }}">
            <link rel="canonical" href="{{ $seo['canonical'] }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:title" content="{{ config('app.name') }}: scheduling and billing for clinics">
            <meta property="og:description" content="{{ $seo['description'] }}">
            <meta property="og:url" content="{{ $seo['canonical'] }}">
            <meta property="og:image" content="{{ $seo['image'] }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
            <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
            <meta name="twitter:card" content="summary_large_image">
            @if ($seo['twitter_site'])
                <meta name="twitter:site" content="{{ $seo['twitter_site'] }}">
            @endif
            <meta name="twitter:title" content="{{ config('app.name') }}: scheduling and billing for clinics">
            <meta name="twitter:description" content="{{ $seo['description'] }}">
            <meta name="twitter:image" content="{{ $seo['image'] }}">
            <script type="application/ld+json">{!! json_encode($seo['json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        @endisset

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
