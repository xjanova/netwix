<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="bg-black text-cream antialiased">
    @yield('content')
    {{-- Guests can watch now — remind them the campaign goodies need an account. --}}
    @include('partials.guest-cta')
    @stack('scripts')
    <style>[x-cloak]{display:none !important;}</style>
</body>
</html>
