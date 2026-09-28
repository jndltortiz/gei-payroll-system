@echo off
title GEI Payroll — Restore Demo Database
color 0A
echo.
echo  ============================================
echo    GEI Payroll System — Demo Restore
echo  ============================================
echo.
echo  This will ERASE all current data and restore
echo  the demo snapshot (seeded thesis data).
echo.
set /p CONFIRM="  Type YES to continue: "
if /i NOT "%CONFIRM%"=="YES" (
    echo.
    echo  Cancelled. No changes were made.
    pause
    exit /b
)

echo.
echo  Restoring...
"C:\xampp\php\php.exe" "%~dp0restore_demo.php"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo  ============================================
    echo    DONE. Database restored successfully.
    echo  ============================================
    echo.
    echo  You can now open the system in your browser:
    echo  http://localhost/gei-payroll-system/
    echo.
) else (
    echo.
    echo  ERROR: Restore failed. Make sure XAMPP MySQL
    echo  is running and try again.
    echo.
)
pause
