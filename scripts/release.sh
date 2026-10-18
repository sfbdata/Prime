#!/usr/bin/env bash
# Confere e extrai o que uma release precisa, a partir da fonte única de versão.
#
#   scripts/release.sh validar X.Y.Z   o código está pronto para a tag vX.Y.Z?
#   scripts/release.sh notas X.Y.Z     imprime a seção X.Y.Z do CHANGELOG (corpo da release)
#
# "Pronto" quer dizer: VersaoDoSistema::ATUAL == X.Y.Z, o CHANGELOG tem "## [X.Y.Z] - AAAA-MM-DD"
# como versão mais recente, com data real e texto, o link de referência dela e o de [Não lançado]
# comparando a partir de vX.Y.Z — e a seção [Não lançado] VAZIA. Essa última é o que impede a tag
# de levar mudança alheia: o workflow marca a ponta do master no disparo, e um PR de produto
# mergeado entre o PR de release e o disparo escreve em [Não lançado], e aqui a release é recusada.
# É o mesmo contrato do tests/Arquitetura/VersaoDoSistemaTest, mais a exigência de [Não lançado]
# vazio (que só vale na hora da release), repetido aqui porque o workflow o roda ANTES da tag.
#
# Não cria tag nem release, não fala com o GitHub. Política: docs/versionamento.md.
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
CHANGELOG="$RAIZ/CHANGELOG.md"
CONSTANTE="$RAIZ/app/src/Shared/VersaoDoSistema.php"
REPOSITORIO="https://github.com/sfbdata/Prime"

falhar() { echo "::error::$*" >&2; exit 1; }

acao="${1:-}"
versao="${2:-}"

if [[ ! "$versao" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    echo "uso: $0 {validar|notas} X.Y.Z   (SemVer sem o \"v\"; recebido: '$versao')" >&2
    exit 64
fi
versao_re="${versao//./\\.}"

secao() {
    # Do título "## [X.Y.Z] - data" até o próximo "## [" ou a primeira definição de link.
    awk -v alvo="## [$versao] - " '
        index($0, alvo) == 1 { dentro = 1; next }
        dentro && (/^## \[/ || /^\[[^]]+\]: /) { exit }
        dentro { print }
    ' "$CHANGELOG" | sed -e '/./,$!d' | tac | sed -e '/./,$!d' | tac
}

validar() {
    [[ -f "$CHANGELOG" ]] || falhar "CHANGELOG.md ausente."
    [[ -f "$CONSTANTE" ]] || falhar "VersaoDoSistema.php ausente."

    local atual
    atual="$(sed -n "s/^[[:space:]]*public const ATUAL = '\(.*\)';/\1/p" "$CONSTANTE")"
    [[ "$atual" == "$versao" ]] \
        || falhar "VersaoDoSistema::ATUAL é '$atual', não '$versao'. O PR de release muda os dois juntos."

    local titulo data
    titulo="$(grep -E "^## \[$versao_re\] - " "$CHANGELOG" || true)"
    [[ -n "$titulo" ]] || falhar "CHANGELOG sem a seção '## [$versao] - AAAA-MM-DD'."
    data="${titulo##* - }"
    [[ "$data" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] && date -d "$data" +%F >/dev/null 2>&1 \
        && [[ "$(date -d "$data" +%F)" == "$data" ]] \
        || falhar "Data inválida na seção $versao: '$data'."

    local mais_recente
    mais_recente="$(grep -m1 -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' "$CHANGELOG" | tr -d '#[] ')"
    [[ "$mais_recente" == "$versao" ]] \
        || falhar "A versão mais recente do CHANGELOG é '$mais_recente', não '$versao'."

    [[ -n "$(secao | tr -d '[:space:]')" ]] || falhar "A seção $versao do CHANGELOG está vazia."

    local pendente
    pendente="$(awk '
        $0 == "## [Não lançado]" { dentro = 1; next }
        dentro && /^## \[/ { exit }
        dentro { print }
    ' "$CHANGELOG" | tr -d '[:space:]')"
    [[ -z "$pendente" ]] \
        || falhar "[Não lançado] tem conteúdo: o master tem mudança que não é da $versao. Inclua-a na release ou lance depois."

    # Arquivo em mudancas/ (fora o README) é mudança de PR que ainda não entrou numa versão: a tag
    # levaria código que o CHANGELOG da $versao não descreve.
    local soltos
    soltos="$(find "$RAIZ/mudancas" -maxdepth 1 -name '*.md' ! -name README.md -printf '%f ' 2>/dev/null || true)"
    [[ -z "$soltos" ]] \
        || falhar "Há mudanças em mudancas/ que não são da $versao ($soltos). Lance-as numa versão nova."

    grep -qE "^\[$versao_re\]: $REPOSITORIO/(compare/v[0-9.]+\.\.\.v|releases/tag/v)$versao_re$" "$CHANGELOG" \
        || falhar "Falta o link de referência '[$versao]: ...' no fim do CHANGELOG."
    grep -qxF "[Não lançado]: $REPOSITORIO/compare/v$versao...HEAD" "$CHANGELOG" \
        || falhar "O link de [Não lançado] deve comparar a partir de v$versao."

    echo "ok: v$versao pronta para tag ($data)"
}

case "$acao" in
    validar) validar ;;
    notas)   validar >/dev/null && secao ;;
    *) echo "uso: $0 {validar|notas} X.Y.Z" >&2; exit 64 ;;
esac
