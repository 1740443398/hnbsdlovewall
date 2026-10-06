<#
    lw-sync-key.bat 的实现体（生成同步密钥 / 查看状态 / 计算请求签名）
    ---------------------------------------------------------------
    与 PHP 端（includes/sync_auth.php）保持同一套算法：
        签名 = hex(HMAC-SHA256(种子, 时间戳 + "\n" + 方法 + "\n" + 路径 + "\n" + sha256(请求体)))
    直接双击 lw-sync-key.bat 可看用法。
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)][string]$Action = '',
    [Parameter(Position = 1)][string]$Method = 'GET',
    [Parameter(Position = 2)][string]$Url = '',
    [Parameter(Position = 3)][string]$BodyFile = '',
    [string]$SeedFile = '',
    [string]$Cookie = '',
    [string]$UserAgent = '',
    [switch]$Send
)

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }

$root = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($SeedFile)) {
    $SeedFile = Join-Path $root 'config\sync_config.php'
}
$SeedTtlSeconds = 90 * 86400

function Get-SeedState {
    if (-not (Test-Path -LiteralPath $SeedFile)) { return $null }
    $text = Get-Content -LiteralPath $SeedFile -Raw -Encoding UTF8
    $m = [regex]::Match($text, "'secret_key'\s*=>\s*'([^']*)'")
    if (-not $m.Success -or $m.Groups[1].Value -eq '') { return $null }
    $c = [regex]::Match($text, "'created_at'\s*=>\s*(\d+)")
    $createdAt = 0
    if ($c.Success) { $createdAt = [int64]$c.Groups[1].Value }
    if ($createdAt -le 0) { $createdAt = [int64]([DateTimeOffset](Get-Item -LiteralPath $SeedFile).LastWriteTime).ToUnixTimeSeconds() }
    [pscustomobject]@{ Seed = $m.Groups[1].Value; CreatedAt = $createdAt }
}

function New-Seed {
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $entropy = New-Object byte[] 32
        $salt    = New-Object byte[] 16
        $rng.GetBytes($entropy)
        $rng.GetBytes($salt)

        # 站点特征只参与搅拌（让不同部署彼此隔离）；密钥的唯一性完全来自上面的 256 位系统随机数。
        # 绝不能改成「按时间戳派生」——那样同一秒部署的两个站点会算出同一个密钥。
        $hosts = ''
        $constFile = Join-Path $root 'config\constants.php'
        if (Test-Path -LiteralPath $constFile) {
            $h = [regex]::Match((Get-Content -LiteralPath $constFile -Raw -Encoding UTF8), "EXPECTED_HOSTS'\s*,\s*\[([^\]]*)\]")
            if ($h.Success) { $hosts = $h.Groups[1].Value }
        }
        $context = @(
            $root,
            $env:COMPUTERNAME,
            $hosts,
            [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds(),
            ([BitConverter]::ToString($salt) -replace '-', '')
        ) -join '|'

        $hmac = New-Object System.Security.Cryptography.HMACSHA256
        $hmac.Key = $entropy
        try {
            $digest = $hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($context))
            return ([BitConverter]::ToString($digest) -replace '-', '').ToLowerInvariant()
        } finally { $hmac.Dispose() }
    } finally { $rng.Dispose() }
}

function Save-Seed([string]$seed, [int64]$createdAt) {
    $lines = @(
        '<?php',
        '// AI/开发者 数据同步端点专用密钥（高度敏感：请勿上传公开仓库）',
        '// 本文件由 includes/sync_auth.php 自动生成与轮换，也可用 tools/lw-sync-key.bat 生成。',
        '// 请求需带 X-Sync-Timestamp 与 X-Sync-Key，算法见 includes/sync_auth.php。',
        '$SYNC_CFG = [',
        "    'secret_key' => '$seed',",
        "    'created_at' => $createdAt,",
        '];'
    )
    # UTF-8 无 BOM：PHP 文件带 BOM 会先输出字节，导致 header() 报「headers already sent」
    $encoding = New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($SeedFile, (($lines -join "`n") + "`n"), $encoding)
}

