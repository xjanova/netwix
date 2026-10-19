$ErrorActionPreference = 'Stop'
$relayApp = $PSScriptRoot
$relayUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$relayTaskName = 'NetWix BrainX Browser Relay'
$relayExisting = Get-ScheduledTask -TaskName $relayTaskName -ErrorAction SilentlyContinue
if ($relayExisting -and $relayExisting.Description -ne 'NetWix on-demand Chrome relay with Urban VPN') {
    throw 'A task with this name already exists and is not managed by this installer.'
}
$relayRunner = Join-Path $relayApp 'run-worker.ps1'
$relayTaskAction = New-ScheduledTaskAction -Execute "$env:SystemRoot\System32\conhost.exe" `
    -Argument ('--headless powershell.exe -NoProfile -File "'+$relayRunner+'"') -WorkingDirectory $relayApp
$relayTaskPrincipal = New-ScheduledTaskPrincipal -UserId $relayUser -LogonType Interactive -RunLevel Limited
$relayTaskSettings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew -StartWhenAvailable
$relayTaskTrigger = New-ScheduledTaskTrigger -AtLogOn -User $relayUser
Register-ScheduledTask -TaskName $relayTaskName -Action $relayTaskAction -Principal $relayTaskPrincipal `
    -Settings $relayTaskSettings -Trigger $relayTaskTrigger -Description 'NetWix on-demand Chrome relay with Urban VPN' -Force | Out-Null
$relayDesktop = [Environment]::GetFolderPath('Desktop')
$relayShell = New-Object -ComObject WScript.Shell
$relayShortcut = $relayShell.CreateShortcut((Join-Path $relayDesktop 'NetWix Relay.lnk'))
$relayShortcut.TargetPath = "$env:SystemRoot\System32\conhost.exe"
$relayShortcut.Arguments = '--headless powershell.exe -NoProfile -STA -File "'+(Join-Path $relayApp 'app.ps1')+'"'
$relayShortcut.WorkingDirectory = $relayApp
$relayShortcut.IconLocation = "$env:SystemRoot\System32\shell32.dll,18"
$relayShortcut.Description = 'NetWix relay controls: start, stop, test and Urban VPN'
$relayShortcut.WindowStyle = 7
$relayShortcut.Save()
$relayReadme = @'
NetWix Relay - BrainX

Double-click NetWix Relay on this Desktop.

Start Relay: Receive requested episodes from NetWix.
Stop Relay: Finish the current request and stop.
Test Link: Check the series and episode shown in the app without creating a production job.
Urban VPN: Open the isolated Urban control panel. Stop relay before using this button.
Open Folder: Program, private settings and log files.

The worker uses an isolated browser profile with Urban VPN. It never uses your everyday Chrome profile.
Closing the control window keeps the worker running. Disconnecting RDP keeps it running; signing out ends
the interactive session. The task starts again at the next Administrator sign-in.

Application: C:\brainx\netwix-browser-relay
Task Scheduler: NetWix BrainX Browser Relay
Production: https://netwix.online
Do not share .env, .urban-profile or probe-result.json.
'@
Set-Content -LiteralPath (Join-Path $relayDesktop 'NetWix Relay - Readme.txt') -Value $relayReadme -Encoding UTF8
Write-Output ('Desktop shortcut created: '+(Join-Path $relayDesktop 'NetWix Relay.lnk'))
