#!/usr/bin/env bash
# Testa scripts/qa-arquivos-novos.sh num repositório descartável.
# Rodar: bash scripts/testar-qa-arquivos-novos.sh
set -euo pipefail

script="$(cd "$(dirname "$0")" && pwd)/qa-arquivos-novos.sh"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
cd "$tmp"

falhas=0
confere() {  # confere <descrição> <esperado> <obtido>
    if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FALHA $1"; echo "  esperado: [$2]"; echo "  obtido:   [$3]"; falhas=$((falhas + 1)); fi
}

git init -q -b master .
git config user.email t@t; git config user.name t
mkdir -p app/src/Legado app/src/Pasta app/tests app/config app/migrations
printf '<?php\n// legado com conteúdo suficiente para a detecção de renomeação\nclass A {}\n' > app/src/Legado/A.php
echo '<?php // alterado' > app/src/Alterado.php
git add -A; git commit -qm base
base=$(git rev-parse HEAD)

git mv app/src/Legado/A.php app/src/Pasta/A.php
echo '<?php // mais' >> app/src/Alterado.php
echo '<?php // novo' > app/src/Pasta/Novo.php
echo '<?php // com acento' > app/src/Pasta/Ação.php
echo '<?php // teste' > app/tests/NovoTest.php
echo '<?php // migration' > app/migrations/Version1.php
echo '<?php return [];' > app/config/bundles.php
echo 'nao php' > app/src/leia.txt
git add -A; git commit -qm frente

confere "CI: só os criados, sem o renomeado, o alterado e o fora de escopo" \
    "$(printf 'migrations/Version1.php\nsrc/Pasta/Ação.php\nsrc/Pasta/Novo.php\ntests/NovoTest.php' | LC_ALL=C sort)" \
    "$("$script" "$base" | LC_ALL=C sort)"

echo '<?php // ainda não rastreado' > app/src/Pasta/Rascunho.php
confere "local: inclui o não rastreado" \
    "$(printf 'migrations/Version1.php\nsrc/Pasta/Ação.php\nsrc/Pasta/Novo.php\nsrc/Pasta/Rascunho.php\ntests/NovoTest.php' | LC_ALL=C sort)" \
    "$("$script" --local "$base" | LC_ALL=C sort)"

set +e
"$script" 0123456789012345678901234567890123456789 >/dev/null 2>&1; codigo=$?
set -e
confere "base desconhecida recusa (saída 2)" 2 "$codigo"

[ "$falhas" -eq 0 ] && echo "todos os casos passaram" || { echo "$falhas falha(s)"; exit 1; }
