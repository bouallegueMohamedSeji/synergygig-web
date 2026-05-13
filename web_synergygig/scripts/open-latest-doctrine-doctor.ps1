param(
    [string]$BaseUrl = "http://127.0.0.1:8000",
    [switch]$OpenBrowser
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot

# Generate a fresh profile entry (redirect is fine; profiler still stores request)
try {
    Invoke-WebRequest -Uri "$BaseUrl/dashboard" -UseBasicParsing -MaximumRedirection 0 -ErrorAction SilentlyContinue | Out-Null
} catch {
    # Redirects/non-200 are fine for profiler token generation
}

$indexPath = Join-Path $projectRoot "var/cache/dev/profiler/index.csv"
if (-not (Test-Path $indexPath)) {
    throw "Profiler index not found at $indexPath"
}

$line = Get-Content $indexPath | Select-Object -Last 1
if ([string]::IsNullOrWhiteSpace($line)) {
    throw "Profiler index is empty."
}

$parts = $line.Split(',')
if ($parts.Length -lt 1 -or [string]::IsNullOrWhiteSpace($parts[0])) {
    throw "Could not parse profiler token from index.csv line: $line"
}

$token = $parts[0].Trim()
$url = "$BaseUrl/_profiler/$token`?panel=doctrine_doctor"
Write-Host "Doctrine Doctor URL:" -ForegroundColor Cyan
Write-Host $url

if ($OpenBrowser) {
    Start-Process $url | Out-Null
}
