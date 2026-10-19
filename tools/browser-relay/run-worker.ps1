$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$relayStopFile = Join-Path $PSScriptRoot 'worker.stop'
if (Test-Path -LiteralPath $relayStopFile) { Remove-Item -LiteralPath $relayStopFile }
$relayNode = (Get-Command node.exe -ErrorAction Stop).Source
# Windows PowerShell 5.1 turns a native program's stderr line into an error record. Under 'Stop' the
# first warning (node:sqlite prints one) ended this script and took the worker with it (2026-10-19).
$ErrorActionPreference = 'Continue'
& $relayNode --disable-warning=ExperimentalWarning --env-file=.env (Join-Path $PSScriptRoot 'worker.mjs') 1>> (Join-Path $PSScriptRoot 'worker.log') 2>> (Join-Path $PSScriptRoot 'worker-error.log')
exit $LASTEXITCODE
