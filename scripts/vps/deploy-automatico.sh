#!/usr/bin/env bash
# Deploy de uma versão oficial vX.Y.Z do BlueJus na VPS, com volta automática.
#
# Chamado pelo workflow Deploy (.github/workflows/deploy.yml), que envia este arquivo por SSH.
#
#   bash deploy-automatico.sh <modo> <tag> <sha>
#     modo: simular  → confere tudo, faz e confere um backup (depois apagado), lê a saúde do
#                      site; NÃO troca código, imagem, container nem banco.
#           publicar → o deploy de verdade.
#
# Ordem do "publicar" (cada passo só começa com o anterior ok):
#   1. trava (um deploy por vez) e pré-condições: checkout limpo, tag = sha, só para a frente;
#   2. guarda de migration: migration nova que APAGA (DROP TABLE/COLUMN, TRUNCATE, DELETE,
#      ALTER ... TYPE, RENAME) para o deploy automático. Essas são do dono, à mão;
#   3. backup do banco (pg_dump -Fc) conferido com pg_restore --list;
#   4. guarda a imagem atual do php e do worker (tag + container parado + docker save);
#   5. posiciona o checkout na tag (fast-forward) e roda scripts/deploy-prod-tls.sh;
#   6. confere: php saudável, a versão nova dentro do container, manutenção desligada e
#      https://bluejus.com.br/login respondendo 200;
#   7. se 5 ou 6 falharem: volta para a imagem e o commit anteriores e desliga a manutenção.
#      O banco NÃO volta: por isso o passo 2 manda para o dono toda migration que apaga ou
#      altera dado (a detecção é conservadora: na dúvida, para).
#
# Saídas: 0 ok · 1 falhou e VOLTOU para a versão anterior (ou o build quebrou e nada foi trocado)
# · 2 falhou ANTES de mudar qualquer coisa · 3 precisa do dono (migration destrutiva) · 4 falhou e
# a volta TAMBÉM falhou (urgente).
#
# O script se relança numa sessão própria (setsid) e grava em /var/log/jusprime-deploy/: se o SSH
# cair, o deploy termina sozinho em vez de morrer no meio.
set -euo pipefail

REPO="${JUSPRIME_DIR:-/opt/jusprime}"
URL="${JUSPRIME_URL:-https://bluejus.com.br}"
BACKUP_DB="${JUSPRIME_BACKUP_DEPLOY:-/opt/backups/deploy}"
BACKUP_IMG="${JUSPRIME_BACKUP_IMAGENS:-/opt/backups/imagens}"
LOGS="${JUSPRIME_LOGS_DEPLOY:-/var/log/jusprime-deploy}"
TRAVA="${JUSPRIME_TRAVA_DEPLOY:-/var/lock/jusprime-deploy.lock}"
PHP=jusprime_php_prod
WORKER=jusprime_worker_prod
DB=jusprime_db_prod
COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env.prod)

