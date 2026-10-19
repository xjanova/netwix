param([switch]$Check)
# NetWix Relay control window. Saved as UTF-8 WITH BOM: Windows PowerShell 5.1 reads a BOM-less
# file as ANSI and every Thai label would turn into mojibake.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()
$relayRoot = $PSScriptRoot
$relayVersion = try { (Get-Content -LiteralPath (Join-Path $relayRoot 'package.json') -Raw | ConvertFrom-Json).version } catch { '?' }
$relayIct = [TimeSpan]::FromHours(7)
$relayInv = [Globalization.CultureInfo]::InvariantCulture

# ---- look ---------------------------------------------------------------------------------
function New-RelayColor([string]$hex) { [Drawing.ColorTranslator]::FromHtml($hex) }
$C = @{
    Bg = New-RelayColor '#0F1117'; Card = New-RelayColor '#181B24'; Card2 = New-RelayColor '#1F2330'
    Line = New-RelayColor '#2A2F3D'; Text = New-RelayColor '#E8EAF0'; Muted = New-RelayColor '#8B90A0'
    Accent = New-RelayColor '#7C5CFF'; Green = New-RelayColor '#2ECC71'; GreenBg = New-RelayColor '#12301F'
    Red = New-RelayColor '#FF6B6B'; RedBg = New-RelayColor '#3A1A1D'; Amber = New-RelayColor '#FFB020'
    AmberBg = New-RelayColor '#3A2C10'; Grey = New-RelayColor '#5A6070'; GreyBg = New-RelayColor '#23262F'
}
# Leelawadee UI is the Windows Thai UI face; Segoe UI has no Thai glyphs.
$relayFace = if ((New-Object Drawing.Text.InstalledFontCollection).Families.Name -contains 'Leelawadee UI') { 'Leelawadee UI' } else { 'Tahoma' }
function New-RelayFont([float]$size, [Drawing.FontStyle]$style = [Drawing.FontStyle]::Regular) { New-Object Drawing.Font($relayFace, $size, $style) }

$relayForm = New-Object Windows.Forms.Form
# Layout is in 96-DPI pixels; Dpi mode scales the window and every control together on larger screens.
$relayForm.AutoScaleDimensions = New-Object Drawing.SizeF(96, 96)
$relayForm.AutoScaleMode = 'Dpi'
$relayForm.Text = 'NetWix Relay ' + $relayVersion
$relayForm.ClientSize = New-Object Drawing.Size(900, 676)
$relayForm.MinimumSize = $relayForm.Size
$relayForm.StartPosition = 'CenterScreen'
$relayForm.BackColor = $C.Bg
$relayForm.ForeColor = $C.Text
$relayForm.Font = New-RelayFont 10

function Add-RelayLabel($parent, [string]$text, $x, $y, $w, $h, $font = $null, $color = $null, $align = 'MiddleLeft') {
    $label = New-Object Windows.Forms.Label
    $label.Text = $text
    $label.Location = New-Object Drawing.Point($x, $y)
    $label.Size = New-Object Drawing.Size($w, $h)
    $label.TextAlign = $align
    $label.BackColor = [Drawing.Color]::Transparent
    if ($font) { $label.Font = $font }
    $label.ForeColor = if ($color) { $color } else { $C.Text }
    $parent.Controls.Add($label)
    return $label
}
function Add-RelayPanel($parent, $x, $y, $w, $h, $color) {
    $panel = New-Object Windows.Forms.Panel
    $panel.Location = New-Object Drawing.Point($x, $y)
    $panel.Size = New-Object Drawing.Size($w, $h)
    $panel.BackColor = $color
    $parent.Controls.Add($panel)
    return $panel
}
function Add-RelayButton($parent, [string]$text, $x, $y, $w, $h, $back, $action) {
    $button = New-Object Windows.Forms.Button
    $button.Text = $text
    $button.Location = New-Object Drawing.Point($x, $y)
    $button.Size = New-Object Drawing.Size($w, $h)
    $button.FlatStyle = 'Flat'
    $button.FlatAppearance.BorderSize = 0
    $button.BackColor = $back
    $button.ForeColor = $C.Text
    $button.Font = New-RelayFont 10 ([Drawing.FontStyle]::Bold)
    $button.Cursor = [Windows.Forms.Cursors]::Hand
    $button.Add_Click($action)
    $parent.Controls.Add($button)
    return $button
}
$relayTips = New-Object Windows.Forms.ToolTip

