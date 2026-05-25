@echo off
echo =========================================================
echo       OpenSquadron DB Schema Sync Utility
echo =========================================================
echo WARNING: This will forcefully update the database schema
echo to exactly match the current PHP entities. It will add missing 
echo columns and DROP extra tables/columns not found in the code.
echo.
pause

C:\xampp\php\php.exe bin/console doctrine:schema:update --force --complete

echo.
echo =========================================================
echo        Sync Successful!
echo =========================================================
pause
