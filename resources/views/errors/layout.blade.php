{{--
    Premium branded error page — DELIBERATELY self-contained: no @vite, no app layout,
    no Setting/DB/Redis calls. It must render even when the app, database or cache is down
    (that is exactly when the 500/503 pages are shown). Only static assets + inline CSS.
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#07050c">
    <title>@yield('code') · NetWix</title>
    <link rel="icon" href="{{ asset('assets/favicon.png') }}" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;500;700;800&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Kanit',system-ui,-apple-system,'Segoe UI',sans-serif;background:#07050c;color:#f4eef7;min-height:100svh;min-height:100vh;overflow:hidden;display:flex;align-items:center;justify-content:center}
        .bg-video{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;opacity:.16;filter:saturate(1.1) blur(1px);z-index:0}
        .vignette{position:fixed;inset:0;z-index:1;background:radial-gradient(120% 120% at 50% 28%,transparent 0%,rgba(7,5,12,.55) 55%,#07050c 100%)}
        .glow{position:fixed;z-index:1;width:60vw;height:60vw;max-width:640px;max-height:640px;left:50%;top:36%;transform:translate(-50%,-50%);background:radial-gradient(circle,rgba(176,38,255,.30),rgba(255,45,85,.10) 45%,transparent 70%);filter:blur(22px);pointer-events:none;animation:pulse 6s ease-in-out infinite}
        @keyframes pulse{0%,100%{opacity:.75;transform:translate(-50%,-50%) scale(1)}50%{opacity:1;transform:translate(-50%,-50%) scale(1.08)}}
        .wrap{position:relative;z-index:2;text-align:center;padding:32px 24px;max-width:560px}
        .logo{height:42px;width:auto;margin:0 auto 30px;opacity:.95;filter:drop-shadow(0 6px 24px rgba(176,38,255,.4))}
        .code{font-size:clamp(84px,22vw,170px);font-weight:800;line-height:.9;letter-spacing:-.04em;background:linear-gradient(135deg,#c66bff 0%,#ff2d55 100%);-webkit-background-clip:text;background-clip:text;color:transparent}
        h1{font-size:clamp(20px,5vw,27px);font-weight:700;margin-top:12px}
        p{margin-top:12px;color:rgba(244,238,247,.62);font-weight:300;font-size:15.5px;line-height:1.65}
        .actions{margin-top:30px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
        .btn{display:inline-flex;align-items:center;gap:8px;padding:13px 26px;border-radius:999px;font-size:14.5px;font-weight:600;text-decoration:none;transition:transform .15s ease,box-shadow .2s ease;border:0;cursor:pointer;font-family:inherit}
        .btn-primary{background:linear-gradient(135deg,#b026ff,#ff2d55);color:#fff;box-shadow:0 10px 30px rgba(176,38,255,.42)}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 14px 38px rgba(176,38,255,.5)}
        .btn-ghost{background:rgba(255,255,255,.07);color:#f4eef7}
        .btn-ghost:hover{background:rgba(255,255,255,.13)}
        @media(prefers-reduced-motion:reduce){.bg-video,.glow{animation:none}}
    </style>
</head>
<body>
    <video class="bg-video" autoplay muted loop playsinline poster="{{ asset('assets/netwix-logo-full.png') }}">
        <source src="{{ asset('assets/logomedia1.mp4') }}" type="video/mp4">
    </video>
    <div class="vignette"></div>
    <div class="glow"></div>
    <div class="wrap">
        <img class="logo" src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" onerror="this.style.display='none'">
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">@yield('actions')</div>
    </div>
</body>
</html>
