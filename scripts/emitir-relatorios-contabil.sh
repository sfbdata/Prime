#!/usr/bin/env bash
#
# EMITE E BAIXA os relatórios da contábil (Group Condomínios) das 3 carteiras.
# Uso:  bash scripts/emitir-relatorios-contabil.sh
# Saída: docs/gestao-cobrancas/planilhas atualizadas/<AAAA-MM-DD>-atualizado/  (15 arquivos)
#
# Rode da RAIZ do repositório. Credencial em docs/gestao-cobrancas/credencial.txt (gitignored).
#
# ─────────────────────────────────────────────────────────────────────────────────────────────
# ARMADILHAS QUE ESTE SCRIPT JÁ RESOLVE — todas medidas, todas custaram uma rodada perdida:
#
# 1. 🔴 `exibirNossoNumero` e `exibirCompetencia` vêm FALSE no formulário deles. Sem os dois, o
#    arquivo sai SEM o Nosso Número e SEM a competência — que juntos são a chave de deduplicação
#    dos importadores. O arquivo parece perfeito e faria a próxima importação DUPLICAR dívida.
# 2. 🔴 Emitir Acordos sem `tipoSituacaoAcordo` traz os CANCELADOS junto (129 em 07/08/2026).
#    Cancelado NÃO entra (decisão do dono) e esse ramo do importador nunca rodou com dado real.
#    Por isso aqui são DUAS emissões por carteira: EM_ANDAMENTO e LIQUIDADO.
#    ⚠️ Mandar `"TODOS"` explícito devolve HTTP 500. Omitir traz tudo, inclusive cancelado.
# 3. 🔴 Receitas SEM `recebimentoInicio`/`recebimentoFim`: "Período de recebimento: Todos" é a
#    AUSÊNCIA dos campos. Foi um recorte de 7 meses que escondeu 5 anos de histórico.
# 4. 🔴 O contexto de condomínio vem do JWT, NUNCA do payload — `condominioContextoId` é IGNORADO.
#    Já saiu relatório do condomínio errado com HTTP 200. Por isso o download confere o
#    `condominioNome` do histórico antes de gravar cada arquivo.
# 5. 🟠 `personalizarAcrescimos: false` → o servidor usa os percentuais do CADASTRO do condomínio,
#    que é a autoridade sobre eles. Mandar juros/multa/honorario SOBRESCREVE o cadastro.
# 6. 🟠 O Cloudflare deles bloqueia cliente HTTP do Python (Error 1010) e aceita curl.
# ─────────────────────────────────────────────────────────────────────────────────────────────
set -uo pipefail

CRED="docs/gestao-cobrancas/credencial.txt"
BASE="https://app.groupcondominios.com.br"
HOJE=$(date +%Y-%m-%d)
INICIO_MES=$(date +%Y-%m-01)
ANO=$(date +%Y); MES=$(date +%-m)
DEST="docs/gestao-cobrancas/planilhas atualizadas/${HOJE}-atualizado"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

[ -r "$CRED" ] || { echo "credencial não encontrada em $CRED"; exit 1; }
mkdir -p "$DEST"

# Condomínios no sistema deles → prefixo do arquivo local → nome esperado no histórico
declare -A PREFIXO=( [1]="top_life_1" [4]="top_life_2" [3]="amli_br_060" )
declare -A NOME=( [1]="APLC - TOP LIFE 1" [4]="APLC - TOP LIFE 2" [3]="AMLI BR 060" )

# ── login ────────────────────────────────────────────────────────────────────────────────────
python3 - "$CRED" > "$TMP/login.json" <<'PY'
import sys, json, re
txt = open(sys.argv[1], encoding='utf-8').read()
u = re.search(r'^usu.rio:(.*)$', txt, re.M).group(1).strip()
p = re.search(r'^senha:(.*)$',   txt, re.M).group(1).strip()
sys.stdout.write(json.dumps({"username": u, "password": p, "remember": False}))
PY

TOKEN=$(curl -s -X POST "$BASE/orquestrador/api/authenticate" -H 'Content-Type: application/json' \
  --data-binary "@$TMP/login.json" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("token") or "")')
[ -n "$TOKEN" ] || { echo "LOGIN FALHOU — confira a credencial (a conta é da secretária; se ela trocou a senha, para tudo)"; exit 1; }
echo "login OK"

