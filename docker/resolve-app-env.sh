#!/bin/bash
#
# Resolve o APP_ENV efetivo do container e o imprime na saída padrão.
#
# O `.env` é a fonte de verdade: é ele que o Laravel lê em runtime. A variável de
# ambiente do shell existe apenas porque o entrypoint roda antes do PHP, e as
# duas já divergiram em produção (incidente de 2026-08-24): o Compose não
# repassou `APP_ENV` ao shell do container, este caiu no default `local` e subiu
# o Vite dev server numa VPS onde o Laravel já se comportava como `production`.
# Ler o `.env` aqui elimina a segunda fonte de verdade — o shell passa a ser só
# fallback para quando o arquivo não existe ou não declara a variável.
#
# Uso: resolve-app-env.sh [caminho-do-.env]
#
set -u

ENV_FILE="${1:-/var/www/html/.env}"

from_file=""
if [ -r "$ENV_FILE" ]; then
    # Aceita espaços em volta do `=`, aspas simples ou duplas e comentário
    # inline. Linha comentada não casa: o padrão exige `APP_ENV` no começo.
    from_file="$(
        sed -n 's/^[[:space:]]*APP_ENV[[:space:]]*=[[:space:]]*//p' "$ENV_FILE" \
            | head -1 \
            | sed 's/[[:space:]]*#.*$//' \
            | tr -d "\"'" \
            | tr -d '[:space:]'
    )"
fi

if [ -n "$from_file" ]; then
    echo "$from_file"
else
    echo "${APP_ENV:-local}"
fi
