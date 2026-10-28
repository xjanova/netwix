param([ValidateSet('probe','urban','browsec')][string]$Mode = 'probe', [string]$SeriesId = '8457', [string]$Episode = '51')
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
if ($SeriesId -notmatch '^\d{1,20}$' -or $Episode -notmatch '^[1-9]\d{0,3}$') { throw 'Invalid episode' }
$relayNode = (Get-Command node.exe -ErrorAction Stop).Source
$relayArguments = @('--disable-warning=ExperimentalWarning')
if (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.env')) { $relayArguments += '--env-file=.env' }
$relayArguments += (Join-Path $PSScriptRoot 'worker.mjs')
$relayStop = Join-Path $PSScriptRoot 'worker.stop'
if (Test-Path -LiteralPath $relayStop) { Remove-Item -LiteralPath $relayStop }
if ($Mode -eq 'urban') {
    $env:BROWSER_RELAY_HEADLESS = 'false'
    $relayArguments += '--urban-setup'
} elseif ($Mode -eq 'browsec') {
    $env:BROWSER_RELAY_HEADLESS = 'false'
    $relayArguments += '--browsec-setup'
} else {
    $relayArguments += @('--probe', $SeriesId, $Episode)
}
$ErrorActionPreference = 'Continue'   # see run-worker.ps1: native stderr must not end the script
& $relayNode @relayArguments 1>> (Join-Path $PSScriptRoot 'worker.log') 2>> (Join-Path $PSScriptRoot 'worker-error.log')
exit $LASTEXITCODE
