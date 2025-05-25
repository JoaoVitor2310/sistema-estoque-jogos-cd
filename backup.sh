#!/bin/bash

# Variáveis do banco
POSTGRES_DB="sistema-estoque-cd"
POSTGRES_USER="postgres"

# Caminho de backup local
BACKUP_DIR="/home/sistema-estoque-jogos-cd/backups"
FILENAME="db_backup_$(date +%F).sql"

# Garante que a pasta existe
mkdir -p "$BACKUP_DIR"

# Gera o backup do banco
docker exec db-cd pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" > "$BACKUP_DIR/$FILENAME"

# Envia para o Google Drive (na pasta 'Meu Drive/Backup sistema')
rclone copy "$BACKUP_DIR/$FILENAME" "meudrive:Backup sistema"

# Remove backups locais com mais de 30 dias
find "$BACKUP_DIR" -type f -mtime +30 -name "*.sql" -delete