# ---- header -------------------------------------------------------------------------------
$relayHeader = Add-RelayPanel $relayForm 0 0 900 86 $C.Card
$null = Add-RelayLabel $relayHeader 'NetWix Relay' 22 12 230 40 (New-RelayFont 20 ([Drawing.FontStyle]::Bold))
$relayBadge = Add-RelayLabel $relayHeader ('v' + $relayVersion) 250 22 70 22 (New-RelayFont 9 ([Drawing.FontStyle]::Bold)) $C.Text 'MiddleCenter'
$relayBadge.BackColor = $C.Accent
$null = Add-RelayLabel $relayHeader 'ขอลิงก์หนังโรงหยกให้ผู้ชมอัตโนมัติ ผ่าน Urban VPN บนเครื่อง BrainX' 24 52 560 24 (New-RelayFont 10) $C.Muted
$relayPill = Add-RelayLabel $relayHeader '' 632 24 244 38 (New-RelayFont 12 ([Drawing.FontStyle]::Bold)) $C.Text 'MiddleCenter'

# ---- stat cards ---------------------------------------------------------------------------
$relayCards = @{}
$relayCaptions = @{}
$relayCardDefs = @(
    @{ Key = 'ok'; Caption = 'ได้ลิงก์ · 24 ชม.' }, @{ Key = 'fail'; Caption = 'ไม่สำเร็จ · 24 ชม.' },
    @{ Key = 'blocks'; Caption = 'โดนบล็อก · 24 ชม.' }, @{ Key = 'switches'; Caption = 'สลับ VPN · 24 ชม.' },
    @{ Key = 'avg'; Caption = 'เวลาเฉลี่ยต่อลิงก์' }, @{ Key = 'vpn'; Caption = 'VPN ตอนนี้' }
)
for ($i = 0; $i -lt $relayCardDefs.Count; $i++) {
    $card = Add-RelayPanel $relayForm (24 + $i * 144) 102 132 88 $C.Card
    $relayCards[$relayCardDefs[$i].Key] = Add-RelayLabel $card '-' 12 6 116 40 (New-RelayFont 20 ([Drawing.FontStyle]::Bold))
    $relayCaptions[$relayCardDefs[$i].Key] = Add-RelayLabel $card $relayCardDefs[$i].Caption 12 46 118 38 (New-RelayFont 9) $C.Muted 'TopLeft'
}

# ---- latest activity ----------------------------------------------------------------------
$relayNow = Add-RelayPanel $relayForm 24 204 852 62 $C.Card
$relayNowBar = Add-RelayPanel $relayNow 0 0 5 62 $C.Accent
$null = Add-RelayLabel $relayNow 'ล่าสุด' 18 6 120 22 (New-RelayFont 9) $C.Muted
$relayNowText = Add-RelayLabel $relayNow 'กำลังอ่านสถานะ...' 18 26 820 30 (New-RelayFont 12 ([Drawing.FontStyle]::Bold))