function Get-HexSha256([byte[]]$bytes) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($bytes)) -replace '-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Get-Signature([string]$seed, [string]$timestamp, [string]$method, [string]$path, [byte[]]$body) {
    $canonical = @($timestamp, $method.ToUpperInvariant(), $path, (Get-HexSha256 $body)) -join "`n"
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    $hmac.Key = [Text.Encoding]::UTF8.GetBytes($seed)
    try { return ([BitConverter]::ToString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))) -replace '-', '').ToLowerInvariant() }
    finally { $hmac.Dispose() }
}

function Show-Help {
    Write-Host ''
    Write-Host '  同步密钥工具（ai_sync 端点专用）' -ForegroundColor Cyan
    Write-Host '  ------------------------------------------------------------'
    Write-Host '  lw-sync-key.bat gen                      生成本地密钥（写入 config\sync_config.php）'
    Write-Host '  lw-sync-key.bat show                     查看当前密钥、生成时间与剩余有效期'
    Write-Host '  lw-sync-key.bat sign GET  <网址>         计算一次 GET 请求的签名并打印 curl 命令'
    Write-Host '  lw-sync-key.bat sign POST <网址> <文件>  同上，请求体取自文件（JSON）'
    Write-Host '  lw-sync-key.bat sign GET  <网址> -Send   直接发送（不需要手抄命令）'
    Write-Host ''
    Write-Host '  可选参数：' -ForegroundColor DarkGray
    Write-Host '    -Cookie "<浏览器里的 Cookie>"  宿主的反爬通行证是绑定 UA 的，需要时连同 UA 一起给'
    Write-Host '    -UserAgent "<浏览器 UA>"'
    Write-Host '    -SeedFile "<路径>"             默认 config\sync_config.php'
    Write-Host ''
    Write-Host '  网址可省略路径，只写站点根地址，会自动补 /api/ai_sync.php' -ForegroundColor DarkGray
    Write-Host ''
}

