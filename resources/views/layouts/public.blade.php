<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="text-cream antialiased min-h-screen">
    {{-- Same ambient background as the member app, but a guest-safe shell (no profile assumptions). --}}
    <style>
        html { background: #07050c; }
        #nx-dream { position: fixed; inset: 0; z-index: -1; pointer-events: none; display: block; }
    </style>
    <canvas id="nx-dream" aria-hidden="true"></canvas>

    @include('partials.public-nav')

    <main>
        @yield('content')
    </main>

    @include('partials.site-footer')
    @include('partials.cookie-consent')

    @stack('scripts')
    <style>[x-cloak]{display:none !important;}</style>
</body>
</html>