# ---- recent requests ----------------------------------------------------------------------
$null = Add-RelayLabel $relayForm 'คำขอล่าสุด' 24 278 300 26 (New-RelayFont 12 ([Drawing.FontStyle]::Bold))
$null = Add-RelayLabel $relayForm 'ใคร ขอเรื่องอะไร เมื่อไหร่ และได้ผลยังไง' 140 280 500 24 (New-RelayFont 9) $C.Muted
$relayList = New-Object Windows.Forms.ListView
$relayList.View = 'Details'
$relayList.FullRowSelect = $true
$relayList.HeaderStyle = 'Nonclickable'
$relayList.BorderStyle = 'None'
$relayList.BackColor = $C.Card
$relayList.ForeColor = $C.Text
$relayList.Font = New-RelayFont 10
$relayList.Location = New-Object Drawing.Point(24, 308)
$relayList.Size = New-Object Drawing.Size(852, 210)
$relayList.OwnerDraw = $true
foreach ($col in @(@('เวลา', 92), @('เรื่อง / ตอน', 262), @('ขอโดย', 168), @('ประเภท', 116), @('ผล', 160), @('VPN', 50))) {
    [void]$relayList.Columns.Add($col[0], $col[1])
}
$relayList.Add_Resize({ $relayList.Columns[5].Width = -2 })
$relayList.Add_HandleCreated({ $relayList.Columns[5].Width = -2 })  # last column fills the row (no blank header stub)
# Smooth redraws: ListView.DoubleBuffered is protected.
[Windows.Forms.ListView].GetProperty('DoubleBuffered', [Reflection.BindingFlags]'NonPublic,Instance').SetValue($relayList, $true, $null)
$relayList.Add_DrawColumnHeader({ param($s, $e)
    $fill = $e.Bounds
    if ($e.ColumnIndex -eq $relayList.Columns.Count - 1) { $fill = New-Object Drawing.Rectangle($e.Bounds.X, $e.Bounds.Y, ($relayList.ClientSize.Width - $e.Bounds.X + 40), $e.Bounds.Height) }
    $e.Graphics.FillRectangle((New-Object Drawing.SolidBrush($C.Card2)), $fill)
    $r = New-Object Drawing.Rectangle(($e.Bounds.X + 8), $e.Bounds.Y, ($e.Bounds.Width - 8), $e.Bounds.Height)
    [Windows.Forms.TextRenderer]::DrawText($e.Graphics, $e.Header.Text, (New-RelayFont 9 ([Drawing.FontStyle]::Bold)), $r, $C.Muted, [Windows.Forms.TextFormatFlags]'VerticalCenter,Left')
})
$relayList.Add_DrawItem({ param($s, $e) })
$relayList.Add_DrawSubItem({ param($s, $e)
    $back = if ($e.ItemIndex % 2) { $C.Card } else { $C.Card2 }
    $e.Graphics.FillRectangle((New-Object Drawing.SolidBrush($back)), $e.Bounds)
    $color = $C.Text
    if ($e.ColumnIndex -eq 0 -or $e.ColumnIndex -eq 5) { $color = $C.Muted }
    if ($e.ColumnIndex -eq 4) { $color = if ($e.Item.Tag -eq 'completed') { $C.Green } elseif ($e.Item.Tag -eq 'failed') { $C.Red } else { $C.Amber } }
    $r = New-Object Drawing.Rectangle(($e.Bounds.X + 8), $e.Bounds.Y, ($e.Bounds.Width - 10), $e.Bounds.Height)
    [Windows.Forms.TextRenderer]::DrawText($e.Graphics, $e.SubItem.Text, $relayList.Font, $r, $color, [Windows.Forms.TextFormatFlags]'VerticalCenter,Left,EndEllipsis')
})
# Row height follows the image list; Thai needs the extra room for vowels above and below.
$relayRows = New-Object Windows.Forms.ImageList
$relayRows.ImageSize = New-Object Drawing.Size(1, 30)
$relayList.SmallImageList = $relayRows
$relayForm.Controls.Add($relayList)
$relaySwitches = Add-RelayLabel $relayForm 'สลับ VPN ล่าสุด: ยังไม่เคยสลับ' 24 524 852 28 (New-RelayFont 9) $C.Muted
$relaySwitches.BackColor = $C.Card
$relaySwitches.Padding = New-Object Windows.Forms.Padding(10, 0, 0, 0)
$relayEmpty = Add-RelayLabel $relayForm 'ยังไม่มีคำขอ — เมื่อผู้ชมเปิดเรื่องที่ระบบปกติขอลิงก์ไม่ได้ จะขึ้นที่นี่' 24 410 852 30 (New-RelayFont 10) $C.Muted 'MiddleCenter'
$relayEmpty.BackColor = $C.Card
$relayEmpty.BringToFront()