trocar_contexto() {  # $1 = id do condomínio
  TOKEN=$(curl -s -X POST "$BASE/orquestrador/api/authenticate/alterar-condominio-contexto/$1" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    | python3 -c 'import sys,json;print(json.load(sys.stdin).get("token") or "")')
  [ -n "$TOKEN" ]
}

# ── 1. EMISSÃO ───────────────────────────────────────────────────────────────────────────────
echo ""; echo "=== EMITINDO (5 relatórios por carteira) ==="
for cid in 1 4 3; do
  trocar_contexto "$cid" || { echo "  troca de contexto falhou ($cid)"; continue; }
  echo "  ${PREFIXO[$cid]}:"

  curl -s -o /dev/null -w "    cadastro       HTTP %{http_code}\n" -X POST "$BASE/backend/api/relatorio/condominio/dados-condominos" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d '{"tipoLancamentoUnidade":"TODAS_UNIDADES","unidadeId":null,"grupoUnidadeId":null,"exibirIdCOM21":false,"tipo":"XLSX"}'

  curl -s -o /dev/null -w "    inadimplencia  HTTP %{http_code}\n" -X POST "$BASE/backend/api/relatorio/inadimplencia-detalhada" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d @- <<JSON
{"dataInadimplencia":"$HOJE","tipoCompetencia":"TODAS","competencia":"$INICIO_MES",
 "anoCompetencia":$ANO,"mesCompetencia":$MES,"dataVencimentoInicio":null,"dataVencimentoFim":null,
 "unidadeCliente":"TODOS","unidadeId":null,"clienteId":null,"grupoUnidadesId":null,
 "hasGrupoCondominio":false,"grupoCondominioId":null,"condominioId":null,"tipoCondominio":"TODOS",
 "exibirSacado":true,"exibirGrafico":false,"incluirHonorarioAcordoVencido":false,
 "classePlanoContaList":null,"apenasAcordos":false,"exibirSubjudice":false,
 "incluirCobrancasVencimento":true,"personalizarAcrescimos":false,
 "exibirNossoNumero":true,"exibirCompetencia":true,
 "juros":null,"multa":null,"honorario":null,"tipo":"XLSX"}
JSON

  curl -s -o /dev/null -w "    receitas       HTTP %{http_code}\n" -X POST "$BASE/backend/api/relatorio/contas-receber-detalhadas" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d @- <<JSON
{"situacao":"BAIXADA","classeclassePlanoContaListConta":null,
 "tipoCompetencia":"TODAS","competencia":"$INICIO_MES","anoCompetencia":$ANO,"mesCompetencia":$MES,
 "vencimentoInicio":null,"vencimentoFim":null,"recebimentoInicio":null,"recebimentoFim":null,
 "liquidacaoInicio":null,"liquidacaoFim":null,"unidadeCliente":"TODOS","unidadeId":null,
 "grupoUnidadesId":null,"clienteId":null,"fornecedorId":null,"condominioId":null,
 "grupoCondominioId":null,"hasGrupoCondominio":false,"tipoCondominio":"TODOS",
 "exibirSacado":true,"exibirParcelas":true,"dataOrdenacaoRelatorio":null,"apenasAcordos":false,
 "exibirSubjudice":false,"conta":null,"filtroContas":"TODOS","contaProvisionamentoId":null,
 "contaId":null,"orientacaoRelatorio":"PAISAGEM","tipo":"XLSX"}
JSON

  for sit in EM_ANDAMENTO LIQUIDADO; do
    curl -s -o /dev/null -w "    acordos $sit HTTP %{http_code}\n" -X POST "$BASE/backend/api/relatorio/acordo/detalhado/assincrono" \
      -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
      -d "{\"tipoSituacaoAcordo\":\"$sit\",\"tipoLancamentoUnidade\":\"TODAS_UNIDADES\",\"unidadeId\":null,\"grupoUnidadeId\":null,\"tipo\":\"XLSX\"}"
    sleep 3   # dá ordem às duas emissões do mesmo tipo (o download associa pela ordem do histórico)
  done
done

echo ""; echo "aguardando o processamento no sistema deles (15-20s por relatório)…"
sleep 75

# ── 2. DOWNLOAD ──────────────────────────────────────────────────────────────────────────────
echo ""; echo "=== BAIXANDO ==="
declare -A SIMPLES=(
  [DADOS_CADASTRAIS_CONDOMINOS]="Dados_cadastrais"
  [INADIMPLENCIAS_DETALHADAS]="Inadimplencias_detalhadas"
  [RECEITAS_DETALHADAS_UNIDADE_CLIENTE]="Receitas_detalhadas"
)

