#!/bin/bash
set -e

# Make sure permissions are correct for var folder without locking out the host user (UID 1000)
chmod -R 777 /var/www/html/var

# Auto-Updater & Custom Template Bootstrapper
if [ -d ".git" ]; then
    echo "Git repository detected! Auto-pulling latest changes..."
    git config --global --add safe.directory '*'
    HOST_UID=$(stat -c '%u' .)
    HOST_GID=$(stat -c '%g' .)
    
    git pull || echo "Git pull failed (possibly uncommitted local changes)."
    
    echo "Updating dependencies..."
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction || true
    chown -R $HOST_UID:$HOST_GID . || true
elif [ ! -f "bin/console" ]; then
    echo "App code is missing (Custom Template deployed with empty volume)! Bootstrapping from Git..."
    git clone https://github.com/BloodCorrupt/OpenSquadron.git /tmp/opensquadron
    cp -r /tmp/opensquadron/. .
    rm -rf /tmp/opensquadron
    
    echo "Installing dependencies..."
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction || true
    
    HOST_UID=$(stat -c '%u' .)
    HOST_GID=$(stat -c '%g' .)
    chown -R $HOST_UID:$HOST_GID . || true
    echo "Bootstrap complete!"
elif [ ! -d ".git" ]; then
    echo "App code is present but not a Git repository. Initializing Git for auto-updates..."
    git init
    git remote add origin https://github.com/BloodCorrupt/OpenSquadron.git
    git fetch
    git reset --mixed origin/main
    
    echo "Updating dependencies..."
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction || true
    
    HOST_UID=$(stat -c '%u' .)
    HOST_GID=$(stat -c '%g' .)
    chown -R $HOST_UID:$HOST_GID . || true
fi

# Ensure .env exists so Symfony's Dotenv parser doesn't crash with PathException
if [ ! -f ".env" ] && [ -f ".env.example" ]; then
    cp .env.example .env
    
    HOST_UID=$(stat -c '%u' .)
    HOST_GID=$(stat -c '%g' .)
    chown $HOST_UID:$HOST_GID .env || true
fi

echo "Waiting for database to be ready..."
# A simple loop to check if doctrine can connect to the database
# We retry up to 30 times (about 60 seconds)
max_tries=30
count=0
until php bin/console dbal:run-sql "SELECT 1"; do
    count=$((count+1))
    if [ $count -gt $max_tries ]; then
        echo "Database connection failed after $max_tries attempts. Exiting."
        exit 1
    fi
    echo "Database not ready yet... Retrying in 2 seconds ($count/$max_tries)..."
    sleep 2
done

echo "Database is ready!"

# Clear Symfony cache
echo "Clearing cache..."
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

# Run Doctrine Migrations
echo "Running database migrations..."
php bin/console doctrine:migrations:migrate -n --allow-no-migration || true

echo "Synchronizing missing database schema..."
php bin/console doctrine:schema:update --force || true

echo "Seeding initial database data..."
# INSERT IGNORE ensures this only runs once and never overwrites an existing admin
php bin/console doctrine:query:sql "INSERT IGNORE INTO admin (email, roles, password, account_type, team_enabled, is_verified, registration_enabled) VALUES ('admin@opensquadron.local', '[\"ROLE_ADMIN\"]', '\$2y\$13\$dCqXfD9w9XB/Dxr2r4DD5u3ihrcsCgpBLq2LOnyfyHWM8pj2Hc4Ty', 'super_admin', 0, 1, 1);" || true
php bin/console doctrine:query:sql "INSERT IGNORE INTO ai_setting (provider, is_active, created_at) VALUES ('openai', 0, NOW());" || true

echo "Starting Apache..."
# Execute the original command (CMD from Dockerfile or docker-compose)
exec apache2-foreground
