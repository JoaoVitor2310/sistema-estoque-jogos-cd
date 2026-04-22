#!/usr/bin/env bash
# Instala os git hooks do projeto.
# Uso: bash scripts/install-hooks.sh

set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
HOOKS_DIR="$ROOT/.git/hooks"
SCRIPTS_DIR="$ROOT/scripts"

echo "Instalando git hooks..."

ln -sf "$SCRIPTS_DIR/pre-commit" "$HOOKS_DIR/pre-commit"
chmod +x "$HOOKS_DIR/pre-commit"

echo "✔ pre-commit instalado em .git/hooks/pre-commit"
echo ""
echo "O hook vai rodar automaticamente em todo 'git commit'."
echo "Para desativar pontualmente: git commit --no-verify"
