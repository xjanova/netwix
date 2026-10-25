# BrainX Chrome relay

## Installed on BrainX server (.250)

Desktop shortcut: **NetWix Relay** (`C:\Users\Administrator\Desktop\NetWix Relay.lnk`).
The program resides at `C:\brainx\netwix-browser-relay`. It has Start Relay, Stop Relay,
Test Link, Urban VPN, Open Folder and **ประวัติย้อนหลัง** (request history) controls. The control
window can close while the worker runs.
The dashboard reflows its six statistics into 6, 3 or 2 equal-width columns as the window
changes size. The request table grows with the window; narrow layouts stack the test/VPN
panel below the main actions. Short windows scroll vertically so every action remains reachable.
Long activity/VPN labels show their full text on hover. History search expands with its window.
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

## Can the relay recover every source?

**Not with the current implementation.** It supports RongYok links anywhere in an episode's
mirror chain, including a title imported from another source that has a RongYok backup.
It does not resolve the other sources themselves. This is enforced on both sides:
`BrowserRelay::descriptor/claim/complete` use RongYok descriptors and its cache/validator;
`resolver.mjs` accepts only RongYok jobs, navigations and episode API responses, and fresh
signed Discord MP4 URLs. Changing only the hostname or removing the allowlist is insufficient.

The following assessment is based on the source adapters in this repository, not live
browser-relay verification of these providers:

| Source / family | Existing playback route | Work required for a relay fallback |
| --- | --- | --- |
| RongYok | Signed Discord MP4 | Already supported; production checks the MP4 outside the VPN before accepting it. |
| Anifume | Signed player iframe, then MP4 | Source-specific page/episode matching, CDN allowlist and expiry handling; verify that the URL works outside the browser session. |
| WowDrama / Goseries4k | Player embed, then HLS | Capture the correct episode's manifest and required Referer; verify manifest, variant and media segments from the serving side. |
| Halim sites / Hd432 / Animeruka | Player-specific HLS (Animeruka can also return MP4) | Separate adapters for the player/API formats, source/CDN allowlists and playback validation. |
| NaayNung | HLS, or Abyss/Hydrax embed fallback | HLS may be transferable after validation; an embed or browser-local `blob:` URL is not a reusable direct video link. |

A shared browser worker can potentially help when the **page/link-resolution request** fails
because of the server's IP or because ordinary browser JavaScript is needed. It does not repair
a removed video, a broken provider API, DRM, or a CDN that requires the browser's cookies or
VPN IP. If only the VPN browser can fetch the media, returning its URL does not solve playback;
transporting the video would be a separate service with very different bandwidth requirements.

For future support, keep normal resolution and mirror rotation first, then opt in a tested
source adapter only on a recoverable miss. Each adapter needs bounded navigation/CDN hosts,
exact episode identity, stream kind/Referer/expiry, independent cache keys, and validation from
the actual playback route. The queue schema currently has no source column and assumes numeric
RongYok series/episode IDs, so multi-source jobs also require a backward-compatible server/worker
protocol and migration. Do not enqueue unsupported providers or claim universal recovery.

## Dashboard layout verification (Windows)

Run `powershell.exe -NoProfile -STA -File .\app.ps1 -Check` from this directory.
It checks live control bounds, card widths, action overlap and scrolling across 30 size/scale
combinations (including simulated 125% and 150% scaling), then saves `app-preview.png`.
It does not start the worker or submit source requests. Inspect the real window as well:
WinForms `DrawToBitmap` does not faithfully render rounded regions and overlapping empty labels.

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

## Version and automatic updates

The version is `package.json` `version`; the worker logs it on every record, and the control
window shows the running one. Releases are GitHub releases tagged `relay-v<version>` with
`netwix-relay-<version>.zip` (`git archive` of this folder) and `SHA256SUMS`, built by
`.github/workflows/relay-release.yml`. They are never marked "Latest" — the NetWix site reads
`releases/latest` for the mobile app.

To release: bump `version` in `package.json` (and the two root entries in `package-lock.json`),
commit, then `git tag relay-v<version> && git push origin relay-v<version>`.

The worker checks the releases API at start and every `BROWSER_RELAY_UPDATE_SECONDS` (default 60;
conditional requests, so an unchanged list costs no rate limit; 10-minute back-off on 403/429).
On a newer version it stops taking work, lets open tabs finish, downloads the archive from GitHub
hosts only, checks SHA-256, unpacks it, and copies the program files over the current ones —
never `.env`, the profiles, `urban-extension`, `node_modules`, `relay.db` or logs. Dependencies are
reinstalled only when `package-lock.json` changed. It then exits with code 75 and
`run-worker.ps1` starts the new version straight away. The replaced files are kept in
`.update/backup-<old>`; if the new version exits with an error within two minutes, `run-worker.ps1`
restores them and marks the version bad (`.update/bad-<version>`) so it is not installed again.
Three successful claims confirm an update. Disable with `BROWSER_RELAY_AUTO_UPDATE=false`.

```powershell
node worker.mjs --version
node worker.mjs --events 60          # last hour of history from relay.db
node worker.mjs --update-now           # install the newest release now (relay stopped)
node worker.mjs --update-now --force   # reinstall it even if already on that version
```

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
record. Signed URLs, cookies and tokens are never stored. Rows older than 30 days are pruned
(at start and once a day); `prune()` never deletes anything younger than 15 days
(`MIN_RETENTION_DAYS`), and `relay.db` is never replaced by a self-update.
`status.json` carries a 24-hour summary that the control window shows.

**ประวัติย้อนหลัง** in the control window lists every request kept: today, 24 hours, 3/7/15 days or
all 30, filtered by result (got a link / failed / blocked) and searched by title, requester or
series number. It reads `relay.db` through `history.mjs`, which only reads (no `status.json`, no
log lines), so it is safe while the worker runs; the newest 5000 rows of a period are listed and
the totals count all of them.

```powershell
node worker.mjs --stats      # last 24 hours as JSON
node worker.mjs --stats 7    # last 7 days
node history.mjs --since 2026-10-06T00:00:00Z   # the requests the history window lists, as JSON
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
