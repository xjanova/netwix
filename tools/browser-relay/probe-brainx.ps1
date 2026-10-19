param(
    [ValidatePattern('^\d{1,20}$')][string]$SeriesId = '8457',
    [ValidatePattern('^[1-9]\d{0,3}$')][string]$Episode = '51'
)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$relayNode = (Get-Command node.exe -ErrorAction Stop).Source
& $relayNode (Join-Path $PSScriptRoot 'worker.mjs') --probe $SeriesId $Episode
exit $LASTEXITCODE
