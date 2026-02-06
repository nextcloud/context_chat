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
echo "Registering Mock Backend..."

# Register daemon config
# Syntax: <name> <display-name> <accepts-deploy-id> <protocol> <host> <nextcloud_url>
docker-compose exec -u 33 nextcloud php occ app_api:daemon:register manual_install "Manual Install" manual-install http context_chat_backend:23000 http://localhost || true

# Register the app
# We use --force-scopes to avoid interactive prompts
docker-compose exec -u 33 nextcloud php occ app_api:app:register context_chat_backend manual_install --json-info '{"id":"context_chat_backend","name":"Context Chat Backend","deploy_method":"manual_install","version":"1.0.0","secret":"secret","host":"context_chat_backend","port":23000,"scopes":[],"protocol":"http","system_app":0}' --force-scopes || true

# Debug: List registered apps
echo "Listing AppAPI apps..."
docker-compose exec -u 33 nextcloud php occ app_api:app:list

# Configure context_chat
docker-compose exec -u 33 nextcloud php occ config:app:set context_chat backend_init --value true

# Create test file
echo "Creating test file..."
docker-compose exec -u 33 nextcloud php -r "if (!is_dir('data/admin/files')) { mkdir('data/admin/files', 0770, true); } file_put_contents('data/admin/files/test.txt', str_repeat('A', 1024*1024));"
docker-compose exec -u 33 nextcloud php occ files:scan --all

# Run Indexer
echo "Running Scan (Direct Indexing)..."
docker-compose exec -u 33 nextcloud php occ context_chat:scan admin

# Check logs
echo "Checking backend logs..."
docker-compose logs context_chat_backend