# ---- controls -----------------------------------------------------------------------------
$script:relayBusyUntil = [DateTime]::MinValue
function Test-RelayRunning {
    if ($env:RELAY_PREVIEW_RUNNING -eq '1') { return $true }
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
        [Windows.Forms.MessageBox]::Show('กรุณาหยุดโปรแกรมก่อน เพราะการทดสอบใช้เบราว์เซอร์ตัวเดียวกัน', 'NetWix Relay') | Out-Null
        return
    }
    if ($mode -eq 'probe' -and ($relaySeries.Text -notmatch '^\d{1,20}$' -or $relayEpisode.Text -notmatch '^[1-9]\d{0,3}$')) {
        [Windows.Forms.MessageBox]::Show('ใส่เลขซีรีส์และเลขตอนเป็นตัวเลข', 'NetWix Relay') | Out-Null
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
    } catch { [Windows.Forms.MessageBox]::Show($_.Exception.Message, 'NetWix Relay') | Out-Null }
}
$relayToggle = Add-RelayButton $relayForm '' 24 566 210 50 $C.Accent {
    if (Test-RelayRunning) { & (Join-Path $relayRoot 'stop-brainx.ps1') | Out-Null; $script:relayBusyUntil = [DateTime]::Now.AddSeconds(5) }
    else { Start-RelayTool 'start' }
}
$relayToggle.Font = New-RelayFont 12 ([Drawing.FontStyle]::Bold)
$relayFolder = Add-RelayButton $relayForm 'เปิดโฟลเดอร์' 244 566 120 50 $C.Card2 { Start-Process explorer.exe -ArgumentList ('"' + $relayRoot + '"') }
$relayLogButton = Add-RelayButton $relayForm 'ดู log' 374 566 90 50 $C.Card2 { Show-RelayLog }

$relayTools = Add-RelayPanel $relayForm 480 560 396 62 $C.Card
$null = Add-RelayLabel $relayTools 'ซีรีส์' 10 6 46 22 (New-RelayFont 9) $C.Muted
$relaySeries = New-Object Windows.Forms.TextBox
$relaySeries.Text = '8457'; $relaySeries.Location = New-Object Drawing.Point(10, 30); $relaySeries.Width = 74
$null = Add-RelayLabel $relayTools 'ตอน' 92 6 40 22 (New-RelayFont 9) $C.Muted
$relayEpisode = New-Object Windows.Forms.TextBox
$relayEpisode.Text = '51'; $relayEpisode.Location = New-Object Drawing.Point(92, 30); $relayEpisode.Width = 44
foreach ($box in @($relaySeries, $relayEpisode)) { $box.BackColor = $C.Card2; $box.ForeColor = $C.Text; $box.BorderStyle = 'FixedSingle'; $relayTools.Controls.Add($box) }
$relayTest = Add-RelayButton $relayTools 'ทดสอบลิงก์' 146 12 118 40 $C.Card2 { Start-RelayTool 'probe' }
$relayUrban = Add-RelayButton $relayTools 'ตั้งค่า VPN' 272 12 114 40 $C.Card2 { Start-RelayTool 'urban' }
$relayTips.SetToolTip($relayTest, 'เปิดตอนที่ระบุผ่าน VPN เพื่อดูว่าขอลิงก์ได้ไหม (ต้องหยุดโปรแกรมก่อน)')
$relayTips.SetToolTip($relayUrban, 'เปิดหน้าตั้งค่า Urban VPN (ต้องหยุดโปรแกรมก่อน)')
$null = Add-RelayLabel $relayForm 'ปิดหน้าต่างนี้ได้ โปรแกรมยังทำงานต่อเบื้องหลัง · อัปเดตเวอร์ชันใหม่ให้อัตโนมัติ · ทดสอบ/ตั้งค่า VPN ต้องหยุดโปรแกรมก่อน' 24 640 852 24 (New-RelayFont 9) $C.Muted

function Show-RelayLog {
    $log = New-Object Windows.Forms.Form
    $log.Text = 'NetWix Relay - log ล่าสุด'; $log.Size = New-Object Drawing.Size(900, 520); $log.StartPosition = 'CenterParent'; $log.BackColor = $C.Bg
    $box = New-Object Windows.Forms.TextBox
    $box.Multiline = $true; $box.ReadOnly = $true; $box.ScrollBars = 'Both'; $box.WordWrap = $false; $box.Dock = 'Fill'
    $box.BackColor = $C.Card; $box.ForeColor = $C.Text; $box.Font = New-Object Drawing.Font('Consolas', 9); $box.BorderStyle = 'None'
    $path = Join-Path $relayRoot 'worker.log'
    $box.Text = if (Test-Path -LiteralPath $path) { (Get-Content -LiteralPath $path -Tail 60 -Encoding UTF8) -join "`r`n" } else { 'ยังไม่มี log' }
    $log.Controls.Add($box); $box.SelectionStart = $box.Text.Length; $box.ScrollToCaret()
    [void]$log.ShowDialog($relayForm)
}

