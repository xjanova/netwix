<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="bg-ink text-cream antialiased">
    @yield('content')
    @stack('scripts')
</body>
</html>