switch ($Action.ToLowerInvariant()) {
    'gen' {
        $state = Get-SeedState
        if ($state) {
            Write-Host "  当前密钥：$($state.Seed)"
            $answer = Read-Host '  是否覆盖并生成新密钥？旧密钥立即失效 (y/N)'
            if ($answer -notmatch '^[Yy]') { Write-Host '  已取消。'; exit 0 }
        }
        $seed = New-Seed
        $now = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        Save-Seed $seed $now
        Write-Host ''
        Write-Host '  已生成新密钥并写入：' -ForegroundColor Green
        Write-Host "    $SeedFile"
        Write-Host "  密钥：$seed" -ForegroundColor Yellow
        Write-Host "  生成时间：$([DateTimeOffset]::FromUnixTimeSeconds($now).ToLocalTime().ToString('yyyy-MM-dd HH:mm:ss'))（有效期 90 天）"
        Write-Host ''
        Write-Host '  记得把该文件上传到服务器（覆盖同路径文件），否则本地签名与服务器对不上。' -ForegroundColor DarkGray
        exit 0
    }
    'show' {
        $state = Get-SeedState
        if (-not $state) {
            Write-Host '  还没有密钥文件，先跑一次：lw-sync-key.bat gen' -ForegroundColor Yellow
            exit 1
        }
        $ageDays = [math]::Floor(([DateTimeOffset]::UtcNow.ToUnixTimeSeconds() - $state.CreatedAt) / 86400)
        $left = [math]::Max(0, [math]::Floor($SeedTtlSeconds / 86400) - $ageDays)
        Write-Host ''
        Write-Host "  文件      ：$SeedFile"
        Write-Host "  密钥      ：$($state.Seed)" -ForegroundColor Yellow
        Write-Host "  生成时间  ：$([DateTimeOffset]::FromUnixTimeSeconds($state.CreatedAt).ToLocalTime().ToString('yyyy-MM-dd HH:mm:ss'))（已过 $ageDays 天）"
        Write-Host "  剩余有效期：$left 天（到期后服务器会自动轮换）"
        Write-Host ''
        exit 0
    }
    'sign' {
        if ([string]::IsNullOrWhiteSpace($Url)) {
            Write-Host '  用法：lw-sync-key.bat sign GET <网址> [请求体文件]' -ForegroundColor Yellow
            exit 1
        }
        $state = Get-SeedState
        if (-not $state) {
            Write-Host '  还没有密钥文件，先跑一次：lw-sync-key.bat gen' -ForegroundColor Yellow
            exit 1
        }

        if ($Url -notmatch '^https?://') { $Url = 'https://' + $Url }
        $target = $Url.TrimEnd('/')
        if ($target -notmatch '/api/ai_sync\.php$') { $target = $target + '/api/ai_sync.php' }
        $uri = [Uri]$target
        $httpMethod = $Method.ToUpperInvariant()

        $body = New-Object byte[] 0
        if (-not [string]::IsNullOrWhiteSpace($BodyFile)) {
            if (-not (Test-Path -LiteralPath $BodyFile)) { throw "请求体文件不存在：$BodyFile" }
            $body = [IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $BodyFile).Path)
        }

        $timestamp = [string][DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        $signature = Get-Signature $state.Seed $timestamp $httpMethod $uri.AbsolutePath $body

        Write-Host ''
        Write-Host "  地址      ：$($uri.AbsoluteUri)"
        Write-Host "  方法      ：$httpMethod"
        Write-Host "  时间戳    ：$timestamp"
        Write-Host "  签名      ：$signature" -ForegroundColor Yellow
        Write-Host ''
        Write-Host '  等价的 curl 命令：' -ForegroundColor DarkGray
        # 先把 '@路径' 拼成单个变量：PowerShell 5.1 在数组字面量里会把 '+' 表达式拆成多个元素
        $bodyArg = ''
        if ($httpMethod -eq 'POST') { $bodyArg = '@' + (Resolve-Path -LiteralPath $BodyFile).Path }
        $curlArgs = @('-X', $httpMethod, '-H', "'X-Sync-Timestamp: $timestamp'", '-H', "'X-Sync-Key: $signature'",
                      '-H', "'Accept: application/json'", '-H', "'X-Requested-With: XMLHttpRequest'")
        if ($httpMethod -eq 'POST') { $curlArgs += @('-H', "'Content-Type: application/json'", '--data-binary', "'$bodyArg'") }
        Write-Host ("  curl.exe " + ($curlArgs -join ' ') + " '" + $uri.AbsoluteUri + "'") -ForegroundColor DarkGray
        Write-Host ''

        if ($Send) {
            $realArgs = @('-sS', '-X', $httpMethod,
                          '-H', "X-Sync-Timestamp: $timestamp",
                          '-H', "X-Sync-Key: $signature",
                          '-H', 'Accept: application/json',
                          '-H', 'X-Requested-With: XMLHttpRequest')
            if ($httpMethod -eq 'POST') {
                $realArgs += @('-H', 'Content-Type: application/json', '--data-binary', $bodyArg)
            }
            if (-not [string]::IsNullOrWhiteSpace($Cookie)) { $realArgs += @('-H', "Cookie: $Cookie") }
            if (-not [string]::IsNullOrWhiteSpace($UserAgent)) { $realArgs += @('-A', $UserAgent) }
            $realArgs += $uri.AbsoluteUri

            $response = & curl.exe @realArgs
            if ($response -match 'aes\.js' -or $response -match 'toNumbers') {
                Write-Host '  收到的是宿主的反爬挑战页，不是接口响应。' -ForegroundColor Red
                Write-Host '  通行证 cookie 与 User-Agent 绑定：请用浏览器里的 Cookie 配上同一个浏览器的 UA，' -ForegroundColor Red
                Write-Host '  或先用浏览器打开站点再重试。' -ForegroundColor Red
                exit 2
            }
            Write-Host $response
            if ($response -match '"success"\s*:\s*false') {
                Write-Host '  请求被拒绝，请对照上面的 message 排查。' -ForegroundColor Red
                exit 3
            }
            Write-Host '  请求成功。' -ForegroundColor Green
        }
        exit 0
    }
    default { Show-Help; exit 0 }
}
