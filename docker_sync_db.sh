#!/bin/bash
echo "========================================================="
echo "   OpenSquadron Docker DB Schema Sync Utility"
echo "========================================================="
echo "WARNING: This will forcefully update the database schema"
echo "to exactly match the current PHP entities. It will add missing"
echo "columns and DROP extra tables/columns not found in the code."
echo ""
read -p "Press Enter to continue..."

docker compose -f docker-compose.npm.yml exec app php bin/console doctrine:schema:update --force --complete

echo ""
echo "========================================================="
echo "       Sync Successful!"
echo "========================================================="
