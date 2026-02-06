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

# Register Mock Backend via OCC
echo "Cleaning up previous registrations..."
docker-compose exec -u 33 nextcloud php occ app_api:app:unregister context_chat_backend --force --no-interaction || true
docker-compose exec -u 33 nextcloud php occ app_api:daemon:unregister manual_install --no-interaction || true

echo "Registering Mock Backend..."

# Register daemon config
# Using just hostname for daemon, allowing app port to be appended correctly
docker-compose exec -u 33 nextcloud php occ app_api:daemon:register manual_install "Manual Install" manual-install http context_chat_backend http://localhost --no-interaction || true

# Register the app
# We use --force-scopes to avoid interactive prompts
docker-compose exec -u 33 nextcloud php occ app_api:app:register context_chat_backend manual_install --json-info '{"id":"context_chat_backend","name":"Context Chat Backend","deploy_method":"manual_install","version":"1.0.0","secret":"secret","host":"context_chat_backend","port":23000,"scopes":[],"protocol":"http","system_app":0}' --force-scopes --no-interaction || true

# Enable the app (it was listed as disabled)
echo "Enabling Context Chat Backend..."
docker-compose exec -u 33 nextcloud php occ app_api:app:enable context_chat_backend --no-interaction || true

# Debug: List registered apps
echo "Listing AppAPI apps..."
docker-compose exec -u 33 nextcloud php occ app_api:app:list

# Configure context_chat
docker-compose exec -u 33 nextcloud php occ config:app:set context_chat backend_init --value true

# Create test file
echo "Creating test file via VFS (Encrypted)..."
docker-compose cp create_test_file.php nextcloud:/var/www/html/create_test_file.php
docker-compose exec -u 33 nextcloud php /var/www/html/create_test_file.php

# Verify file existence via PHP
echo "Verifying file existence in Nextcloud VFS..."
if docker-compose exec -u 33 nextcloud php -r 'define("NC_CLI_MODE", true); require_once "/var/www/html/console.php"; echo \OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder("admin")->nodeExists("test.txt") ? "YES" : "NO";' | grep -q "YES"; then
    echo "SUCCESS: test.txt found in Nextcloud VFS."
else
    echo "FAILURE: test.txt NOT found in Nextcloud VFS."
    exit 1
fi

# Run Indexer
echo "Running Scan (Direct Indexing)..."
docker-compose exec -u 33 nextcloud php occ context_chat:scan admin

# Check logs
echo "Checking backend logs..."
docker-compose logs --no-log-prefix context_chat_backend

echo "Test completed successfully."
