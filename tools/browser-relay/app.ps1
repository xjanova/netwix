param([switch]$Check)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()
$relayRoot = $PSScriptRoot
$relayForm = New-Object System.Windows.Forms.Form
$relayForm.Text = 'NetWix Relay - BrainX'
$relayForm.Size = New-Object System.Drawing.Size(730, 550)
$relayForm.MinimumSize = $relayForm.Size
$relayForm.StartPosition = 'CenterScreen'
$relayForm.BackColor = [Drawing.Color]::FromArgb(21,24,33)
$relayForm.ForeColor = [Drawing.Color]::WhiteSmoke
$relayForm.Font = New-Object Drawing.Font('Segoe UI',10)
$relayPanel = New-Object Windows.Forms.Panel
$relayPanel.Dock = 'Fill'
$relayPanel.BackColor = $relayForm.BackColor
$relayForm.Controls.Add($relayPanel)

function Add-RelayLabel($text,$x,$y,$width,$height) {
    $label = New-Object Windows.Forms.Label
    $label.Text = $text
    $label.Location = New-Object Drawing.Point($x,$y)
    $label.Size = New-Object Drawing.Size($width,$height)
    $relayPanel.Controls.Add($label)
    return $label
}
$relayTitle = Add-RelayLabel 'NetWix Relay' 24 18 620 42
$relayTitle.Font = New-Object Drawing.Font('Segoe UI',22,[Drawing.FontStyle]::Bold)
$relayHint = Add-RelayLabel 'BrainX server | Urban VPN | Every RongYok title viewers request' 26 65 650 26
$relayHint.ForeColor = [Drawing.Color]::Silver
$relayState = Add-RelayLabel 'Checking status...' 26 106 650 32
$relayState.Font = New-Object Drawing.Font('Segoe UI',13,[Drawing.FontStyle]::Bold)
$relayDetails = Add-RelayLabel '' 26 143 650 45

function Add-RelayButton($text,$x,$y,$width,$action) {
    $button = New-Object Windows.Forms.Button
    $button.Text = $text
    $button.Location = New-Object Drawing.Point($x,$y)
    $button.Size = New-Object Drawing.Size($width,40)
    $button.FlatStyle = 'Flat'
    $button.BackColor = [Drawing.Color]::FromArgb(42,47,65)
    $button.ForeColor = [Drawing.Color]::WhiteSmoke
    $button.Add_Click($action)
    $relayPanel.Controls.Add($button)
    return $button
}
$relaySeriesLabel = Add-RelayLabel 'Series ID' 26 198 88 24
$relaySeries = New-Object Windows.Forms.TextBox
$relaySeries.Text = '8457'
$relaySeries.Location = New-Object Drawing.Point(112,194)
$relaySeries.Width = 125
$relayPanel.Controls.Add($relaySeries)
$relayEpisodeLabel = Add-RelayLabel 'Episode' 260 198 85 24
$relayEpisode = New-Object Windows.Forms.TextBox
$relayEpisode.Text = '51'
$relayEpisode.Location = New-Object Drawing.Point(345,194)
$relayEpisode.Width = 70
$relayPanel.Controls.Add($relayEpisode)
$relayLog = New-Object Windows.Forms.TextBox
$relayLog.Multiline = $true
$relayLog.ReadOnly = $true
$relayLog.ScrollBars = 'Vertical'
$relayLog.BackColor = [Drawing.Color]::FromArgb(13,16,24)
$relayLog.ForeColor = [Drawing.Color]::FromArgb(190,208,220)
$relayLog.Font = New-Object Drawing.Font('Consolas',9)
$relayLog.Location = New-Object Drawing.Point(26,308)
$relayLog.Size = New-Object Drawing.Size(660,150)
$relayPanel.Controls.Add($relayLog)
$relayFooter = Add-RelayLabel 'Closing this window keeps the relay running. Stop relay before tests or VPN settings.' 26 471 660 24
$relayFooter.ForeColor = [Drawing.Color]::Silver
$script:relayBusyUntil = [DateTime]::MinValue

function Test-RelayRunning {
    $statusPath = Join-Path $relayRoot 'status.json'
    if (-not (Test-Path -LiteralPath $statusPath)) { return $false }
    try {
        $record = Get-Content -LiteralPath $statusPath -Raw -Encoding UTF8 | ConvertFrom-Json
        $process = Get-Process -Id ([int]$record.pid) -ErrorAction SilentlyContinue
        return [bool]($process -and $process.ProcessName -eq 'node')
    } catch { return $false }
}

