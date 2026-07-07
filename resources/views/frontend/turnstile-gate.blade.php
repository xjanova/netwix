{{-- Search human-gate, shown at most once per guest session (TurnstileSearchGate). Managed mode
     usually solves invisibly; on success we flag the session via POST /turnstile/verify and
     continue to the results the visitor asked for. Deliberately standalone — no app layout, no
     @vite — so it stays feather-light for the bots that are its main audience.

     Fail-open fallback: if api.js never loads (Cloudflare unreachable from the client), we submit
     the sentinel token "cf-unreachable" — the server's siteverify call then also fails/times out
     and Turnstile::passes() fails OPEN, matching the existing outage rule. If CF is actually up,
     the sentinel is simply rejected (422) and the widget keeps gating. --}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ตรวจสอบก่อนค้นหา — NetWix</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
             background:linear-gradient(180deg,#0d0913 0%,#0a0710 100%);color:#f5f0e8;
             font-family:'Noto Sans Thai',system-ui,-apple-system,sans-serif;text-align:center}
        .card{max-width:430px;padding:40px 28px}
        .card img{height:34px;margin-bottom:26px}
        h1{font-size:20px;font-weight:700;margin:0 0 8px}
        p{font-size:14px;line-height:1.6;color:rgba(245,240,232,.55);margin:0 0 24px}
        #err{display:none;margin-top:16px;font-size:13px;color:#ff6b81}
    </style>
</head>
<body>
<div class="card">
    <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix">
    <h1>ตรวจสอบก่อนค้นหา</h1>
    <p>ยืนยันสั้น ๆ ว่าคุณไม่ใช่บอท แล้วผลการค้นหาจะแสดงทันที<br>(ครั้งเดียวต่อการเข้าชม — ส่วนใหญ่ผ่านอัตโนมัติ)</p>
    <div class="cf-turnstile" style="display:flex;justify-content:center"
         data-sitekey="{{ \App\Support\Turnstile::siteKey() }}"
         data-theme="dark" data-language="th" data-size="flexible"
         data-callback="nxTsVerified"></div>
    <div id="err">ยืนยันไม่สำเร็จ กรุณาลองใหม่อีกครั้ง</div>
</div>
<script>
    function nxTsVerified(token) {
        fetch(@json(route('turnstile.verify')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
            },
            body: JSON.stringify({'cf-turnstile-response': token}),
        }).then(function (r) {
            if (r.ok) { window.location.replace(@json($target)); return; }
            document.getElementById('err').style.display = 'block';
            if (window.turnstile) window.turnstile.reset();
        }).catch(function () {
            document.getElementById('err').style.display = 'block';
            if (window.turnstile) window.turnstile.reset();
        });
    }
    setTimeout(function () {
        if (!window.turnstile) nxTsVerified('cf-unreachable');
    }, 8000);
</script>
</body>
</html>
