Write-Host "====================================" -ForegroundColor Cyan
Write-Host " PHP SYNTAX CHECK" -ForegroundColor Cyan
Write-Host "====================================" -ForegroundColor Cyan

$errorCount = 0

# Find every .php file, skip vendor and node_modules
$phpFiles = Get-ChildItem -Path . -Recurse -Filter "*.php" -File |
    Where-Object { $_.FullName -notmatch "\\vendor\\" -and $_.FullName -notmatch "\\node_modules\\" }

foreach ($file in $phpFiles) {
    $output = php -l "$($file.FullName)" 2>&1 | Out-String
    if ($output -notmatch "No syntax errors detected") {
        Write-Host "ERROR in: $($file.FullName)" -ForegroundColor Red
        Write-Host $output
        Write-Host "------------------------------------"
        $errorCount++
    }
}

Write-Host ""
Write-Host "====================================" -ForegroundColor Cyan
Write-Host " SQL FILE CHECK" -ForegroundColor Cyan
Write-Host "====================================" -ForegroundColor Cyan

if (-not $env:DB_USER -or -not $env:DB_NAME) {
    Write-Host "Skipped: DB_USER / DB_NAME not set. See Step 2 below." -ForegroundColor Yellow
} else {
    $sqlFiles = Get-ChildItem -Path . -Recurse -Filter "*.sql" -File |
        Where-Object { $_.FullName -notmatch "\\vendor\\" }

    foreach ($sqlfile in $sqlFiles) {
        $tempSql = [System.IO.Path]::GetTempFileName()
        "START TRANSACTION;" | Out-File -Encoding utf8 $tempSql
        Get-Content $sqlfile.FullName | Out-File -Encoding utf8 -Append $tempSql
        "ROLLBACK;" | Out-File -Encoding utf8 -Append $tempSql

        $result = Get-Content $tempSql | mysql -u"$env:DB_USER" -p"$env:DB_PASS" "$env:DB_NAME" 2>&1
        Remove-Item $tempSql

        if ($result) {
            Write-Host "SQL ERROR in: $($sqlfile.FullName)" -ForegroundColor Red
            Write-Host $result
            Write-Host "------------------------------------"
            $errorCount++
        }
    }
}

Write-Host ""
Write-Host "====================================" -ForegroundColor Cyan
if ($errorCount -eq 0) {
    Write-Host "NO ERRORS FOUND. Safe to push." -ForegroundColor Green
} else {
    Write-Host "TOTAL ERRORS FOUND: $errorCount" -ForegroundColor Red
    Write-Host "Fix these before pushing to GitHub."
}
Write-Host "===================================="
