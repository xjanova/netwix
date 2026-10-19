$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$relayStopFile = Join-Path $PSScriptRoot 'worker.stop'
if (Test-Path -LiteralPath $relayStopFile) { Remove-Item -LiteralPath $relayStopFile }
$relayNode = (Get-Command node.exe -ErrorAction Stop).Source
& $relayNode --env-file=.env (Join-Path $PSScriptRoot 'worker.mjs') 1>> (Join-Path $PSScriptRoot 'worker.log') 2>> (Join-Path $PSScriptRoot 'worker-error.log')
exit $LASTEXITCODE
