param(
    [string] $ProRoot = (Join-Path (Split-Path -Parent $PSScriptRoot) 'lucidlab-mpc-cleanup-pro'),
    [switch] $FreeOnly,
    [switch] $ProOnly,
    [string] $OutputDir = (Join-Path (Split-Path -Parent $PSScriptRoot) 'Releases'),
    # Path to phpcs(.bat). Defaults to $env:LUCIDLAB_PHPCS, vendor\bin\phpcs.bat next to this script,
    # the global Composer install, or phpcs on PATH.
    [string] $Phpcs = $env:LUCIDLAB_PHPCS,
    # Folder of the Plugin Check plugin (contains phpcs-rulesets\plugin-review.xml). Defaults to $env:LUCIDLAB_PLUGIN_CHECK_DIR.
    [string] $PluginCheckDir = $env:LUCIDLAB_PLUGIN_CHECK_DIR,
    # Emergency only: build without the coding-standards gate.
    [switch] $SkipCodingStandards
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Resolve-Phpcs {
    param([string] $Hint)
    $candidates = @()
    if ($Hint) { $candidates += $Hint }
    $candidates += (Join-Path $PSScriptRoot 'vendor\bin\phpcs.bat')
    if ($env:APPDATA) { $candidates += (Join-Path $env:APPDATA 'Composer\vendor\bin\phpcs.bat') }
    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate -PathType Leaf)) { return $candidate }
    }
    $command = Get-Command phpcs -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    throw 'PHP_CodeSniffer (phpcs) was not found. Install it (see docs/coding-standards.md), set LUCIDLAB_PHPCS or pass -Phpcs <path>.'
}

function Test-CodingStandards {
    param([string] $Root, [string] $Slug, [string] $PhpcsPath, [string] $PluginCheck)

    $Root = [IO.Path]::GetFullPath($Root)
    $ruleset = Join-Path $Root 'phpcs.xml.dist'
    if (-not (Test-Path -LiteralPath $ruleset -PathType Leaf)) { throw "Missing coding-standards ruleset: $ruleset" }
    Write-Output "PHPCS (WordPress Coding Standards): $Slug"
    & $PhpcsPath -q --no-colors "--standard=$ruleset"
    if ($LASTEXITCODE -ne 0) { throw "PHPCS found coding-standard issues in $Slug (exit code $LASTEXITCODE). Fix them before building a release." }

    if (-not $PluginCheck) { throw 'Plugin Check folder not set. Set LUCIDLAB_PLUGIN_CHECK_DIR or pass -PluginCheckDir (see docs/coding-standards.md).' }
    $review = Join-Path $PluginCheck 'phpcs-rulesets\plugin-review.xml'
    if (-not (Test-Path -LiteralPath $review -PathType Leaf)) { throw "Plugin Check ruleset not found: $review" }
    $targets = @(
        (Join-Path $Root ($Slug + '.php')),
        (Join-Path $Root 'uninstall.php'),
        (Join-Path $Root 'includes'),
        (Join-Path $Root 'languages')
    )
    Write-Output "PHPCS (Plugin Check rules): $Slug"
    & $PhpcsPath -q --no-colors "--standard=$review" --extensions=php '--ignore=*.l10n.php' --runtime-set testVersion 8.1- @targets
    if ($LASTEXITCODE -ne 0) { throw "Plugin Check rules found issues in $Slug (exit code $LASTEXITCODE). If phpcs says a PluginCheck sniff does not exist, add the Plugin Check sniffs to installed_paths (see docs/coding-standards.md)." }
}

