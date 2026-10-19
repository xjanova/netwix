# BrainX Chrome relay

## Installed on BrainX server (.250)

Desktop shortcut: **NetWix Relay** (`C:\Users\Administrator\Desktop\NetWix Relay.lnk`).
The program resides at `C:\brainx\netwix-browser-relay`. It has Start Relay, Stop Relay,
Test Link, Urban VPN and Open Folder controls. The control window can close while the worker runs.
The task **NetWix BrainX Browser Relay** keeps the worker detached from SSH and starts it at
Administrator sign-in. RDP disconnect is supported; signing out ends that interactive session.

Urban is isolated in `.urban-profile` and `urban-extension`, copied only from the owner's installed
Urban extension and its own storage. No everyday Chrome cookies/history/passwords were copied.
Urban uses bundled Chromium because current branded Chrome no longer supports the sideload flags.
Urban's normal panel opens in this isolated browser to initialize its saved connection. The worker
requires an Urban-controlled active proxy before navigating to an episode. No consent or terms
buttons are clicked automatically. Stop the worker before opening the visible Urban settings.
Do not share `.env`, `.urban-profile` or diagnostic `probe-result.json`.

Verified on 2026-10-19 at 10:03–10:12 ICT: BrainX+Urban resolved series8457 ep51; production outside
the VPN fetched HTTP206/video/mp4 and a valid 1KB MP4 header. The production relay was deployed and
enabled. Diagnostic job1/episode394400 was explicitly queued by the administrator to exercise the
handoff, claimed by the scheduled worker, and completed on attempt1. Public episode source API then
returned HTTP200/ready/mp4/CDN. The ordinary source request before this test already returned200,
so the test did not simulate an actual customer failure or disable working proxies globally.

Direct Chrome WITHOUT Urban had returned403 earlier; those historical measurements do not apply
to the verified Urban route. The working route depends on Urban continuing to connect successfully.

NetWix queues only a requested RongYok episode that the normal source resolver could not resolve.
The BrainX worker polls over HTTPS, opens that episode in its own Chrome profile, and returns a
signed MP4 URL. Viewers then stream from the CDN directly. BrainX does not proxy video bytes.

## Production setup

1. Deploy the Laravel changes and run `php artisan migrate --force`.
2. Generate a private random token (for example, `openssl rand -hex 32`). Set production variables:

   ```dotenv
   RONGYOK_BROWSER_RELAY_ENABLED=true
   RONGYOK_BROWSER_RELAY_TOKEN=<the-random-token>
   ```

3. Run `php artisan config:cache`. Until enabled with a token of at least 32 characters,
   the relay adds no work or database calls to playback.

## BrainX setup (Windows, Node 22+, Google Chrome installed)

In this directory:

```powershell
npm.cmd ci --registry=https://registry.npmjs.org
Copy-Item .env.example .env
```

Edit `.env` and set `NETWIX_RELAY_TOKEN` to the same production token. Keep this file private.
Set `NETWIX_RELAY_URL` to the production site's HTTPS origin. Run:

```powershell
# A real Chrome check that does not contact or change NetWix:
npm.cmd run probe
# Or: .\probe-brainx.ps1 -SeriesId 8457 -Episode 51

# Run continuously in the current terminal (Ctrl+C to stop):
npm.cmd start

# Or launch with a hidden console and log files:
.\start-brainx.ps1
```

The hidden-console launcher writes `launcher.pid` for the console host when no managed task exists. Stop the worker gracefully
with `.\stop-brainx.ps1`; it finishes the current bounded request and exits. The launcher clears
the stop marker on the next start (remove `worker.stop` yourself before using `npm.cmd start`).
The launcher uses `conhost --headless` because a hidden PowerShell window alone can flash Terminal.
`install-desktop.ps1` creates the desktop shortcut and managed sign-in task. It does not replace
unrelated scheduled tasks or re-enable the retired home relay recovery task.

The default is visible Chrome with a separate `.chrome-profile` directory. It never uses your
everyday Chrome profile. Do not run two processes against this directory. Keep BrainX and its
desktop session running. If an interactive source challenge appears, the worker reports failure;
it does not solve CAPTCHA or change browser security protections. An unblocked IP alone does not
guarantee future access. Test this dedicated profile on BrainX before enabling production.

## Behavior and recovery

- Work is created after the normal resolver misses, behind the existing publication, Pro and VIP
  checks. Other sources and episodes with working links do not enqueue work.
- One row per requested episode and a 120-second lease prevent duplicate concurrent work.
- A worker uses up to `BROWSER_RELAY_CONCURRENCY` episode tabs, never clicks advertisements or the play overlay, and
  aborts media requests. The response must match the requested series and episode; prefetched
  next-episode responses are ignored. `video.src` is the fallback.
- Only validated HTTPS Discord attachment MP4 URLs are accepted. NetWix separately checks a
  1 KB range for an MP4 header. Signed URLs stay cached until five minutes before `ex` expiry.
- The player already polls while preparing. Once the result is stored, its next poll receives the
  URL. Web players stop waiting after two minutes or when the job enters cooldown, and offer a
  manual retry. Web and mobile share the same resolver. Existing playback rights are checked on every poll.
