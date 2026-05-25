@echo off
echo ==============================================
echo       OpenSquadron Deploy/Update Utility
echo ==============================================

set "DATABASE_URL=mysql://root:@127.0.0.1:3306/opensquadron?serverVersion=10.4.32-MariaDB&charset=utf8mb4"

echo [1/3] Creating Database (if not exists)...
C:\xampp\php\php.exe bin/console doctrine:database:create --if-not-exists

echo [2/3] Updating Database Schema...
C:\xampp\php\php.exe bin/console doctrine:migrations:migrate --no-interaction

echo [3/3] Seeding Initial Data...
C:\xampp\php\php.exe bin/console doctrine:query:sql "INSERT IGNORE INTO admin (email, roles, password, account_type, team_enabled, is_verified, registration_enabled) VALUES ('admin@opensquadron.local', '[\"ROLE_ADMIN\"]', '$2y$13$dCqXfD9w9XB/Dxr2r4DD5u3ihrcsCgpBLq2LOnyfyHWM8pj2Hc4Ty', 'super_admin', 0, 1, 1);"
C:\xampp\php\php.exe bin/console doctrine:query:sql "INSERT IGNORE INTO ai_setting (provider, is_active, created_at) VALUES ('openai', 0, NOW());"

echo ==============================================
echo        Deployment Successful!
echo ==============================================
pause