# ---- wording ------------------------------------------------------------------------------
$relayEvents = @{
    worker_started = 'เริ่มทำงานแล้ว รอคำขอจากผู้ชม'; job_started = 'กำลังขอลิงก์'; job_completed = 'ได้ลิงก์แล้ว'
    job_failed = 'ขอลิงก์ไม่สำเร็จ ระบบจะลองใหม่'; worker_waiting = 'ติดต่อเว็บ NetWix ไม่ได้ กำลังลองใหม่'
    worker_stopped = 'หยุดทำงานแล้ว'; probe_started = 'กำลังทดสอบลิงก์'; probe_success = 'ทดสอบผ่าน ขอลิงก์ได้'
    urban_setup_opened = 'เปิดหน้าตั้งค่า Urban VPN อยู่'; urban_switched = 'โดนบล็อก สลับ VPN ให้แล้ว'
    urban_switch_failed = 'สลับ VPN ไม่สำเร็จ'; update_found = 'พบเวอร์ชันใหม่ กำลังอัปเดต'
    update_applied = 'อัปเดตแล้ว กำลังเริ่มใหม่'; update_failed = 'อัปเดตไม่สำเร็จ จะลองใหม่'; worker_restarting = 'เริ่มใหม่เพื่อใช้เวอร์ชันใหม่'
    update_check_failed = 'เช็กเวอร์ชันใหม่ไม่ได้ชั่วคราว'
}
$relayErrors = @{
    source_blocked = 'โรงหยกบล็อก'; timeout = 'หมดเวลา'; no_video = 'ไม่พบวิดีโอ'; browser_unavailable = 'เบราว์เซอร์มีปัญหา'
    urban_not_connected = 'VPN ไม่ได้เชื่อมต่อ'; invalid_url = 'ลิงก์ไม่ถูกต้อง'; connection_failed = 'เน็ตหลุด'
    urban_switch_failed = 'VPN ต่อไม่ติด'; urban_no_location = 'ไม่มี VPN ให้สลับ'
}
function Format-RelayError([string]$code) {
    if (-not $code) { return '' }
    if ($relayErrors.ContainsKey($code)) { return $relayErrors[$code] }
    if ($code -match '^api_(\d+)$') { return 'เว็บตอบกลับ ' + $Matches[1] }
    return $code
}
function Format-RelayTime($iso, [switch]$Seconds) {
    if (-not $iso) { return '' }
    $t = [DateTimeOffset]::Parse([string]$iso, $relayInv).ToOffset($relayIct)
    $now = [DateTimeOffset]::UtcNow.ToOffset($relayIct)
    $fmt = if ($Seconds) { 'HH:mm:ss' } else { 'HH:mm' }
    if ($t.Date -ne $now.Date) { $fmt = 'dd/MM ' + $fmt }
    return $t.ToString($fmt, $relayInv)
}
# "United States (USA)" -> "USA": Urban's short form in brackets, or the name itself.
function Format-RelayPlace($name) {
    if ([string]$name -match '\(([^)]+)\)\s*$') { return $Matches[1] }
    return [string]$name
}
function Format-RelayTitle($r) {
    $ep = if ($r.episode_number) { $r.episode_number } else { $r.episode }
    if ($r.title) { return [string]$r.title + '  ·  ตอน ' + $ep }
    return 'ซีรีส์ ' + $r.series_id + '  ·  ตอน ' + $ep
}
function Format-RelayWho($r) {
    $who = switch ([string]$r.requester) { 'guest' { 'ผู้ชมทั่วไป' } 'member' { 'สมาชิก' } '' { '-' } default { [string]$r.requester } }
    $via = switch ([string]$r.via) { 'web' { ' (เว็บ)' } 'app' { ' (แอป)' } default { '' } }
    return $who + $via
}
function Format-RelayReason($r) {
    $reason = if ($r.reason) { [string]$r.reason } elseif ([int]$r.priority -eq 0) { 'viewer' } else { 'prefetch' }
    if ($reason -eq 'viewer') { return 'คนดูรออยู่' } else { return 'เตรียมตอนถัดไป' }
}
function Format-RelayResult($r) {
    if ($r.outcome -eq 'completed') {
        $text = 'ได้ลิงก์'
        if ($r.duration_ms) { $text += ' · ' + [Math]::Round([double]$r.duration_ms / 1000) + ' วิ' }
        if ([int]$r.extras_accepted -gt 0) { $text += ' · +' + $r.extras_accepted + ' ตอน' }
        return $text
    }
    if ($r.outcome -eq 'failed') { return 'ไม่สำเร็จ · ' + (Format-RelayError $r.error) }
    return 'กำลังขอ...'
}

