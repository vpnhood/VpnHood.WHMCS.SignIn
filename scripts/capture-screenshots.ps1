<#
.SYNOPSIS
    Regenerate the WHMCS admin screenshots used by the module README.

.DESCRIPTION
    Drives a real WHMCS admin session with Playwright and writes PNGs to docs/images/.
    Credentials are read from the credentials folder outside the repo and passed to the
    capture script through the environment, so they are never written to disk here and
    never appear in a committed file.

    Sensitive values are overwritten in the page before any screenshot is taken — see
    the header of capture-screenshots.js for what and why. The script reports anything
    credential-shaped that survived into a captured image; review the PNGs regardless.

    The capture is read only: it never activates, disables or reconfigures the addon,
    so it documents the target install exactly as it finds it.

    Playwright drives the Chrome already installed on the machine, so no browser
    download is needed.

.PARAMETER Url
    WHMCS to capture from. Defaults to the shared dev install.

.PARAMETER CredentialsPath
    JSON file holding the admin credentials, in the format written by the VpnHood dev
    setup: { "adminUser": "...", "adminPassword": "..." }.

.PARAMETER OutDir
    Where to write the PNGs. Defaults to docs/images/.

.EXAMPLE
    ./scripts/capture-screenshots.ps1
    Recapture every screenshot from the dev WHMCS into docs/images/.

.EXAMPLE
    ./scripts/capture-screenshots.ps1 -Url https://my-whmcs.example.com
    Capture from a different install.
#>
[CmdletBinding()]
# CredentialsPath is a filesystem path, not a secret — the analyser only flags it
# because the name contains "Credentials". The password it points at is read into a
# local, passed to node through the environment, and cleared again below.
[Diagnostics.CodeAnalysis.SuppressMessageAttribute('PSAvoidUsingPlainTextForPassword', 'CredentialsPath')]
param(
    [string] $Url = 'https://whmcs-dev.vpnhood.com',
    [string] $CredentialsPath,
    [string] $OutDir
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $OutDir) { $OutDir = Join-Path $RepoRoot 'docs\images' }
if (-not $CredentialsPath) {
    # <Vh root>\.user\account-dev.vpnhood.com\ — outside the repo, never committed.
    # Same folder deploy-dev.sh takes its SSH key from.
    $CredentialsPath = Join-Path (Split-Path -Parent $RepoRoot) '.user\account-dev.vpnhood.com\secrets.json'
}

function Fail([string] $Message) { Write-Host "ERROR: $Message" -ForegroundColor Red; exit 1 }

if (-not (Get-Command node -ErrorAction SilentlyContinue)) { Fail 'Node.js not found.' }
if (-not (Test-Path $CredentialsPath)) {
    Fail "No credentials at $CredentialsPath. Pass -CredentialsPath, or see CLAUDE.md for where the dev credentials live."
}

# Playwright lives in a throwaway folder, not in the repo: this is a maintenance tool
# run occasionally, and the module itself has no JavaScript toolchain to attach it to.
$toolDir = Join-Path ([System.IO.Path]::GetTempPath()) 'vpnhood-shot-tools'
if (-not (Test-Path (Join-Path $toolDir 'node_modules\playwright'))) {
    Write-Host '==> Installing Playwright (uses your existing Chrome, no browser download)' -ForegroundColor Cyan
    New-Item -ItemType Directory -Path $toolDir -Force | Out-Null
    '{ "name": "vpnhood-shot-tools", "private": true }' | Set-Content (Join-Path $toolDir 'package.json')
    $env:PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD = '1'
    Push-Location $toolDir
    try {
        & npm install playwright --no-audit --no-fund --silent
        if ($LASTEXITCODE -ne 0) { Fail 'npm install playwright failed.' }
    } finally { Pop-Location }
}

$secrets = Get-Content $CredentialsPath -Raw | ConvertFrom-Json
$user = $secrets.adminUser
$pass = $secrets.adminPassword
if (-not $user -or -not $pass) { Fail "Could not read adminUser and adminPassword out of $CredentialsPath." }

Write-Host "==> Capturing from $Url into $OutDir" -ForegroundColor Cyan

$env:WHMCS_URL = $Url
$env:WHMCS_USER = $user
$env:WHMCS_PASS = $pass
$env:SHOT_DIR = $OutDir
$env:NODE_PATH = Join-Path $toolDir 'node_modules'
try {
    & node (Join-Path $PSScriptRoot 'capture-screenshots.js')
    $code = $LASTEXITCODE
} finally {
    # Do not leave the password sitting in the session environment.
    Remove-Item Env:WHMCS_PASS -ErrorAction SilentlyContinue
    Remove-Item Env:WHMCS_USER -ErrorAction SilentlyContinue
}

if ($code -ne 0) { Fail 'Capture failed.' }

Write-Host ''
Write-Host 'Review the images before committing:' -ForegroundColor Yellow
Write-Host "  $OutDir"
