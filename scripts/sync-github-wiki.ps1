param(
    [string]$Destination = $env:GITHUB_WIKI_PATH
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$sourceDir = Join-Path $root 'docs\wiki'

if (-not $Destination) {
    $repoName = Split-Path -Leaf $root
    $candidate = Join-Path (Split-Path -Parent $root) "$repoName.wiki"
    if (Test-Path (Join-Path $candidate '.git')) {
        $Destination = $candidate
    }
}

if (-not $Destination) {
    Write-Error "Usage: scripts/sync-github-wiki.ps1 <wiki-checkout> (or set GITHUB_WIKI_PATH)"
}

if (-not (Test-Path $sourceDir)) {
    Write-Error "Source docs directory not found: $sourceDir"
}

if (-not (Test-Path (Join-Path $Destination '.git'))) {
    Write-Error "Destination is not a git checkout: $Destination"
}

$sourceFiles = Get-ChildItem -Path $sourceDir -File
$sourceNames = @($sourceFiles | ForEach-Object { $_.Name })

foreach ($file in $sourceFiles) {
    Copy-Item -Path $file.FullName -Destination (Join-Path $Destination $file.Name) -Force
}

foreach ($existing in (Get-ChildItem -Path $Destination -File)) {
    if ($existing.Name -notin $sourceNames) {
        Remove-Item -Path $existing.FullName -Force
    }
}

Write-Output "Synced $sourceDir -> $Destination"
Write-Output "Review with: git -C $Destination status --short"
