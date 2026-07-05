<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="text-cream antialiased">
    {{-- Ambient "dream fibers" background (see nxDreamBg in app.js). Base ink colour on <html> so
         this fixed canvas at z-index:-1 shows through the transparent body — same as layouts.app. --}}
    <style>
        html { background: #07050c; }
        #nx-dream { position: fixed; inset: 0; z-index: -1; pointer-events: none; display: block; }
    </style>
    <canvas id="nx-dream" aria-hidden="true"></canvas>
    @yield('content')
    @include('partials.cookie-consent')
    @stack('scripts')
    <style>[x-cloak]{display:none !important;}</style>
</body>
</html>
