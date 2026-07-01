<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="bg-black text-cream antialiased">
    @yield('content')
    @stack('scripts')
    <style>[x-cloak]{display:none !important;}</style>
</body>
</html>