function Build-PluginZip {
    param([string] $Root, [string] $Slug, [string] $OutputDir)

    $Root = [IO.Path]::GetFullPath($Root)
    $main = Join-Path $Root ($Slug + '.php')
    $readme = Join-Path $Root 'readme.txt'
    if (-not (Test-Path -LiteralPath $main -PathType Leaf) -or -not (Test-Path -LiteralPath $readme -PathType Leaf)) {
        throw "Missing plugin entry point or readme in $Root"
    }
    $header = [IO.File]::ReadAllText($main)
    $version = [regex]::Match($header, '(?m)^\s*\*\s*Version:\s*([0-9.]+)\s*$').Groups[1].Value
    if (-not $version -or -not ([IO.File]::ReadAllText($readme) -match "(?m)^Stable tag: $([regex]::Escape($version))\s*$")) {
        throw "Plugin version and readme Stable tag differ in $Root"
    }

    $files = @($main, $readme, (Join-Path $Root 'uninstall.php'))
    foreach ($dirName in @('assets', 'includes', 'languages')) {
        $dir = Join-Path $Root $dirName
        if (-not (Test-Path -LiteralPath $dir -PathType Container)) { throw "Missing $dir" }
        $files += @(Get-ChildItem -LiteralPath $dir -Recurse -File | Where-Object {
            $_.Extension.ToLowerInvariant() -in @('.php', '.js', '.css', '.png', '.woff2', '.po', '.mo', '.pot')
        } | ForEach-Object FullName)
    }
    foreach ($file in $files) {
        if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing release file: $file" }
        if ($file.EndsWith('.l10n.php', [StringComparison]::OrdinalIgnoreCase)) {
            $content = [IO.File]::ReadAllText($file)
            if ($content.Contains("`r")) { throw "Mixed or CR line endings in generated translation file: $file" }
        }
    }
    $zipPath = Join-Path $OutputDir ($Slug + '-' + $version + '.zip')
    if (Test-Path -LiteralPath $zipPath) { throw "Release already exists: $zipPath" }
    $tempPath = Join-Path $OutputDir ($Slug + '-' + $version + '.building.zip')
    if (Test-Path -LiteralPath $tempPath) { throw "Temporary release already exists: $tempPath" }

    $zip = [IO.Compression.ZipFile]::Open($tempPath, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in ($files | Sort-Object -Unique)) {
            $relative = $file.Substring($Root.TrimEnd('\').Length + 1).Replace('\', '/')
            $entry = $zip.CreateEntry($Slug + '/' + $relative, [IO.Compression.CompressionLevel]::Optimal)
            $output = $entry.Open()
            try {
                $inputFile = [IO.File]::OpenRead($file)
                try { $inputFile.CopyTo($output) } finally { $inputFile.Dispose() }
            } finally { $output.Dispose() }
        }
    } finally { $zip.Dispose() }

    $check = [IO.Compression.ZipFile]::OpenRead($tempPath)
    try {
        $names = @($check.Entries | ForEach-Object FullName)
        if ($names.Count -ne $files.Count -or
            @($names | Where-Object { $_ -match '(?i)(^|/)(tests/|dist/|[^/]+\.bak$|[^/]+\.md$)' }).Count -gt 0 -or
            @($names | Where-Object { $_ -match '(?i)(mpc-emergency-restore|restore-translations\.php|class-mpc-install-ping\.php)' }).Count -gt 0 -or
            $names -notcontains ($Slug + '/' + $Slug + '.php') -or
            $names -notcontains ($Slug + '/readme.txt')) {
            throw "Release content validation failed: $tempPath"
        }
    } finally { $check.Dispose() }

    Move-Item -LiteralPath $tempPath -Destination $zipPath
    Write-Output "$zipPath ($($files.Count) files)"
}

$out = $OutputDir
if (-not (Test-Path -LiteralPath $out -PathType Container)) { throw "Missing release directory: $out" }
if ($FreeOnly -and $ProOnly) { throw 'Choose FreeOnly or ProOnly, not both.' }
if ($SkipCodingStandards) {
    Write-Warning 'Coding-standards gate skipped (-SkipCodingStandards). Do not publish this build without running PHPCS.'
} else {
    $phpcsPath = Resolve-Phpcs -Hint $Phpcs
    if (-not $ProOnly) { Test-CodingStandards -Root $PSScriptRoot -Slug 'lucidlab-mpc-cleanup' -PhpcsPath $phpcsPath -PluginCheck $PluginCheckDir }
    if (-not $FreeOnly) { Test-CodingStandards -Root $ProRoot -Slug 'lucidlab-mpc-cleanup-pro' -PhpcsPath $phpcsPath -PluginCheck $PluginCheckDir }
}
if (-not $ProOnly) {
    $proMain = Join-Path $ProRoot 'lucidlab-mpc-cleanup-pro.php'
    if (-not (Test-Path -LiteralPath $proMain -PathType Leaf)) { throw "Missing Pro entry point: $proMain" }
    $proVersion = [regex]::Match([IO.File]::ReadAllText($proMain), '(?m)^\s*\*\s*Version:\s*([0-9.]+)\s*$').Groups[1].Value
    $proMinFree = [regex]::Match([IO.File]::ReadAllText($proMain), "define\(\s*'LUCIDLAB_MPC_PRO_MIN_FREE',\s*'([0-9.]+)'\s*\)").Groups[1].Value
    $freeVersion = [regex]::Match([IO.File]::ReadAllText((Join-Path $PSScriptRoot 'lucidlab-mpc-cleanup.php')), '(?m)^\s*\*\s*Version:\s*([0-9.]+)\s*$').Groups[1].Value
    $freeReadme = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'readme.txt'))
    if (-not ($freeReadme -match "(?m)^Works with Pro: $([regex]::Escape($proVersion))\s*$")) { throw 'Free readme Works with Pro must match the latest Pro version.' }
    if (-not $proVersion -or -not $proMinFree -or -not $freeVersion -or ([version]$freeVersion -lt [version]$proMinFree)) { throw 'Free version must satisfy the Pro LUCIDLAB_MPC_PRO_MIN_FREE requirement.' }
    Build-PluginZip -Root $PSScriptRoot -Slug 'lucidlab-mpc-cleanup' -OutputDir $out
}
if (-not $FreeOnly) {
    Build-PluginZip -Root $ProRoot -Slug 'lucidlab-mpc-cleanup-pro' -OutputDir $out
}
