#!/bin/bash
set -e

# Start containers
docker-compose up -d

echo "Waiting for Nextcloud to be ready..."
max_retries=30
count=0
while [ $count -lt $max_retries ]; do
    if docker-compose exec -u 33 nextcloud php occ status | grep -q "installed: true"; then
        echo "Nextcloud is installed and ready."
        break
    fi
    echo "Nextcloud not ready yet... waiting (Attempt $((count+1))/$max_retries)"
    sleep 10
    count=$((count+1))
done

if [ $count -eq $max_retries ]; then
    echo "Timeout waiting for Nextcloud to install."
    exit 1
fi

echo "Configuring Nextcloud..."

# Enable encryption
docker-compose exec -u 33 nextcloud php occ app:enable encryption
docker-compose exec -u 33 nextcloud php occ encryption:enable
docker-compose exec -u 33 nextcloud php occ encryption:enable-master-key

# Enable apps
docker-compose exec -u 33 nextcloud php occ app:enable context_chat
docker-compose exec -u 33 nextcloud php occ app:enable app_api

# Configure context_chat
docker-compose exec -u 33 nextcloud php occ config:app:set context_chat backend_init --value true

# Create test file
echo "Creating test file..."
docker-compose exec -u 33 nextcloud php -r "if (!is_dir('data/admin/files')) { mkdir('data/admin/files', 0770, true); } file_put_contents('data/admin/files/test.txt', str_repeat('A', 1024*1024));"
docker-compose exec -u 33 nextcloud php occ files:scan --all

# Run Indexer
echo "Running IndexerJob..."
docker-compose exec -u 33 nextcloud php occ context_chat:index

# Check logs
echo "Checking backend logs..."
docker-compose logs context_chat_backend
