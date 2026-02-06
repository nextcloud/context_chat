#!/bin/bash
set -e

# Start containers
docker-compose up -d

echo "Waiting for container to accept commands..."
sleep 10

# Check if Nextcloud is installed
echo "Checking Nextcloud status..."
if docker-compose exec -u 33 nextcloud php occ status | grep -q "installed: true"; then
    echo "Nextcloud is already installed."
else
    echo "Nextcloud is not installed. Installing..."
    docker-compose exec -u 33 nextcloud php occ maintenance:install \
        --database "sqlite" \
        --admin-user "admin" \
        --admin-pass "password"
fi

echo "Waiting for Nextcloud to be fully ready..."
max_retries=10
count=0
while [ $count -lt $max_retries ]; do
    if docker-compose exec -u 33 nextcloud php occ status | grep -q "installed: true"; then
        echo "Nextcloud is ready."
        break
    fi
    echo "Waiting for status update... (Attempt $((count+1))/$max_retries)"
    sleep 5
    count=$((count+1))
done

if [ $count -eq $max_retries ]; then
    echo "Timeout waiting for Nextcloud to be ready."
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
