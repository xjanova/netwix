$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$relayStopFile = Join-Path $PSScriptRoot 'worker.stop'
if (Test-Path -LiteralPath $relayStopFile) { Remove-Item -LiteralPath $relayStopFile }
$relayNode = (Get-Command node.exe -ErrorAction Stop).Source
$relayLog = Join-Path $PSScriptRoot 'worker.log'
$relayErrors = Join-Path $PSScriptRoot 'worker-error.log'
$relayRollback = Join-Path $PSScriptRoot '.update\rollback.json'
# Windows PowerShell 5.1 turns a native program's stderr line into an error record. Under 'Stop' the
# first warning (node:sqlite prints one) ended this script and took the worker with it (2026-10-19).
$ErrorActionPreference = 'Continue'
while ($true) {
    $relayStarted = Get-Date
    & $relayNode --disable-warning=ExperimentalWarning --env-file=.env (Join-Path $PSScriptRoot 'worker.mjs') 1>> $relayLog 2>> $relayErrors
    $relayExit = $LASTEXITCODE
    if (Test-Path -LiteralPath $relayStopFile) { break }
    # 75 = the worker installed a new version from GitHub: start it.
    if ($relayExit -eq 75) { continue }
    # A version that crashes within two minutes of an update is rolled back to the one before it.
    if ($relayExit -ne 0 -and (Test-Path -LiteralPath $relayRollback) -and ((Get-Date) - $relayStarted).TotalSeconds -lt 120) {
        try {
            $marker = Get-Content -LiteralPath $relayRollback -Raw | ConvertFrom-Json
            if (Test-Path -LiteralPath $marker.backup) {
                Copy-Item -Path (Join-Path $marker.backup '*') -Destination $PSScriptRoot -Recurse -Force
                # Remember the bad version so the restored one does not install it again.
                Set-Content -LiteralPath (Join-Path (Join-Path $PSScriptRoot '.update') ('bad-' + $marker.to)) -Value $marker.at
                Add-Content -LiteralPath $relayLog -Value ('{"time":"' + (Get-Date).ToUniversalTime().ToString('o') + '","event":"update_rolled_back","from":"' + $marker.to + '","to":"' + $marker.from + '"}')
            }
        } catch { }
        Remove-Item -LiteralPath $relayRollback -Force -ErrorAction SilentlyContinue
        continue
    }
    break
}
exit $relayExit