# ---- refresh ------------------------------------------------------------------------------
$script:relayLastList = ''
function Set-RelayPill([string]$text, $fore, $back) { $relayPill.Text = '●  ' + $text; $relayPill.ForeColor = $fore; $relayPill.BackColor = $back }
function Update-RelayView {
    try {
        $running = Test-RelayRunning
        $record = $null
        $statusPath = Join-Path $relayRoot 'status.json'
        if (Test-Path -LiteralPath $statusPath) { $record = Get-Content -LiteralPath $statusPath -Raw -Encoding UTF8 | ConvertFrom-Json }

        if (-not $running) { Set-RelayPill 'หยุดทำงาน' $C.Muted $C.GreyBg }
        elseif ($record -and $record.event -eq 'worker_waiting') { Set-RelayPill 'ติดต่อเว็บไม่ได้' $C.Amber $C.AmberBg }
        elseif ($record -and [int]$record.active -gt 0) { Set-RelayPill ('กำลังขอลิงก์ ' + $record.active + '/' + $record.concurrency + ' แท็บ') $C.Green $C.GreenBg }
        else { Set-RelayPill 'พร้อม รอคำขอ' $C.Green $C.GreenBg }
        $relayToggle.Text = if ($running) { '■   หยุดทำงาน' } else { '▶   เริ่มทำงาน' }
        $relayToggle.BackColor = if ($running) { $C.RedBg } else { $C.Accent }
        foreach ($b in @($relayTest, $relayUrban)) { $b.Enabled = -not $running; $b.ForeColor = if ($running) { $C.Grey } else { $C.Text } }

        if ($record) {
            if ($record.version) { $relayBadge.Text = 'v' + $record.version }
            $s = $record.stats
            $relayCards.ok.Text = if ($s) { [string]$s.completed } else { '-' }
            $relayCards.fail.Text = if ($s) { [string]$s.failed } else { '-' }
            $relayCards.fail.ForeColor = if ($s -and [int]$s.failed -gt 0) { $C.Red } else { $C.Text }
            $relayCards.avg.Text = if ($s -and $s.avg_ms) { ([double]$s.avg_ms / 1000).ToString('0.0', $relayInv) + ' วิ' } else { '-' }
            $relayCards.vpn.Text = if ($record.urban) { Format-RelayPlace $record.urban } else { '-' }
            $relayCards.blocks.Text = if ($s) { [string][int]$s.blocks } else { '-' }
            $relayCards.blocks.ForeColor = if ($s -and [int]$s.blocks -gt 0) { $C.Amber } else { $C.Text }
            $relayCaptions.blocks.Text = 'โดนบล็อก · 24 ชม.' + "`n" + 'รวม 30 วัน ' + $(if ($s) { [int]$s.blocks_total } else { 0 }) + ' ครั้ง'
            $relayCards.switches.Text = if ($s) { [string][int]$s.switches } else { '-' }
            $relayCaptions.switches.Text = 'สลับ VPN · 24 ชม.' + "`n" + $(if ($s) { 'ไป ' + [int]$s.locations_used + ' ที่ · รวม ' + [int]$s.switches_total + ' ครั้ง' } else { '' })
            $sw = if ($s -and $s.recent_switches) { @($s.recent_switches) } else { @() }
            $relaySwitches.Text = if ($sw.Count) {
                'สลับ VPN ล่าสุด:   ' + (($sw | Select-Object -First 3 | ForEach-Object {
                    $why = switch ([string]$_.reason) { 'source_blocked' { 'โดนบล็อก' } 'repeated_failures' { 'ล้มเหลวติดกัน' } 'manual' { 'สั่งเอง' } default { [string]$_.reason } }
                    (Format-RelayTime $_.time) + '  ' + (Format-RelayPlace $_.from) + ' → ' + (Format-RelayPlace $_.to) + ' (' + $why + ')'
                }) -join '     ·     ')
            } else { 'สลับ VPN ล่าสุด:   ยังไม่เคยสลับ — ถ้าโรงหยกบล็อก ระบบจะสลับประเทศให้เองทันที' }

            $event = [string]$record.event
            $line = if ($relayEvents.ContainsKey($event)) { $relayEvents[$event] } else { $event }
            if ($record.title -or $record.series_id) { $line += '  —  ' + (Format-RelayTitle $record) }
            if ($record.requester -or $record.via) { $line += '  ·  ' + (Format-RelayWho $record) }
            if ($event -eq 'urban_switched') { $line += '  (' + $record.from + ' → ' + $record.to + ')' }
            if ($record.error) { $line += '  ·  ' + (Format-RelayError ([string]$record.error)) }
            $relayNowText.Text = $line + '   ' + (Format-RelayTime $record.time -Seconds)
            $relayNowBar.BackColor = if ($event -match 'failed|waiting') { $C.Amber } elseif ($event -match 'completed|success|switched') { $C.Green } else { $C.Accent }

            $recent = if ($s -and $s.recent) { @($s.recent) } else { @() }
            $key = ($recent | ConvertTo-Json -Compress -Depth 4)
            if ($key -ne $script:relayLastList) {
                $script:relayLastList = $key
                $relayList.BeginUpdate()
                $relayList.Items.Clear()
                foreach ($r in $recent) {
                    $item = New-Object Windows.Forms.ListViewItem((Format-RelayTime $r.started_at))
                    [void]$item.SubItems.Add((Format-RelayTitle $r))
                    [void]$item.SubItems.Add((Format-RelayWho $r))
                    [void]$item.SubItems.Add((Format-RelayReason $r))
                    [void]$item.SubItems.Add((Format-RelayResult $r))
                    [void]$item.SubItems.Add($(if ($r.vpn) { [string]$r.vpn } else { '-' }))
                    $item.Tag = [string]$r.outcome
                    [void]$relayList.Items.Add($item)
                }
                $relayList.Columns[5].Width = -2
                $relayList.EndUpdate()
                $relayEmpty.Visible = $relayList.Items.Count -eq 0
            }
        } else {
            $relayNowText.Text = 'ยังไม่เคยทำงาน — กด "เริ่มทำงาน" หรือใช้ "ทดสอบลิงก์" ก่อน'
        }
    } catch { $relayNowText.Text = 'กำลังรออัปเดตสถานะ...' }
}