- A lease lost during a crash can be reclaimed. At most three attempts occur before a 10-minute
  cooldown. Requests older than 10 minutes are not opened unless a viewer asks again.
- Every title with a RongYok link is covered — RongYok imports AND titles where RongYok is only a
  mirror/backup (`BrowserRelay::descriptor` walks `MirrorRotation::chain`).
- Work is queued only while the worker is online (a claim within 3 minutes). With the worker
  stopped, playback stays on the existing paths instead of making viewers wait.
- While an episode is queued/running, player polls answer straight from the job row without
  re-walking the rotation, so web players poll every 3 s and add no upstream requests.
- Next-episode prefetch: when a viewer needs the relay (or plays a relay-cached link), the next
  two episodes of that title are queued at priority 1, behind every viewer request (priority 0).
  At most once per episode per minute; never a catalogue crawl. A viewer asking for a prefetched
  episode promotes it to priority 0.
- Episode pages often fetch the next episode too. Up to three such links for the SAME series are
  sent as `extras`, range-checked like the main link, and cached — matching queued jobs become ready.
- `BROWSER_RELAY_CONCURRENCY` (default 2, max 4) episode tabs run in parallel in one browser. A
  `source_blocked` pauses new tabs for 30 s; a broken browser restarts once no tab still uses it.
- Only polling/requests keep jobs current; there is no catalogue prefetch or bulk scraping.

## Automatic Urban location switch

When RongYok blocks the current exit (`source_blocked`), or three requests in a row fail without a
block page (`timeout`/`no_video`), the worker immediately picks another location in Urban's own
panel — the location selector and one listed location, nothing else (no upsell, rating or consent
buttons) — and waits until Urban reports the new connection and controls the proxy again. The
failed job is already back in the queue (5 s for a waiting viewer), so the next claim runs on the
new exit. The switch is single-flight (tabs that hit the same block share it) and at most one
per 20 s. A blocked location rests 30 minutes; a location that does not connect rests too, and up
to three locations are tried per switch. When everything rests, the round starts again.

Order: `BROWSER_RELAY_URBAN_LOCATIONS` (comma-separated names exactly as Urban lists them), else
US, Germany, UK, Singapore, Japan, Netherlands, France, Canada, Hong Kong, Australia, then any
other listed location. Every switch is logged (`urban_switched` / `urban_switch_failed`, also in
`relay.db`), and the control window shows the current location.

Manual (stop the relay first; it shares the browser profile):

```powershell
node worker.mjs --urban-switch              # next location
node worker.mjs --urban-switch "Germany"    # a named one
```

Verified on BrainX 2026-10-19: US → Germany switched, a probe through Germany returned a CDN MP4
in 11.5 s, and switching back to US worked.

## Local history (SQLite)

The worker records its work in `relay.db` (SQLite through Node's built-in `node:sqlite`; Node
22.13+ needs no flag). Table `jobs`: job id, series/episode, priority, start/finish time, duration,
outcome, error code, CDN host, link expiry and extras found/accepted. Table `events`: every log
record. Signed URLs, cookies and tokens are never stored. Rows older than 30 days are pruned.
`status.json` carries a 24-hour summary that the control window shows.

```powershell
node worker.mjs --stats      # last 24 hours as JSON
node worker.mjs --stats 7    # last 7 days
```

Without `node:sqlite` the worker still runs and logs `sqlite: false`; `worker.log` keeps everything.
- Worker API routes require the shared bearer token. BrainX makes outbound requests only and
  does not need an inbound port or a public Chrome debugging port.
- Logs contain job IDs and fixed error codes, not signed video URLs, cookies or tokens.
  `browser_relay_jobs` holds attempt/lease status and `last_error`; the ordinary NetWix cache holds
  the playable URL. A worker claim updates `browser-relay:heartbeat` (five-minute TTL).

Disable with `RONGYOK_BROWSER_RELAY_ENABLED=false`, rebuild config, and stop the worker.
Other playback paths remain available. Existing assisted cache entries can expire normally.

## Verification

```powershell
npm.cmd test
# From the repository root, with a PHP runtime that has mbstring and pdo_sqlite:
php vendor/phpunit/phpunit/phpunit --filter 'BrowserRelayTest|RongYokClientResolutionTest'
```

### Historical checks before Urban integration

Local Chrome smoke test on 2026-10-19 (Asia/Bangkok): series `8457`, episode `51` successfully
resolved a Discord CDN MP4, expiring at `2026-10-19 14:09:22 +07:00`. This does not establish that
production has been deployed, or that a different machine/profile will have the same access.

BrainX server installation: `C:\brainx\netwix-browser-relay` on `WIN-8083NJGR9TE` (egress `.250`).
At 09:35 ICT on 2026-10-19, both headed and headless Chrome probes were blocked. A separately
launched ordinary Chrome with an isolated profile also returned HTTP 403 with page title
`ขออภัย คุณถูกบล็อก · โรงหยก`. This is a failed source-access check, not a working relay.
Production was kept disabled at that point. The later Urban probe succeeded as recorded above.
The successful earlier probe ran on development PC `DESKTOP-2797V5R`, not this server.
The server's npm registry was an expired-certificate mirror; installation succeeded with a
per-command `--registry=https://registry.npmjs.org` override. TLS verification stays enabled.
