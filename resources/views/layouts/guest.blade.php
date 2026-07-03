<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="bg-ink text-cream antialiased">
    @yield('content')
    @include('partials.cookie-consent')
    @stack('scripts')
    <style>[x-cloak]{display:none !important;}</style>
</body>
</html>
