@echo off
echo =======================================================
echo Clearing Symfony Cache (Production Mode) for Docker
echo =======================================================

docker compose -f docker-compose.npm.yml exec app php bin/console cache:clear --env=prod

echo.
echo Cache cleared successfully!
echo You can now refresh your browser to see the changes.
pause
