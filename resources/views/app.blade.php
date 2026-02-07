<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

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
                background-color: hsl(0 0% 100%);
            }

            html.dark {
                background-color: hsl(222 20% 10%);
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- hreflang SEO tags --}}
        @php
            $currentPath = request()->getPathInfo();
            $baseUrl = config('app.url');
            if (str_starts_with($currentPath, '/en')) {
                $svPath = substr($currentPath, 3) ?: '/';
                $enPath = $currentPath;
            } else {
                $svPath = $currentPath;
                $enPath = '/en' . $currentPath;
            }
        @endphp
        <link rel="alternate" hreflang="sv" href="{{ $baseUrl }}{{ $svPath }}" />
        <link rel="alternate" hreflang="en" href="{{ $baseUrl }}{{ $enPath }}" />
        <link rel="alternate" hreflang="x-default" href="{{ $baseUrl }}{{ $svPath }}" />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