baixar() {  # $1 = uuid · $2 = caminho de destino
  local url
  url=$(curl -s "$BASE/backend/api/historico-visualizacao-relatorio/baixar-arquivo-historico/$1" -H "Authorization: Bearer $TOKEN")
  case "$url" in https://*) curl -s "$url" -o "$2"; return 0 ;; *) return 1 ;; esac
}

for cid in 1 4 3; do
  trocar_contexto "$cid" || continue
  pre="${PREFIXO[$cid]}"

  for tipo in "${!SIMPLES[@]}"; do
    linha=$(curl -s "$BASE/backend/api/historico-visualizacao-relatorio?tipoRelatorio=$tipo&page=0&size=8&sort=id,desc" \
      -H "Authorization: Bearer $TOKEN" | python3 -c "
import sys, json
d = json.load(sys.stdin); regs = d if isinstance(d, list) else d.get('content', [])
for r in regs:
    if r.get('dataSolicitacao') == '$HOJE' and r.get('status') == 'FINALIZADO' and str(r.get('condominioId')) == '$cid':
        print(r['uuid'], r['condominioNome'], sep='|'); break
")
    [ -n "$linha" ] || { printf "  %-12s %-26s AINDA NAO FINALIZADO — rode o script de novo\n" "$pre" "${SIMPLES[$tipo]}"; continue; }
    uuid=${linha%%|*}; nome=${linha#*|}
    [ "$nome" = "${NOME[$cid]}" ] || { printf "  🔴 %-12s %-26s CONDOMINIO ERRADO: %s\n" "$pre" "${SIMPLES[$tipo]}" "$nome"; continue; }
    if baixar "$uuid" "$DEST/${pre}_${SIMPLES[$tipo]}.xlsx"; then
      printf "  ✅ %-12s %-26s %s\n" "$pre" "${SIMPLES[$tipo]}" "$(du -h "$DEST/${pre}_${SIMPLES[$tipo]}.xlsx" | cut -f1)"
    fi
  done

  # Acordos: as 2 emissões de hoje. A MAIS RECENTE (id maior) é LIQUIDADO — foi a última disparada.
  mapfile -t ac < <(curl -s "$BASE/backend/api/historico-visualizacao-relatorio?tipoRelatorio=ACORDOS_DETALHADOS&page=0&size=10&sort=id,desc" \
    -H "Authorization: Bearer $TOKEN" | python3 -c "
import sys, json
d = json.load(sys.stdin); regs = d if isinstance(d, list) else d.get('content', [])
for r in [x for x in regs if x.get('dataSolicitacao') == '$HOJE' and x.get('status') == 'FINALIZADO' and str(x.get('condominioId')) == '$cid'][:2]:
    print(r['uuid'], r['condominioNome'], sep='|')
")
  if [ "${#ac[@]}" -lt 2 ]; then
    printf "  %-12s %-26s só %d de 2 finalizados — rode o script de novo\n" "$pre" "Acordos" "${#ac[@]}"
  else
    i=0
    for rot in LIQUIDADO EM_ANDAMENTO; do
      uuid=${ac[$i]%%|*}; nome=${ac[$i]#*|}
      if [ "$nome" = "${NOME[$cid]}" ] && baixar "$uuid" "$DEST/${pre}_Acordos_detalhados_${rot}.xlsx"; then
        printf "  ✅ %-12s %-26s %s\n" "$pre" "Acordos $rot" "$(du -h "$DEST/${pre}_Acordos_detalhados_${rot}.xlsx" | cut -f1)"
      else
        printf "  🔴 %-12s %-26s falhou (%s)\n" "$pre" "Acordos $rot" "$nome"
      fi
      i=$((i+1))
    done
  fi
done

echo ""; echo "=== $(ls "$DEST" | wc -l) arquivo(s) em $DEST (esperado: 15) ==="
echo ""
echo "PRÓXIMO PASSO OBRIGATÓRIO — nada é confiável antes disto:"
echo "  1. validar o recorte de cada arquivo (o rodapé 'Filtros:') com ValidadorRodapeFiltros;"
echo "  2. conferir que os Acordos_*_EM_ANDAMENTO/LIQUIDADO não trazem 'Cancelado' em aba nenhuma;"
echo "  3. só então levar para a VPS e importar (ordem: deploy → docker cp → importar)."
