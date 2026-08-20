#!/bin/bash
set -euo pipefail

# Backup do banco — **local, na própria VPS**.
#
# O envio para o Google Drive foi removido em 2026-08-19: o dump sai em texto
# puro e carrega todas as `keys.key_code` em claro, então a segurança dele
# passava a ser a da conta Google. Enquanto não houver encriptação antes do
# envio (`gpg`/`age` ou `rclone crypt`), a cópia não sai daqui.
#
# Efeito colateral consciente: não existe mais cópia fora da máquina. Perder a
# VPS é perder o backup junto. Ver docs/IMPROVEMENTS.md.

# O cron roda com um PATH mínimo (tipicamente `/usr/bin:/bin`) e sem carregar
# perfil nenhum, então `docker` some e o backup falha calado. Na VPS isso já tinha
# sido resolvido à mão, com caminho absoluto no comando; declarar o PATH aqui
# resolve igual e não quebra se o binário mudar de lugar.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

# Variáveis do banco
POSTGRES_DB="sistema-estoque-cd"
POSTGRES_USER="postgres"

# Caminho de backup local
BACKUP_DIR="/var/www/sistema-estoque-jogos-cd/backups"
FILENAME="db_backup_$(date +%F).sql"

# Garante que a pasta existe
mkdir -p "$BACKUP_DIR"

# Gera o backup do banco
docker exec db-cd pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" > "$BACKUP_DIR/$FILENAME"

# Só o dono lê: o arquivo é o inventário inteiro de keys em texto puro.
chmod 600 "$BACKUP_DIR/$FILENAME"

# Remove backups locais com mais de 30 dias
find "$BACKUP_DIR" -type f -mtime +30 -name "*.sql" -delete
