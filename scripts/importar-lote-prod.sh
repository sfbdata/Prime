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
readonly WRAPPER='scripts/vps/bluejus-importar'

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

# O wrapper instalado na VPS é uma CÓPIA, levada por scp. Mudar o arquivo aqui não muda nada lá, e o
# deploy também não leva — ele mora em /usr/local/bin do host, fora da imagem da aplicação. Sem esta
# conferência, editar aqui e esquecer de reinstalar deixa a importação rodando com o código velho,
# CALADA. A conferência mora toda deste lado de propósito: assim ela não exige mexer no wrapper.
conferir_wrapper() {
    local aqui la
    [ -f "$WRAPPER" ] || morrer "não achei $WRAPPER — rode da raiz do repositório"

    aqui=$(sha256sum "$WRAPPER" | cut -d' ' -f1)
    la=$(remoto 'estado' 2>/dev/null | awk '/sha256:/ {print $2; exit}')
    [ -n "$la" ] || morrer 'não consegui ler o sha256 do wrapper na VPS'
    [ "$aqui" != "$la" ] || return 0

    morrer "o wrapper da VPS NÃO é o deste repositório — reinstale antes de continuar.
    aqui: $aqui
    VPS:  $la

  No terminal local:
    scp $WRAPPER bluejus:/usr/local/bin/bluejus-importar
  E na VPS:
    chmod 700 /usr/local/bin/bluejus-importar"
}

# ── ações ────────────────────────────────────────────────────────────────────────────────────────
acao_estado() {
    printf '=== estado em produção ===\n'
    remoto 'estado'

    # Aqui é aviso, não morte: `estado` é justamente o comando que se usa para diagnosticar, e
    # recusar a rodar esconderia a informação de quem foi olhar por quê.
    local aqui la
    aqui=$(sha256sum "$WRAPPER" 2>/dev/null | cut -d' ' -f1)
    la=$(remoto 'estado' 2>/dev/null | awk '/sha256:/ {print $2; exit}')
    if [ -n "$aqui" ] && [ -n "$la" ] && [ "$aqui" != "$la" ]; then
        printf '\n⚠️  o wrapper da VPS não é o deste repositório — falta reinstalar (veja `ajuda`).\n'
        printf '    aqui: %s\n    VPS:  %s\n' "$aqui" "$la"
    fi
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

    conferir_wrapper
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

    conferir_wrapper
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
    conferir_wrapper

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
