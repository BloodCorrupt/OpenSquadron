#!/bin/bash
echo "========================================================="
echo "      OpenSquadron Docker Deploy/Update Utility"
echo "========================================================="

echo "[1/3] Creating Database (if not exists)..."
docker compose -f docker-compose.npm.yml exec app php bin/console doctrine:database:create --if-not-exists

echo "[2/3] Updating Database Schema via Migrations..."
docker compose -f docker-compose.npm.yml exec app php bin/console doctrine:migrations:migrate --no-interaction

echo "[3/3] Seeding Initial Data..."
docker compose -f docker-compose.npm.yml exec app php bin/console doctrine:query:sql "INSERT IGNORE INTO admin (email, roles, password, account_type, team_enabled, is_verified, registration_enabled) VALUES ('admin@opensquadron.local', '[\"ROLE_ADMIN\"]', '\$2y\$13\$dCqXfD9w9XB/Dxr2r4DD5u3ihrcsCgpBLq2LOnyfyHWM8pj2Hc4Ty', 'super_admin', 0, 1, 1);"
docker compose -f docker-compose.npm.yml exec app php bin/console doctrine:query:sql "INSERT IGNORE INTO ai_setting (provider, is_active, created_at) VALUES ('openai', 0, NOW());"

echo "========================================================="
echo "       Deployment Successful!"
echo "========================================================="
