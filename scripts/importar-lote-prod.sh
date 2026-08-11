#!/usr/bin/env bash
#
# importar-lote-prod.sh — conduz o ciclo "lote da contábil → produção" a partir daqui.
#
# ⚠️ Este script é CONVENIÊNCIA DE OPERAÇÃO, NÃO fronteira de segurança. A contenção real mora na
#    VPS, no /usr/local/bin/bluejus-importar amarrado à chave por `command=` forçado. Qualquer coisa
#    que este arquivo tentar fazer além das quatro formas aceitas lá é recusada do outro lado.
#    Ver docs/specs/cobranca-importacao-prod-remota.md.
#
# Uso (da raiz do repositório):
#   scripts/importar-lote-prod.sh estado
#   scripts/importar-lote-prod.sh emitir                       # baixa o lote de hoje da contábil
#   scripts/importar-lote-prod.sh enviar   [AAAA-MM-DD]
#   scripts/importar-lote-prod.sh simular  [AAAA-MM-DD]        # as 3 carteiras, sem persistir
#   scripts/importar-lote-prod.sh importar <AAAA-MM-DD> <carteira> --confirmar
#
# carteira: top_life_1 | top_life_2 | amli_br_060
#
set -euo pipefail

readonly REMOTO=bluejus-importar          # alias do ~/.ssh/config, apontando para a chave dedicada
readonly BASE_LOTES='docs/gestao-cobrancas/planilhas atualizadas'
readonly EMISSOR='scripts/emitir-relatorios-contabil.sh'

readonly -a SUFIXOS=(
    'Dados_cadastrais'
    'Inadimplencias_detalhadas'
    'Receitas_detalhadas'
    'Acordos_detalhados_EM_ANDAMENTO'
    'Acordos_detalhados_LIQUIDADO'
)
readonly -a CARTEIRAS=(top_life_1 top_life_2 amli_br_060)

morrer() { printf '\n✗ %s\n' "$*" >&2; exit 1; }

uso() {
    cat <<'TXT'
Uso (da raiz do repositório):
  scripts/importar-lote-prod.sh estado
  scripts/importar-lote-prod.sh emitir                       # baixa o lote de hoje da contábil
  scripts/importar-lote-prod.sh enviar   [AAAA-MM-DD]
  scripts/importar-lote-prod.sh simular  [AAAA-MM-DD]        # as 3 carteiras, sem persistir
  scripts/importar-lote-prod.sh importar <AAAA-MM-DD> <carteira> --confirmar

carteira: top_life_1 | top_life_2 | amli_br_060
Sem data, assume hoje.
TXT
}

# O lote local de uma data. Nome fixado pelo emissor: <AAAA-MM-DD>-atualizado.
dir_lote() { printf '%s/%s-atualizado' "$BASE_LOTES" "$1"; }

# Confere os 15 antes de mandar. O wrapper confere de novo do outro lado — de propósito: aqui é para
# errar barato, lá é para não confiar em quem chama.
conferir_lote() {
    local dir=$1 faltando=0 pre suf arquivo

    [ -d "$dir" ] || morrer "lote não existe: $dir"

    for pre in "${CARTEIRAS[@]}"; do
        for suf in "${SUFIXOS[@]}"; do
            arquivo="${pre}_${suf}.xlsx"
            if [ -f "$dir/$arquivo" ]; then
                printf '  ✓ %s\n' "$arquivo"
            else
                printf '  ✗ FALTA %s\n' "$arquivo"
                faltando=$((faltando + 1))
            fi
        done
    done

    [ "$faltando" -eq 0 ] || morrer "$faltando de 15 arquivo(s) faltando — reemita antes de enviar"
}

carteira_valida() {
    local c
    for c in "${CARTEIRAS[@]}"; do
        [ "$1" = "$c" ] && return 0
    done

    return 1
}

remoto() { ssh "$REMOTO" "$@"; }

# ── ações ────────────────────────────────────────────────────────────────────────────────────────
acao_estado() {
    printf '=== estado em produção ===\n'
    remoto 'estado'
}

acao_emitir() {
    [ -x "$EMISSOR" ] || morrer "emissor não encontrado ou sem permissão de execução: $EMISSOR"
    printf '=== emitindo e baixando o lote da contábil ===\n'
    bash "$EMISSOR"
}

acao_enviar() {
    local data=$1
    local dir
    dir=$(dir_lote "$data")

    printf '=== conferindo o lote local (%s) ===\n' "$data"
    conferir_lote "$dir"

    # Envia exatamente os 15 nomes canônicos, nunca `*.xlsx`: um arquivo a mais na pasta (um
    # `~$tmp.xlsx` do Excel, um arquivo de outro dia) faria o wrapper recusar o lote inteiro.
    local -a nomes=()
    local pre suf
    for pre in "${CARTEIRAS[@]}"; do
        for suf in "${SUFIXOS[@]}"; do
            nomes+=("${pre}_${suf}.xlsx")
        done
    done

    printf '\n=== enviando para produção ===\n'
    tar -cz -C "$dir" "${nomes[@]}" | remoto "receber-lote $data"
}

acao_simular() {
    local data=$1 carteira falhas=0

    for carteira in "${CARTEIRAS[@]}"; do
        printf '\n\n############ SIMULANDO %s ############\n' "$carteira"
        remoto "simular $data $carteira" || falhas=$((falhas + 1))
    done

    printf '\n\n=== simulação concluída: %d carteira(s) com falha ===\n' "$falhas"
    [ "$falhas" -eq 0 ] || exit 1
}

acao_importar() {
    local data=$1 carteira=$2 confirmacao=$3

    carteira_valida "$carteira" || morrer "carteira inválida: $carteira"
    [ "$confirmacao" = '--confirmar' ] || morrer 'importar exige --confirmar literal'

    printf '⚠️  VALENDO: %s, lote %s. Isto PERSISTE em produção.\n' "$carteira" "$data"
    remoto "importar $data $carteira --confirmar"
}

# ── entrada ──────────────────────────────────────────────────────────────────────────────────────
hoje=$(date +%Y-%m-%d)

case "${1:-ajuda}" in
    estado)   acao_estado ;;
    emitir)   acao_emitir ;;
    enviar)   acao_enviar "${2:-$hoje}" ;;
    simular)  acao_simular "${2:-$hoje}" ;;
    importar)
        [ "$#" -eq 4 ] || morrer 'uso: importar <AAAA-MM-DD> <carteira> --confirmar'
        acao_importar "$2" "$3" "$4"
        ;;
    ajuda|-h|--help) uso ;;
    *) morrer "ação desconhecida: $1" ;;
esac
