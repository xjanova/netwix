$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.env'))) {
    throw 'Create tools/browser-relay/.env from .env.example and set NETWIX_RELAY_TOKEN first.'
}
Get-Command node.exe -ErrorAction Stop | Out-Null
$relayStopFile = Join-Path $PSScriptRoot 'worker.stop'
if (Test-Path -LiteralPath $relayStopFile) { Remove-Item -LiteralPath $relayStopFile }
$relayTask = Get-ScheduledTask -TaskName 'NetWix BrainX Browser Relay' -ErrorAction SilentlyContinue
if ($relayTask) {
    Start-ScheduledTask -TaskName $relayTask.TaskName
    Write-Output 'BrainX relay start requested. See worker.log.'
    return
}
# A hidden PowerShell window alone can still flash Windows Terminal. Give it a headless console.
$relayRunner = Join-Path $PSScriptRoot 'run-worker.ps1'
$relayArguments = '--headless powershell.exe -NoProfile -File "' + $relayRunner + '"'
$relayProcess = Start-Process -FilePath "$env:SystemRoot\System32\conhost.exe" -ArgumentList $relayArguments `
    -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -PassThru
Set-Content -LiteralPath (Join-Path $PSScriptRoot 'launcher.pid') -Value $relayProcess.Id
Write-Output "BrainX relay started (PID $($relayProcess.Id)). See worker.log."