function Start-RelayTool([string]$mode) {
    if ((Test-RelayRunning) -or [DateTime]::Now -lt $script:relayBusyUntil) {
        [Windows.Forms.MessageBox]::Show('Stop the current relay or test before opening another session.','NetWix Relay') | Out-Null
        return
    }
    if ($mode -eq 'probe' -and ($relaySeries.Text -notmatch '^\d{1,20}$' -or $relayEpisode.Text -notmatch '^[1-9]\d{0,3}$')) {
        [Windows.Forms.MessageBox]::Show('Enter a numeric series ID and episode number.','NetWix Relay') | Out-Null
        return
    }
    try {
        if ($mode -eq 'start') {
            & (Join-Path $relayRoot 'start-brainx.ps1') | Out-Null
        } else {
            $runner = Join-Path $relayRoot 'run-tool.ps1'
            $arguments = '--headless powershell.exe -NoProfile -File "' + $runner + '" -Mode ' + $mode
            if ($mode -eq 'probe') { $arguments += ' -SeriesId ' + $relaySeries.Text + ' -Episode ' + $relayEpisode.Text }
            Start-Process -FilePath "$env:SystemRoot\System32\conhost.exe" -ArgumentList $arguments -WorkingDirectory $relayRoot -WindowStyle Hidden | Out-Null
        }
        $script:relayBusyUntil = [DateTime]::Now.AddSeconds(5)
    } catch { [Windows.Forms.MessageBox]::Show($_.Exception.Message,'NetWix Relay') | Out-Null }
}
$relayStart = Add-RelayButton 'Start Relay' 26 244 125 { Start-RelayTool 'start' }
$relayStart.BackColor = [Drawing.Color]::FromArgb(89,55,180)
$relayStop = Add-RelayButton 'Stop Relay' 160 244 125 { & (Join-Path $relayRoot 'stop-brainx.ps1') | Out-Null }
$relayTest = Add-RelayButton 'Test Link' 294 244 125 { Start-RelayTool 'probe' }
$relayUrban = Add-RelayButton 'Urban VPN' 428 244 125 { Start-RelayTool 'urban' }
$relayFolder = Add-RelayButton 'Open Folder' 562 244 124 { Start-Process explorer.exe -ArgumentList ('"'+$relayRoot+'"') -WindowStyle Hidden }

function Update-RelayView {
    try {
        $running = Test-RelayRunning
        $relayState.Text = if ($running) {'Running'} else {'Stopped / ready'}
        $relayState.ForeColor = if ($running) {[Drawing.Color]::LightGreen} else {[Drawing.Color]::LightSteelBlue}
        $statusPath = Join-Path $relayRoot 'status.json'
        if (Test-Path -LiteralPath $statusPath) {
            $record = Get-Content -LiteralPath $statusPath -Raw -Encoding UTF8 | ConvertFrom-Json
            $labels = @{worker_started='Relay started';job_started='Resolving episode';job_completed='Link saved';job_failed='Request needs retry';probe_started='Testing link';probe_success='Link test passed';worker_waiting='Waiting for server';worker_stopped='Stopped';urban_setup_opened='Urban VPN settings open';urban_switched='Blocked - switched VPN location';urban_switch_failed='VPN location switch failed'}
            $eventLabel = if ($labels.ContainsKey([string]$record.event)) {$labels[[string]$record.event]} else {$record.event}
            $eventTime = [DateTimeOffset]::Parse($record.time).ToOffset([TimeSpan]::FromHours(7)).ToString('yyyy-MM-dd HH:mm:ss',[Globalization.CultureInfo]::InvariantCulture)
            $relayDetails.Text = $eventLabel + '   ' + $eventTime + ' ICT'
            if ($record.error) { $relayDetails.Text += "`r`n" + $record.error }
            if ($record.stats) {
                $avg = if ($record.stats.avg_ms) { ([double]$record.stats.avg_ms / 1000).ToString('0.0',[Globalization.CultureInfo]::InvariantCulture) + 's' } else { '-' }
                $relayHint.Text = 'Last 24h: ' + $record.stats.completed + ' links, ' + $record.stats.failed + ' failed, ' + $record.stats.extras + ' bonus | avg ' + $avg + ' | tabs ' + $record.active + '/' + $record.concurrency
                if ($record.urban) { $relayHint.Text += ' | VPN ' + $record.urban }
            }
            if ($record.event -eq 'probe_success') { $relayDetails.Text += "`r`nLink test passed. CDN: " + $record.video_host }
        } else { $relayDetails.Text = 'Use Test Link to check Urban and source access before starting.' }
        $relayLogPath = Join-Path $relayRoot 'worker.log'
        if (Test-Path -LiteralPath $relayLogPath) { $relayLog.Text = (Get-Content -LiteralPath $relayLogPath -Tail 8) -join "`r`n" }
        $relayStart.Enabled = -not $running
        $relayTest.Enabled = -not $running
        $relayUrban.Enabled = -not $running
        $relayStart.BackColor = if ($running) {[Drawing.Color]::LightSlateGray} else {[Drawing.Color]::FromArgb(89,55,180)}
        foreach ($button in @($relayTest,$relayUrban)) {
            $button.BackColor = if ($running) {[Drawing.Color]::LightSlateGray} else {[Drawing.Color]::FromArgb(42,47,65)}
        }
    } catch { $relayDetails.Text = 'Waiting for the next status update...' }
}
$relayTimer = New-Object Windows.Forms.Timer
$relayTimer.Interval = 2000
$relayTimer.Add_Tick({ Update-RelayView })
$relayTimer.Start()
$relayForm.Add_FormClosed({ $relayTimer.Stop(); $relayTimer.Dispose() })
Update-RelayView
if ($Check) {
    $relayTimer.Stop()
    $relayForm.Show()
    [Windows.Forms.Application]::DoEvents()
    $relayForm.Refresh()
    $bitmap = New-Object Drawing.Bitmap($relayPanel.ClientSize.Width,$relayPanel.ClientSize.Height)
    $relayPanel.DrawToBitmap($bitmap,(New-Object Drawing.Rectangle(0,0,$relayPanel.ClientSize.Width,$relayPanel.ClientSize.Height)))
    $bitmap.Save((Join-Path $relayRoot 'app-preview.png'),[Drawing.Imaging.ImageFormat]::Png)
    $bitmap.Dispose()
    $relayForm.Close()
    $relayForm.Dispose()
    Write-Output 'App controls and preview OK'
} else { [void]$relayForm.ShowDialog() }
