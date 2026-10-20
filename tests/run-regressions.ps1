param(
    [string] $ProRoot = (Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) 'lucidlab-mpc-cleanup-pro'),
    [string] $PhpPath = ''
)

$ErrorActionPreference = 'Stop'
$php = if ($PhpPath) { Get-Command -Name $PhpPath -ErrorAction SilentlyContinue } else { Get-Command php -ErrorAction SilentlyContinue }
$node = Get-Command node -ErrorAction SilentlyContinue
if (-not $php) { throw 'PHP CLI is required for lint and standalone regression tests.' }
if (-not $node) { throw 'Node.js is required for JavaScript syntax checks.' }

$roots = @((Split-Path -Parent $PSScriptRoot), $ProRoot)
foreach ($root in $roots) {
    if (-not (Test-Path -LiteralPath $root -PathType Container)) { throw "Missing plugin source: $root" }
    foreach ($file in (Get-ChildItem -LiteralPath $root -Recurse -File -Filter '*.php' | Where-Object {
        $_.FullName -notmatch '[\\/](dist|vendor)[\\/]'
    })) {
        $result = & $php.Source -l $file.FullName 2>&1
        if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($file.FullName)`n$result" }
    }
    foreach ($file in (Get-ChildItem -LiteralPath (Join-Path $root 'assets') -Recurse -File -Filter '*.js')) {
        $result = & $node.Source --check $file.FullName 2>&1
        if ($LASTEXITCODE -ne 0) { throw "JavaScript syntax failed: $($file.FullName)`n$result" }
    }
}

foreach ($test in (Get-ChildItem -LiteralPath $PSScriptRoot -File -Filter '*.php')) {
    $result = & $php.Source $test.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Regression failed: $($test.Name)`n$result" }
    Write-Output "$($test.Name): $result"
}
Write-Output 'PHP lint, JavaScript syntax and standalone regressions passed.'
