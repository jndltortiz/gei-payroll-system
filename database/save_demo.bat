@echo off
title GEI Payroll — Save Demo Snapshot
color 0B
echo.
echo  ============================================
echo    GEI Payroll System — Save Demo Snapshot
echo  ============================================
echo.
echo  This will OVERWRITE demo_snapshot.sql with
echo  the current database state (including all
echo  attendance and payroll data).
echo.
set /p CONFIRM="  Type YES to continue: "
if /i NOT "%CONFIRM%"=="YES" (
    echo.
    echo  Cancelled. No changes were made.
    pause
    exit /b
)

echo.
echo  Saving snapshot...
"C:\xampp\mysql\bin\mysqldump.exe" -u root --routines --triggers --single-transaction gei_payroll_system > "%~dp0demo_snapshot.sql"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo  ============================================
    echo    DONE. Snapshot saved successfully.
    echo  ============================================
    echo.
    echo  demo_snapshot.sql now contains the current
    echo  database state. Running restore_demo.bat
    echo  will restore to THIS state.
    echo.
) else (
    echo.
    echo  ERROR: Snapshot failed. Make sure XAMPP MySQL
    echo  is running and try again.
    echo.
)
pause
