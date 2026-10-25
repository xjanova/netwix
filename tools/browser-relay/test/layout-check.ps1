# Invoked by app.ps1 -Check after the form has completed its real WinForms DPI scaling.
# Exercise live controls, including resize after scrolling, without starting a worker.
function Assert-RelayLayout {
    $controls = @($relayBody.Controls | Where-Object { $_.Visible -and $_ -ne $relayEmpty })
    foreach ($control in $controls) {
        if ($control.Left -lt 0 -or $control.Top -lt 0 -or $control.Right -gt $relayBody.Width + 1 -or $control.Bottom -gt $relayBody.Height + 1) {
            throw ('Control outside content: ' + $control.Text + ' ' + $control.Bounds)
        }
        foreach ($other in $controls) {
            if ($control -ne $other -and $control.Bounds.IntersectsWith($other.Bounds)) {
                throw ('Overlapping controls: ' + $control.Text + ' / ' + $other.Text)
            }
        }
    }
    foreach ($panel in $relayCardPanels) {
        foreach ($child in $panel.Controls) {
            if ($child.Right -gt $panel.Width + 1 -or $child.Bottom -gt $panel.Height + 1) { throw 'Card content clipped' }
        }
    }
    if ($relayViewport.HorizontalScroll.Visible) {
        throw ('Unexpected horizontal scroll: scale=' + $script:relayScale + ' form=' + $relayForm.ClientSize + ' viewport=' + $relayViewport.ClientSize + ' body=' + $relayBody.Bounds)
    }
    if ($relayList.Height -lt 149 * $script:relayScale) { throw 'Request table became too short' }
    if ($relayTest.Right -ge $relayUrban.Left -or $relayUrban.Right -gt $relayTools.Width) { throw 'Tool buttons overlap' }
    $first = $relayCardPanels[0]
    foreach ($panel in $relayCardPanels) {
        if ([Math]::Abs($panel.Width - $first.Width) -gt 1) { throw 'Card widths are unequal' }
    }
}
function Test-RelayLayout {
    $originalSize = $relayForm.ClientSize
    $originalScale = $script:relayScale
    $checks = 0
    try {
        foreach ($scale in @(1.0, 1.25, 1.5)) {
            $ratio = $scale / $script:relayScale
            $relayForm.Scale((New-Object Drawing.SizeF($ratio, $ratio)))
            $script:relayScale = $scale
            foreach ($size in @(@(560, 480), @(640, 700), @(760, 600), @(900, 676), @(960, 760), @(1000, 760), @(1040, 760), @(1280, 900), @(560, 480), @(1040, 760))) {
                $relayForm.ClientSize = New-Object Drawing.Size(([int]($size[0] * $scale)), ([int]($size[1] * $scale)))
                Resize-RelayForm
                [Windows.Forms.Application]::DoEvents()
                Assert-RelayLayout
                if ($env:RELAY_LAYOUT_PREVIEW_DIR -and $scale -eq 1.0 -and $size[0] -in @(560, 900, 1280)) {
                    $relayViewport.AutoScrollPosition = New-Object Drawing.Point(0, 0)
                    $bitmap = New-Object Drawing.Bitmap($relayForm.Width, $relayForm.Height)
                    try {
                        $relayForm.DrawToBitmap($bitmap, (New-Object Drawing.Rectangle(0, 0, $relayForm.Width, $relayForm.Height)))
                        $bitmap.Save((Join-Path $env:RELAY_LAYOUT_PREVIEW_DIR ('relay-' + $size[0] + '.png')), [Drawing.Imaging.ImageFormat]::Png)
                    } finally { $bitmap.Dispose() }
                }
                # Scroll to the footer, then resize on the next iteration. Content coordinates
                # must stay stable even when AutoScrollPosition is negative.
                $relayViewport.ScrollControlIntoView($relayBody)
                $relayViewport.AutoScrollPosition = New-Object Drawing.Point(0, $relayBody.Height)
                [Windows.Forms.Application]::DoEvents()
                Assert-RelayLayout
                $checks++
            }
        }
        $relayForm.WindowState = 'Maximized'
        [Windows.Forms.Application]::DoEvents()
        Assert-RelayLayout
        $relayForm.WindowState = 'Minimized'
        [Windows.Forms.Application]::DoEvents()
        $relayForm.WindowState = 'Normal'
        [Windows.Forms.Application]::DoEvents()
        Assert-RelayLayout
    } finally {
        $ratio = $originalScale / $script:relayScale
        $relayForm.Scale((New-Object Drawing.SizeF($ratio, $ratio)))
        $script:relayScale = $originalScale
        $relayForm.ClientSize = $originalSize
        $relayViewport.AutoScrollPosition = New-Object Drawing.Point(0, 0)
        Resize-RelayForm
    }
    Write-Output ('Layout OK: ' + $checks + ' size/scale combinations, before and after scrolling')
}