# ─── Migration destrutiva ──────────────────────────────────────────────────────
# Lê o PHP da migration (stdin) e imprime as instruções que apagam ou alteram dado existente.
# Olha o arquivo INTEIRO menos o corpo do down() (preUp/postUp e métodos auxiliares contam) e sem
# comentários. Conta como destrutivo, na sintaxe que o Doctrine GERA (sem a palavra COLUMN):
#   - qualquer DROP que não seja de CONSTRAINT, INDEX, DEFAULT ou NOT NULL
#     (ALTER TABLE x DROP coluna, DROP TABLE, DROP SEQUENCE, DROP FUNCTION...);
#   - mudança de tipo (ALTER x TYPE / SET DATA TYPE), RENAME, TRUNCATE, DELETE FROM, UPDATE ... SET.
# Na dúvida, marca: o custo de um falso positivo é o deploy parar e pedir o dono, não um dado
# perdido. O motivo de existir: a volta automática NÃO restaura o banco, só código e imagem.
instrucoes_destrutivas() {
    sed -e 's#^[[:space:]]*//.*##' -e 's#[[:space:]]//[^\x27]*$##' \
        | awk '
            /\/\*/ { comentario = 1 }
            comentario { if (/\*\//) comentario = 0; next }
            /function[[:space:]]+down[[:space:]]*\(/ { pulando = 1; profundidade = 0; abriu = 0 }
            pulando {
                n = gsub(/\{/, "{"); m = gsub(/\}/, "}")
                profundidade += n - m; if (n > 0) abriu = 1
                if (abriu && profundidade <= 0) pulando = 0
                next
            }
            { print }' \
        | grep -oiE "DROP[[:space:]]+(IF[[:space:]]+EXISTS[[:space:]]+)?[A-Za-z_\"]+|ALTER[[:space:]]+(COLUMN[[:space:]]+)?[A-Za-z_\"]+[[:space:]]+(SET[[:space:]]+DATA[[:space:]]+)?TYPE|RENAME[[:space:]]+[A-Za-z_\"]+|TRUNCATE|DELETE[[:space:]]+FROM|UPDATE[[:space:]]+[A-Za-z_\".]+[[:space:]]+SET" \
        | grep -viE "^DROP[[:space:]]+(IF[[:space:]]+EXISTS[[:space:]]+)?(CONSTRAINT|INDEX|DEFAULT|NOT)$" \
        || true
}

if [[ "${1:-}" == --detectar ]]; then   # uso de teste: --detectar <migration.php>...
    shift
    for f in "$@"; do
        achado="$(instrucoes_destrutivas < "$f" | tr '\n' ';')"
        [[ -n "$achado" ]] && echo "$(basename "$f"): $achado"
    done
    exit 0
fi

# ─── Relança em sessão própria, com log em arquivo ─────────────────────────────
if [[ "${1:-}" != --filho ]]; then
    mkdir -p "$LOGS"
    log="$LOGS/$(date -u +%Y%m%dT%H%M%SZ)-${2:-sem-tag}-${1:-sem-modo}.log"
    # O log nasce ANTES do filho: senão o tail às vezes abre o arquivo antes de ele existir, falha,
    # e (com set -e) o pai sai com erro enquanto o deploy segue sozinho em segundo plano.
    : > "$log"
    setsid bash "$0" --filho "$@" >> "$log" 2>&1 < /dev/null &
    filho=$!
    tail -n +1 -f --pid="$filho" "$log" || true   # mostrar o log é cortesia; quem decide é o wait
    wait "$filho"
    exit $?
fi
shift

MODO="${1:-}"; TAG="${2:-}"; SHA="${3:-}"
VERSAO="${TAG#v}"

etapa() { printf '\n==> %s\n' "$*"; }
falha_antes() { echo "::error::$*"; echo "NADA foi alterado em produção."; exit 2; }
# Até a troca de código (passo 5), qualquer comando que falhe sem tratamento (git fetch, docker
# save com disco cheio...) sai com 2: nada foi alterado em produção.
trap 'echo "::error::falha inesperada na linha $LINENO (antes da troca)."; echo "NADA foi alterado em produção."; exit 2' ERR

[[ "$MODO" == simular || "$MODO" == publicar ]] || falha_antes "modo inválido: '$MODO'"
[[ "$TAG" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || falha_antes "tag inválida: '$TAG'"
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || falha_antes "sha inválido: '$SHA'"

# ─── 1. Trava e pré-condições ──────────────────────────────────────────────────
exec 9> "$TRAVA"
flock -n 9 || falha_antes "outro deploy está em andamento (trava $TRAVA)."

cd "$REPO"
etapa "Pré-condições ($MODO de $TAG)"
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    git status --short --untracked-files=no
    falha_antes "o checkout $REPO tem arquivo versionado alterado: alguém mexeu à mão na VPS."
fi
ANTES="$(git rev-parse HEAD)"
git fetch --quiet --tags origin
[[ "$(git rev-parse "refs/tags/$TAG^{commit}" 2>/dev/null)" == "$SHA" ]] \
    || falha_antes "a tag $TAG não aponta para $SHA no GitHub."
if [[ "$ANTES" == "$SHA" ]]; then
    echo "Produção já está em $TAG ($SHA)."
elif ! git merge-base --is-ancestor "$ANTES" "$SHA"; then
    falha_antes "$TAG não está à frente do que roda hoje ($ANTES): deploy só anda para a frente."
fi
echo "Hoje: $(git describe --tags --always "$ANTES")  →  vai para: $TAG"
# Versão anterior ao deploy automático: o deploy-prod-tls.sh dela não conhece PULAR_GIT_PULL e
# faria `git pull`, publicando o que entrou no master DEPOIS da tag.
if [[ "$MODO" == publicar ]] && ! git show "$SHA:scripts/deploy-prod-tls.sh" | grep -q PULAR_GIT_PULL; then
    falha_antes "$TAG é anterior ao deploy automático (o deploy-prod-tls.sh dela faria git pull). Use o deploy manual."
fi

# ─── 2. Guarda de migration destrutiva ─────────────────────────────────────────
etapa "Migrations novas"
destrutivas=""
while IFS= read -r arquivo; do
    [[ -n "$arquivo" ]] || continue
    echo "  + $arquivo"
    achado="$(git show "$SHA:$arquivo" | instrucoes_destrutivas | tr '\n' ';')"
    if [[ -n "$achado" ]]; then
        echo "    destrutivo: $achado"
        destrutivas+="$arquivo "
    fi
done < <(git diff --name-only --diff-filter=A "$ANTES" "$SHA" -- 'app/migrations/*.php')
if [[ -n "$destrutivas" ]]; then
    echo "::error::Migration que apaga ou altera dado existente: $destrutivas"
    echo "Deploy automático PARADO antes de mexer em qualquer coisa. Esta versão é do dono, à mão"
    echo "(docs/versionamento.md, seção 7: dump antes e plano de volta)."
    exit 3
fi
echo "Nenhuma migration destrutiva."

# ─── 3. Backup do banco, conferido ─────────────────────────────────────────────
etapa "Backup do banco"
mkdir -p "$BACKUP_DB"; chmod 700 "$BACKUP_DB"
dump="$BACKUP_DB/jusprime-pre-$TAG-$(date -u +%Y%m%dT%H%M%SZ).dump"
docker exec "$DB" sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$dump" \
    || { rm -f "$dump"; falha_antes "pg_dump falhou."; }
objetos="$(docker exec -i "$DB" pg_restore --list < "$dump" | grep -c ' TABLE ' || true)"
[[ "$objetos" -gt 0 ]] || { rm -f "$dump"; falha_antes "o backup não tem tabelas (pg_restore --list)."; }
echo "Backup: $dump ($(du -h "$dump" | cut -f1), $objetos tabelas)"
if [[ "$MODO" == simular ]]; then
    rm -f "$dump"
    echo "(simulação: backup conferido e apagado)"
else
    ls -1t "$BACKUP_DB"/jusprime-pre-*.dump 2>/dev/null | tail -n +6 | xargs -r rm -f   # guarda 5
fi

saude() {
    local codigo
    codigo="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$URL/login" || true)"
    echo "  $URL/login → HTTP $codigo"
    [[ "$codigo" == 200 ]]
}

if [[ "$MODO" == simular ]]; then
    etapa "Saúde de produção (simulação)"
    saude || { echo "::error::produção não respondeu 200 agora (nada foi alterado)."; exit 2; }
    echo "Simulação de $TAG ok: o deploy real pode seguir."
    exit 0
fi

# ─── 4. Guarda a versão atual (rollback) ───────────────────────────────────────
etapa "Guardando a versão atual para a volta automática"
declare -A IMG_NOME IMG_ID
for c in "$PHP" "$WORKER"; do
    IMG_NOME[$c]="$(docker inspect -f '{{.Config.Image}}' "$c")"
    IMG_ID[$c]="$(docker inspect -f '{{.Image}}' "$c")"
    docker tag "${IMG_ID[$c]}" "jusprime-rollback:${c}-pre-$TAG"
    docker rm -f "jusprime_rollback_${c}_pre_$TAG" >/dev/null 2>&1 || true
    docker create --name "jusprime_rollback_${c}_pre_$TAG" "jusprime-rollback:${c}-pre-$TAG" >/dev/null
    echo "  $c: ${IMG_ID[$c]:7:12} guardada como jusprime-rollback:${c}-pre-$TAG"
done
mkdir -p "$BACKUP_IMG"
docker save "jusprime-rollback:$PHP-pre-$TAG" "jusprime-rollback:$WORKER-pre-$TAG" \
    | gzip > "$BACKUP_IMG/jusprime-pre-$TAG.tar.gz"
ls -1t "$BACKUP_IMG"/jusprime-pre-v*.tar.gz 2>/dev/null | tail -n +3 | xargs -r rm -f   # guarda 2
echo "  cópia em arquivo: $BACKUP_IMG/jusprime-pre-$TAG.tar.gz ($(du -h "$BACKUP_IMG/jusprime-pre-$TAG.tar.gz" | cut -f1))"
# Imagens e containers de rollback: só os dos 2 deploys mais recentes (tag com nome não é
# apagada pelo `docker image prune` do deploy, e o disco da VPS é apertado).
while IFS= read -r antigo; do
    [[ -n "$antigo" ]] || continue
    resto="${antigo#jusprime_rollback_}"                       # <container>_pre_vX.Y.Z
    docker rm -f "$antigo" >/dev/null 2>&1 || true
    docker rmi "jusprime-rollback:${resto%_pre_v*}-pre-v${resto##*_pre_v}" >/dev/null 2>&1 || true
    echo "  rollback antigo removido: $antigo"
done < <(docker ps -a --filter name=jusprime_rollback_ --format '{{.CreatedAt}}|{{.Names}}' \
    | grep -E '\|jusprime_rollback_.+_pre_v[0-9]+\.[0-9]+\.[0-9]+$' | sort -r | cut -d'|' -f2 | tail -n +5)
# Só os do formato deste script (…_pre_vX.Y.Z): travas criadas à mão em outros deploys (preloop1,
# preargv...) não são dele e ficam. Medido no deploy da v2.1.0: o filtro antigo, só por prefixo,
# apagou os containers preloop1/preloop2 de 13/10 (as cópias em arquivo deles ficaram).

voltar() {
    trap - ERR
    etapa "VOLTANDO para a versão anterior"
    local ok=1
    for c in "$PHP" "$WORKER"; do
        docker tag "jusprime-rollback:${c}-pre-$TAG" "${IMG_NOME[$c]}" || ok=0
    done
    git reset --quiet --hard "$ANTES" || ok=0   # checkout conferido limpo no passo 1
    "${COMPOSE[@]}" up -d --no-build php worker || ok=0
    for _ in $(seq 1 36); do
        [[ "$(docker inspect -f '{{.State.Health.Status}}' "$PHP" 2>/dev/null)" == healthy ]] && break
        sleep 5
    done
    rm -f nginx/maintenance/maintenance.on
    if [[ "$ok" == 1 ]] && saude; then
        echo "::error::$TAG falhou e produção VOLTOU para $(git describe --tags --always "$ANTES")."
        exit 1
    fi
    echo "::error::$TAG falhou e a VOLTA TAMBÉM FALHOU. Produção precisa do dono agora."
    exit 4
}

# ─── 5. Publica ────────────────────────────────────────────────────────────────
etapa "Publicando $TAG"
trap 'voltar' ERR
git merge --quiet --ff-only "$SHA"
if ! PULAR_GIT_PULL=1 bash scripts/deploy-prod-tls.sh; then
    # Build quebrado sai ANTES da manutenção, sem tocar container: nesse caso a volta é só do git.
    if [[ ! -f nginx/maintenance/maintenance.on && "$(docker inspect -f '{{.Image}}' "$PHP")" == "${IMG_ID[$PHP]}" ]]; then
        git reset --quiet --hard "$ANTES"
        # Build parcial pode ter retagueado a imagem de um serviço: devolve o nome à imagem em uso.
        for c in "$PHP" "$WORKER"; do docker tag "${IMG_ID[$c]}" "${IMG_NOME[$c]}" || true; done
        echo "::error::o build de $TAG falhou; nenhum container foi trocado e o site seguiu no ar."
        exit 1
    fi
    voltar
fi

# ─── 6. Confere ────────────────────────────────────────────────────────────────
etapa "Conferindo $TAG em produção"
ok=1
[[ "$(docker inspect -f '{{.State.Health.Status}}' "$PHP")" == healthy ]] || { echo "  php não está healthy"; ok=0; }
docker exec "$PHP" grep -q "public const ATUAL = '$VERSAO';" /var/www/app/src/Shared/VersaoDoSistema.php \
    || { echo "  o container não tem a versão $VERSAO"; ok=0; }
[[ ! -f nginx/maintenance/maintenance.on ]] || { echo "  a manutenção continua ligada"; ok=0; }
saude || ok=0
[[ "$ok" == 1 ]] || voltar

echo
echo "✅ Produção em $TAG ($SHA). Backup: $dump"
