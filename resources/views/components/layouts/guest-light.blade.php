<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <script>
            (function(){var h=document.documentElement;h.classList.remove('dark');new MutationObserver(function(){h.classList.contains('dark')&&h.classList.remove('dark')}).observe(h,{attributes:true,attributeFilter:['class']})})();
        </script>
        @isset($structuredData)
        <script type="application/ld+json">
        {!! $structuredData !!}
        </script>
        @endisset
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased">
        <div class="min-h-screen flex flex-col">
            <main class="flex-1">
                {{ $slot }}
            </main>
        </div>
        @fluxScripts
        @include('partials.messenger-widget')
    </body>
</html>
