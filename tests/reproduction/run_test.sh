#!/bin/bash
docker-compose up -d
echo "Waiting for Nextcloud..."
sleep 60 # wait for init

# Enable encryption
docker-compose exec -u 33 nextcloud php occ app:enable encryption
docker-compose exec -u 33 nextcloud php occ encryption:enable
docker-compose exec -u 33 nextcloud php occ encryption:enable-master-key

docker-compose exec -u 33 nextcloud php occ app:enable context_chat
docker-compose exec -u 33 nextcloud php occ app:enable app_api

# Configure context_chat
docker-compose exec -u 33 nextcloud php occ config:app:set context_chat backend_init --value true

# Create test file
docker-compose exec -u 33 nextcloud php -r "file_put_contents('data/admin/files/test.txt', str_repeat('A', 1024*1024));"
docker-compose exec -u 33 nextcloud php occ files:scan --all

# Run Indexer
docker-compose exec -u 33 nextcloud php occ context_chat:index

# Check logs
docker-compose logs context_chat_backend