# Whatever the screen scaling did to the controls, size the window to hold all of them.
function Resize-RelayForm {
    $right = 0; $bottom = 0
    foreach ($c in $relayForm.Controls) {
        if ($c -eq $relayHeader) { continue }
        if ($c.Right -gt $right) { $right = $c.Right }
        if ($c.Bottom -gt $bottom) { $bottom = $c.Bottom }
    }
    $pad = [int]($relayList.Left)
    $relayForm.ClientSize = New-Object Drawing.Size(($right + $pad), ($bottom + [int]($pad / 2)))
    $relayForm.MinimumSize = $relayForm.Size
    $relayHeader.Width = $relayForm.ClientSize.Width
    $relayPill.Left = $relayHeader.Width - $relayPill.Width - $pad
}
$relayForm.Add_Shown({ Resize-RelayForm })

$relayTimer = New-Object Windows.Forms.Timer
$relayTimer.Interval = 2000
$relayTimer.Add_Tick({ Update-RelayView })
$relayTimer.Start()
$relayForm.Add_FormClosed({ $relayTimer.Stop(); $relayTimer.Dispose() })
Update-RelayView
if ($Check) {
    $relayTimer.Stop()
    $relayForm.Show()
    Resize-RelayForm
    [Windows.Forms.Application]::DoEvents()
    $relayForm.Refresh()
    $bitmap = New-Object Drawing.Bitmap($relayForm.Width, $relayForm.Height)
    $relayForm.DrawToBitmap($bitmap, (New-Object Drawing.Rectangle(0, 0, $relayForm.Width, $relayForm.Height)))
    $bitmap.Save((Join-Path $relayRoot 'app-preview.png'), [Drawing.Imaging.ImageFormat]::Png)
    $bitmap.Dispose()
    $relayForm.Close()
    $relayForm.Dispose()
    Write-Output 'App controls and preview OK'
} else { [void]$relayForm.ShowDialog() }
