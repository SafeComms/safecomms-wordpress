$ErrorActionPreference = "Stop"
$pluginDir = $PSScriptRoot
$phpDir = Join-Path $pluginDir "php"
$phpExe = Join-Path $phpDir "php.exe"
$composerPhar = Join-Path $pluginDir "composer.phar"

Write-Host "Starting SafeComms WordPress Standards Fixer..."

# --- 1. Bootstrap PHP ---
Write-Host "`n[1/4] Checking PHP..."

if (-not (Test-Path $phpExe)) {
    Write-Host "PHP not found locally. Installing portable PHP 8.3..."
    
    # Create directory
    if (-not (Test-Path $phpDir)) { New-Item -ItemType Directory -Path $phpDir | Out-Null }
    
    # Download PHP
    $phpZip = Join-Path $pluginDir "php.zip"
    $phpUrl = "https://windows.php.net/downloads/releases/archives/php-8.3.0-nts-Win32-vs16-x64.zip"
    
    try {
        Write-Host "Downloading PHP from $phpUrl..."
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -Uri $phpUrl -OutFile $phpZip
    } catch {
        Write-Error "Failed to download PHP. Please check your internet connection."
    }
    
    # Extract PHP
    Write-Host "Extracting PHP..."
    Expand-Archive -Path $phpZip -DestinationPath $phpDir -Force
    Remove-Item $phpZip
    
    # Configure php.ini
    Write-Host "Configuring php.ini..."
    $phpIni = Join-Path $phpDir "php.ini"
    Copy-Item (Join-Path $phpDir "php.ini-development") $phpIni
    
    $iniContent = Get-Content $phpIni
    $iniContent = $iniContent -replace ';extension_dir = "ext"', 'extension_dir = "ext"'
    $iniContent = $iniContent -replace ';extension=curl', 'extension=curl'
    $iniContent = $iniContent -replace ';extension=mbstring', 'extension=mbstring'
    $iniContent = $iniContent -replace ';extension=openssl', 'extension=openssl'
    $iniContent = $iniContent -replace ';extension=zip', 'extension=zip'
    Set-Content -Path $phpIni -Value $iniContent
    
    Write-Host "PHP installed successfully."
} else {
    Write-Host "Using local PHP at $phpExe"
}

# --- 2. Bootstrap Composer ---
Write-Host "`n[2/4] Checking Composer..."

if (-not (Test-Path $composerPhar)) {
    Write-Host "Composer not found locally. Downloading composer.phar..."
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -Uri "https://getcomposer.org/composer.phar" -OutFile $composerPhar
    } catch {
        Write-Error "Failed to download composer.phar."
    }
}

# Define commands using local PHP
$phpCmd = $phpExe
$composerCmd = "$phpExe $composerPhar"

# --- 3. Install Dependencies ---
Write-Host "`n[3/4] Installing Dependencies..."

if (-not (Test-Path (Join-Path $pluginDir "vendor/bin/phpcbf"))) {
    Write-Host "Installing development dependencies locally (vendor/)..."
    Invoke-Expression "$composerCmd install --working-dir='$pluginDir'"
} else {
    Write-Host "Dependencies already installed."
}

# --- 4. Run PHPCBF (Auto-fixer) ---
Write-Host "`n[4/5] Running PHPCBF to auto-fix coding standards..."
$phpcbf = Join-Path $pluginDir "vendor/bin/phpcbf"

if (Test-Path $phpcbf) {
    # phpcbf returns exit code 1 if it fixed files
    $proc = Start-Process -FilePath $phpExe -ArgumentList "$phpcbf --standard=phpcs.xml.dist" -WorkingDirectory $pluginDir -NoNewWindow -PassThru -Wait
    
    if ($proc.ExitCode -eq 1) {
        Write-Host "PHPCBF fixed some issues automatically." -ForegroundColor Green
    } elseif ($proc.ExitCode -eq 0) {
        Write-Host "No fixable issues found." -ForegroundColor Green
    } else {
        Write-Warning "PHPCBF encountered errors or could not fix all issues (Exit Code: $($proc.ExitCode))"
    }
} else {
    Write-Error "Could not find vendor/bin/phpcbf. Installation may have failed."
}

# --- 5. Run PHPCS (Report remaining issues) ---
Write-Host "`n[5/5] Running PHPCS to report remaining issues..."
$phpcs = Join-Path $pluginDir "vendor/bin/phpcs"

if (Test-Path $phpcs) {
    # Run PHPCS
    $reportFile = "phpcs-report.txt"
    $proc = Start-Process -FilePath $phpExe -ArgumentList "$phpcs --standard=phpcs.xml.dist --report=full --report-file=$reportFile -s" -WorkingDirectory $pluginDir -NoNewWindow -PassThru -Wait
    
    if ($proc.ExitCode -eq 0) {
        Write-Host "No coding standard violations found!" -ForegroundColor Green
    } else {
        Write-Warning "PHPCS found remaining issues. Report saved to $reportFile (Exit Code: $($proc.ExitCode))."
    }
} else {
    Write-Error "Could not find vendor/bin/phpcs."
}

Write-Host "`nDone!"
