$ErrorActionPreference = 'Stop'
Set-Content -LiteralPath (Join-Path $PSScriptRoot 'worker.stop') -Value 'stop'
Write-Output 'Stop requested. The worker will finish its current bounded request and exit.'
