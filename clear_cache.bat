@echo off
echo ==============================================
echo       OpenSquadron Cache Clear Utility
echo ==============================================
echo.
echo [1/2] Forcefully deleting var/cache directory...
rmdir /s /q var\cache

echo [2/2] Rebuilding Symfony cache...
C:\xampp\php\php.exe bin/console cache:clear

echo.
echo ==============================================
echo        Cache cleared successfully!
echo ==============================================
echo.
pause
