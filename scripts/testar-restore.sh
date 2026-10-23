#!/bin/bash
# =============================================================================
# Teste de integração do scripts/restore.sh
# =============================================================================
# Destrutivo APENAS contra recursos criados aqui: um postgres:15 descartável, um volume próprio
# e containers alpine que fazem o papel de worker/php, todos com nome
# bluejus_restore_test_<pid>_<ts>. Nada de produção é tocado.
# Os volumes de trabalho que o restore.sh cria (bluejus_restore_<pid>_*) são conferidos
# depois de cada cenário (não pode sobrar nenhum) e removidos no fim.
#
# Uso: bash scripts/testar-restore.sh   (no host, precisa de docker, flock e python3)
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN="bluejus_restore_test_${$}_$(date +%s)"
DB_CONTAINER="${RUN}_db"
WORKER_CONTAINER="${RUN}_worker"
APP_CONTAINER="${RUN}_app"
UPLOADS_VOLUME="${RUN}_uploads"
WORK=$(mktemp -d)
TRAVA="$WORK/deploy.lock"
POSTGRES_USER=tester
POSTGRES_PASSWORD=tester_password
POSTGRES_DB=bluejus_restore_test
VOLUMES_ANTES=$(docker volume ls -q --filter name=bluejus_restore_ | sort)
CENARIOS=0
OLD_FP=""
TRAVA_PID=""

volumes_do_restore() {
    docker volume ls -q --filter name=bluejus_restore_ | grep -v -F -x "$UPLOADS_VOLUME" | sort \
        | comm -13 <(printf '%s\n' "$VOLUMES_ANTES") - || true
}

cleanup() {
    set +e
    [[ -n "$TRAVA_PID" ]] && kill "$TRAVA_PID" 2>/dev/null
    docker rm -f "$DB_CONTAINER" "$WORKER_CONTAINER" "$APP_CONTAINER" >/dev/null 2>&1
    docker volume rm "$UPLOADS_VOLUME" >/dev/null 2>&1
    volumes_do_restore | xargs -r docker volume rm >/dev/null 2>&1
    rm -rf "$WORK"
}
trap cleanup EXIT

fail() { echo "FALHOU: $*" >&2; [[ -s "$WORK/saida.log" ]] && sed 's/^/  | /' "$WORK/saida.log" >&2; exit 1; }
assert_eq() { [[ "$1" == "$2" ]] || fail "esperado '$2', obtido '$1' ($3)"; }
cenario() { CENARIOS=$((CENARIOS + 1)); echo "$CENARIOS. $*"; }

sql() {
    local banco="$1"; shift
    docker exec -i "$DB_CONTAINER" sh -c \
        'b="$1"; PGPASSWORD="$POSTGRES_PASSWORD" exec psql -X -q -At -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$b"' \
        sh "$banco" <<<"$*"
}

docker volume create "$UPLOADS_VOLUME" >/dev/null
docker run -d --name "$DB_CONTAINER" \
    -e "POSTGRES_USER=$POSTGRES_USER" \
    -e "POSTGRES_PASSWORD=$POSTGRES_PASSWORD" \
    -e "POSTGRES_DB=$POSTGRES_DB" \
    postgres:15 >/dev/null

# pg_isready responde durante o initdb (servidor temporário); espera por uma consulta real duas
# vezes seguidas para não pegar o reinício do entrypoint.
pronto=0
for _ in $(seq 1 60); do
    if sql "$POSTGRES_DB" 'SELECT 1' >/dev/null 2>&1; then
        pronto=$((pronto + 1)); [[ $pronto -ge 2 ]] && break
    fi
    sleep 1
done
[[ $pronto -ge 2 ]] || fail 'PostgreSQL de teste não iniciou'
sql postgres 'CREATE ROLE leitor_teste LOGIN;'

cat >"$WORK/test.env" <<EOF
POSTGRES_USER="$POSTGRES_USER"
POSTGRES_PASSWORD='$POSTGRES_PASSWORD'
POSTGRES_DB=$POSTGRES_DB
EOF
sed 's/^POSTGRES_USER=.*/POSTGRES_USER=outro_usuario/' "$WORK/test.env" >"$WORK/user-divergente.env"

# ---------------------------------------------------------------------------
# Artefatos
# ---------------------------------------------------------------------------
mkdir -p "$WORK/current" "$WORK/separate/pastas/sub dir" "$WORK/bad" "$WORK/outro" "$WORK/mal" "$WORK/vazio" "$WORK/grande"
printf 'novo-atual\n' >"$WORK/separate/pastas/atual.txt"
printf 'com espaço\n' >"$WORK/separate/pastas/sub dir/arquivo com espaço.pdf"
printf 'oculto\n' >"$WORK/separate/.oculto"

cat >"$WORK/valid.sql" <<'EOF'
CREATE TABLE estado (valor text NOT NULL);
INSERT INTO estado VALUES ('novo');
CREATE TABLE "user" (id int);
INSERT INTO "user" SELECT generate_series(1, 3);
CREATE TABLE tenant (id int);
INSERT INTO tenant VALUES (1);
EOF
gzip -c "$WORK/valid.sql" >"$WORK/current/database.sql.gz"
printf 'metadados ficticios\n' >"$WORK/current/backup_info.txt"
# Formato atual: igual ao scripts/backup.sh (tar.gz só com banco; uploads em TAR do busybox).
tar -czf "$WORK/jusprime_20261009_120000.tar.gz" -C "$WORK/current" database.sql.gz backup_info.txt
docker run --rm -v "$WORK/separate:/source:ro" -v "$WORK:/backup" alpine \
    sh -c "tar -cf /backup/uploads_20261009_120000.tar -C /source . && chown $(id -u):$(id -g) /backup/uploads_20261009_120000.tar"
cp "$WORK/jusprime_20261009_120000.tar.gz" "$WORK/outro/jusprime_20261009_120000.tar.gz"
cp "$WORK/uploads_20261009_120000.tar" "$WORK/outro/uploads-renomeado.tar"

# Dump sem nenhuma tabela (SQL válido, mas não é backup do sistema).
printf 'SELECT 1;\n' | gzip -c >"$WORK/vazio/database.sql.gz"
tar -czf "$WORK/sem-tabelas.tar.gz" -C "$WORK/vazio" database.sql.gz
# Banco grande o bastante (incompressível) para estourar ulimit -f 1 na extração do host.
{ cat "$WORK/valid.sql"; printf -- '-- %s\n' "$(head -c 12000 /dev/urandom | base64 -w0)"; } | gzip -c >"$WORK/grande/database.sql.gz"
tar -czf "$WORK/banco-grande.tar.gz" -C "$WORK/grande" database.sql.gz

# Dump do deploy automático (pg_dump -Fc), gerado de um banco auxiliar com o estado "novo".
sql postgres 'CREATE DATABASE fonte_dump;'
sql fonte_dump "$(cat "$WORK/valid.sql")"
docker exec "$DB_CONTAINER" sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d fonte_dump -Fc' \
    >"$WORK/jusprime-pre-v9.9.9-20261009T120000Z.dump"
sql postgres 'DROP DATABASE fonte_dump;'
head -c 200 "$WORK/jusprime-pre-v9.9.9-20261009T120000Z.dump" >"$WORK/truncado.dump"

# Corrompidos.
printf 'nao e gzip' >"$WORK/bad/database.sql.gz"
tar -czf "$WORK/corrupt-db.tar.gz" -C "$WORK/bad" database.sql.gz
printf 'CREATE TABLE quebrada (;' | gzip -c >"$WORK/bad/database.sql.gz"
tar -czf "$WORK/sql-error.tar.gz" -C "$WORK/bad" database.sql.gz
printf 'tar corrompido' >"$WORK/uploads-corrupt.tar"

# Histórico (uploads/ embutido, com um arquivo do uid 33 como o www-data da imagem) e maliciosos:
# travessia, caminho absoluto, link simbólico e link físico.
python3 -I - "$WORK" <<'PY'
import io, os, sys, tarfile
work = sys.argv[1]
db = open(f"{work}/current/database.sql.gz", "rb").read()

def add(t, name, data=b"x", uid=None):
    info = tarfile.TarInfo(name); info.size = len(data); info.mode = 0o644
    if uid is not None: info.uid = info.gid = uid
    t.addfile(info, io.BytesIO(data))

def pasta(t, name):
    info = tarfile.TarInfo(name); info.type = tarfile.DIRTYPE; info.mode = 0o755; t.addfile(info)

def link(t, name, kind, target):
    info = tarfile.TarInfo(name); info.type = kind; info.linkname = target; t.addfile(info)

with tarfile.open(f"{work}/historical.tar.gz", "w:gz") as t:
    add(t, "database.sql.gz", db)
    pasta(t, "uploads"); pasta(t, "uploads/pastas")
    add(t, "uploads/pastas/historico.txt", b"novo-historico\n", uid=33)
    add(t, "uploads/pastas/grande.bin", os.urandom(8192))
    add(t, "backup_info.txt", b"metadados\n")
with tarfile.open(f"{work}/truncado.tar.gz", "w:gz") as t:
    add(t, "database.sql.gz", db)
with open(f"{work}/truncado.tar.gz", "r+b") as f:
    f.truncate(60)
with tarfile.open(f"{work}/mal/travessia.tar.gz", "w:gz") as t:
    add(t, "database.sql.gz", db); add(t, "uploads/../../fora.txt")
with tarfile.open(f"{work}/mal/absoluto.tar.gz", "w:gz") as t:
    add(t, "database.sql.gz", db); add(t, "/tmp/absoluto.txt")
with tarfile.open(f"{work}/mal/symlink.tar.gz", "w:gz") as t:
    add(t, "database.sql.gz", db); link(t, "uploads/link", tarfile.SYMTYPE, "/etc/passwd")
with tarfile.open(f"{work}/mal/intruso.tar.gz", "w:gz") as t:
    add(t, "database.sql.gz", db); add(t, "uploads/ok.txt"); add(t, "etc/cron.d/intruso")
with tarfile.open(f"{work}/mal/uploads-travessia.tar", "w") as t:
    add(t, "./pastas/ok.txt"); add(t, "../fora.txt")
with tarfile.open(f"{work}/mal/uploads-symlink.tar", "w") as t:
    add(t, "./pastas/ok.txt"); link(t, "./pastas/link", tarfile.SYMTYPE, "../../etc/passwd")
with tarfile.open(f"{work}/mal/uploads-hardlink.tar", "w") as t:
    add(t, "./pastas/ok.txt"); link(t, "./pastas/duro", tarfile.LNKTYPE, "/etc/shadow")
PY

# ---------------------------------------------------------------------------
# Auxiliares
# ---------------------------------------------------------------------------
restore() {
    env DB_CONTAINER="$DB_CONTAINER" UPLOADS_VOLUME="$UPLOADS_VOLUME" ENV_FILE="${ENV_TESTE:-$WORK/test.env}" \
        WORKER_CONTAINER="$WORKER_CONTAINER" APP_CONTAINER="$APP_CONTAINER" JUSPRIME_TRAVA_DEPLOY="$TRAVA" \
        RESTORE_MODO_TESTE="${MODO_TESTE:-1}" \
        bash "$ROOT/scripts/restore.sh" "$@"
}
# Roda o restore respondendo $1; grava a saída em saida.log; devolve o código do restore.
restore_resp() {
    local resposta="$1"; shift
    printf '%s\n' "$resposta" | restore "$@" >"$WORK/saida.log" 2>&1
}
deve_recusar() {
    local motivo="$1"; shift
    if restore_resp CONFIRMO "$@"; then fail "$motivo foi aceito"; fi
    assert_old
}
fingerprint_uploads() {
    docker run --rm -v "$UPLOADS_VOLUME:/source:ro" alpine sh -ec \
        'cd /source && find . -type f -exec sha256sum {} + | LC_ALL=C sort'
}
reset_old() {
    local nome
    for nome in $(sql postgres "SELECT datname FROM pg_database WHERE datname LIKE '${POSTGRES_DB}\\_%'"); do
        sql postgres "DROP DATABASE \"$nome\";"
    done
    sql "$POSTGRES_DB" "DROP TABLE IF EXISTS estado; CREATE TABLE estado (valor text NOT NULL); INSERT INTO estado VALUES ('antigo');" 2>/dev/null
    docker run --rm -v "$UPLOADS_VOLUME:/dest" alpine sh -ec \
        'rm -rf /dest/* /dest/.[!.]* /dest/..?* 2>/dev/null || true; mkdir -p /dest/pastas;
         printf antigo > /dest/antigo.txt; head -c 4096 /dev/urandom > /dest/pastas/velho.bin'
    OLD_FP=$(fingerprint_uploads)
}
bancos_extras() { sql postgres "SELECT datname FROM pg_database WHERE datname LIKE '${POSTGRES_DB}\\_%' ORDER BY 1"; }
sem_sobras() {
    local sobra
    sobra=$(volumes_do_restore)
    [[ -z "$sobra" ]] || fail "volumes de trabalho do restore sobraram: $sobra"
    sobra=$(sql postgres "SELECT datname FROM pg_database WHERE datname LIKE '${POSTGRES_DB}\\_restore\\_%'")
    [[ -z "$sobra" ]] || fail "banco descartável sobrou: $sobra"
    sobra=$(sql postgres "SELECT datname FROM pg_database WHERE NOT datallowconn AND datname NOT LIKE 'template%'")
    [[ -z "$sobra" ]] || fail "banco ficou com conexões bloqueadas: $sobra"
}
assert_old() {
    local db
    db=$(sql "$POSTGRES_DB" 'SELECT valor FROM estado' 2>&1) || fail "banco '$POSTGRES_DB' inacessível: $db"
    assert_eq "$db" antigo 'banco anterior preservado'
    [[ "$(fingerprint_uploads)" == "$OLD_FP" ]] || fail 'conteúdo dos uploads anteriores mudou (checksum)'
    assert_eq "$(bancos_extras)" '' 'nenhum banco de trabalho criado'
    sem_sobras
}
banco_preservado() { sed -n "s/^Banco anterior preservado como '\\([^']*\\)'.*/\\1/p" "$WORK/saida.log"; }
assert_new_db() {
    local antigo
    assert_eq "$(sql "$POSTGRES_DB" 'SELECT valor FROM estado')" novo 'banco restaurado'
    antigo=$(bancos_extras)
    [[ "$antigo" =~ ^${POSTGRES_DB}_before_restore_[0-9]+$ ]] || fail "banco anterior não preservado (extras: '$antigo')"
    assert_eq "$(banco_preservado)" "$antigo" 'saída informa o banco preservado'
    assert_eq "$(sql "$antigo" 'SELECT valor FROM estado')" antigo 'banco _before_restore_ tem o conteúdo anterior'
    grep -q "dropdb -U \"\$POSTGRES_USER\" $antigo" "$WORK/saida.log" || fail 'saída não ensina a apagar o banco anterior'
    sem_sobras
}
uploads_atuais() {
    docker run --rm -v "$UPLOADS_VOLUME:/source:ro" alpine sh -ec 'cd /source && find . -type f | sort'
}
permissoes() {
    sql postgres "SELECT coalesce((SELECT string_agg(x::text, ',' ORDER BY x::text) FROM unnest(d.datacl) x), '<padrão>')
        || ' ' || coalesce((SELECT string_agg(coalesce(r.rolname, '*') || ':' || s.setconfig::text, ';' ORDER BY 1)
                            FROM pg_db_role_setting s LEFT JOIN pg_roles r ON r.oid = s.setrole
                           WHERE s.setdatabase = d.oid), '')
        FROM pg_database d WHERE d.datname = '$1'"
}

# ---------------------------------------------------------------------------
# Cenários
# ---------------------------------------------------------------------------
cenario 'formato histórico (uploads embutidos, extraídos no container preservando dono)'
reset_old
restore_resp CONFIRMO "$WORK/historical.tar.gz" || fail 'restore histórico falhou'
assert_new_db
assert_eq "$(uploads_atuais)" "$(printf './pastas/grande.bin\n./pastas/historico.txt')" 'uploads do histórico'
assert_eq "$(docker run --rm -v "$UPLOADS_VOLUME:/source:ro" alpine stat -c %u /source/pastas/historico.txt)" 33 'dono numérico preservado'

cenario 'formato atual: banco + uploads_<TS>.tar irmão (TAR do busybox, como o backup.sh)'
reset_old
restore_resp CONFIRMO "$WORK/jusprime_20261009_120000.tar.gz" || fail 'restore atual falhou'
assert_new_db
assert_eq "$(uploads_atuais)" "$(printf './.oculto\n./pastas/atual.txt\n./pastas/sub dir/arquivo com espaço.pdf')" 'uploads do formato atual'
grep -q "volume de volta: bluejus_restore_" "$WORK/saida.log" || fail 'nome do volume de volta não foi impresso'

cenario 'formato atual com uploads.tar informado explicitamente (outro nome/pasta)'
reset_old
restore_resp CONFIRMO "$WORK/outro/jusprime_20261009_120000.tar.gz" "$WORK/outro/uploads-renomeado.tar" \
    || fail 'restore com uploads explícito falhou'
assert_new_db
docker run --rm -v "$UPLOADS_VOLUME:/source:ro" alpine test -f /source/pastas/atual.txt || fail 'upload ausente'

cenario '--database-only preserva uploads; tabela comparativa antes do CONFIRMO'
reset_old
restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz" || fail 'somente banco falhou'
assert_new_db
[[ "$(fingerprint_uploads)" == "$OLD_FP" ]] || fail 'modo somente banco mexeu nos uploads'
grep -Eq '^  tabelas +[0-9]+ +3$' "$WORK/saida.log" || fail 'tabela comparativa sem contagem de tabelas'
grep -Eq '^  usuários \("user"\) +[0-9-]+ +3$' "$WORK/saida.log" || fail 'tabela comparativa sem contagem de "user"'
grep -Eq '^  escritórios \(tenant\) +[0-9-]+ +1$' "$WORK/saida.log" || fail 'tabela comparativa sem contagem de tenant'

cenario 'dump do deploy (.dump, pg_dump -Fc) restaura só o banco'
reset_old
restore_resp CONFIRMO "$WORK/jusprime-pre-v9.9.9-20261009T120000Z.dump" || fail 'restore do .dump falhou'
assert_new_db
[[ "$(fingerprint_uploads)" == "$OLD_FP" ]] || fail '.dump mexeu nos uploads'

cenario 'banco com conexão aberta é trocado mesmo assim'
reset_old
docker exec -d "$DB_CONTAINER" sh -c \
    'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT pg_sleep(600)"'
sleep 1
assert_eq "$(sql postgres "SELECT count(*) FROM pg_stat_activity WHERE datname = '$POSTGRES_DB'")" 1 'conexão de teste aberta'
restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz" || fail 'restore com conexão aberta falhou'
assert_new_db

cenario 'permissões de banco (GRANT e SET) são replicadas no banco novo'
reset_old
sql postgres "REVOKE ALL ON DATABASE $POSTGRES_DB FROM PUBLIC;
    GRANT TEMPORARY ON DATABASE $POSTGRES_DB TO PUBLIC;
    GRANT CONNECT ON DATABASE $POSTGRES_DB TO leitor_teste;
    ALTER DATABASE $POSTGRES_DB SET work_mem = '8MB';
    ALTER ROLE leitor_teste IN DATABASE $POSTGRES_DB SET statement_timeout = '5s';
    ALTER DATABASE $POSTGRES_DB SET search_path = \"\$user\", public, \"Outro Schema\";
    ALTER ROLE leitor_teste IN DATABASE $POSTGRES_DB SET search_path = app, public;"
esperado=$(permissoes "$POSTGRES_DB")
[[ "$esperado" == *leitor_teste=c/* && "$esperado" == *work_mem=8MB* && "$esperado" == *'search_path=app, public'* ]] || fail "preparação de permissões falhou: $esperado"
restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz" || fail 'restore com ACL falhou'
assert_new_db
assert_eq "$(permissoes "$POSTGRES_DB")" "$esperado" 'ACL e SETs replicados'
assert_eq "$(docker exec "$DB_CONTAINER" psql -X -At -U leitor_teste -d "$POSTGRES_DB" -c 'SHOW statement_timeout')" 5s 'leitor conecta e herda o SET'
assert_eq "$(docker exec "$DB_CONTAINER" psql -X -At -U leitor_teste -d "$POSTGRES_DB" -c 'SHOW search_path')" 'app, public' 'search_path de lista replicado'
# shellcheck disable=SC2016  # "$user" é literal do PostgreSQL, não variável do shell
assert_eq "$(sql "$POSTGRES_DB" 'SHOW search_path')" '"$user", public, "Outro Schema"' 'search_path do banco replicado'
sql postgres "GRANT ALL ON DATABASE $POSTGRES_DB TO PUBLIC; ALTER DATABASE $POSTGRES_DB RESET ALL;
    ALTER ROLE leitor_teste IN DATABASE $POSTGRES_DB RESET ALL;"

cenario 'o comando de desfazer impresso no fim volta ao banco anterior'
reset_old
restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz" || fail 'restore para desfazer falhou'
antigo=$(banco_preservado)
sed -n '/^# --- desfazer: início ---$/,/^# --- desfazer: fim ---$/p' "$WORK/saida.log" >"$WORK/desfazer.sh"
grep -q "RENAME TO \"$POSTGRES_DB\"" "$WORK/desfazer.sh" || fail 'comando de desfazer ausente ou sem os nomes reais'
descartado="${POSTGRES_DB}_restaurado_${antigo##*_}"
# Falha do desfazer (nome de destino já ocupado): nada troca e os DOIS bancos ficam reabertos.
sql postgres "CREATE DATABASE \"$descartado\";"
# O bloco não pode terminar em comando falho (fecharia um terminal com set -e): roda com set -e.
bash -e "$WORK/desfazer.sh" >"$WORK/desfazer.log" 2>&1 || { cat "$WORK/desfazer.log" >&2; fail 'bloco de desfazer abortou sob set -e'; }
grep -q 'DESFAZER FALHOU: nada foi trocado' "$WORK/desfazer.log" || { cat "$WORK/desfazer.log" >&2; fail 'desfazer não avisou a falha'; }
assert_eq "$(sql "$POSTGRES_DB" 'SELECT valor FROM estado')" novo 'desfazer que falhou não trocou nada'
assert_eq "$(sql postgres "SELECT count(*) FROM pg_database WHERE NOT datallowconn AND datname IN ('$POSTGRES_DB', '$antigo')")" 0 'desfazer que falhou reabriu os dois bancos'
sql postgres "DROP DATABASE \"$descartado\";"
# Desfazer de verdade, com uma conexão aberta no _before_restore_ (o script o reabriu).
docker exec -d "$DB_CONTAINER" sh -c \
    'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$1" -c "SELECT pg_sleep(600)"' sh "$antigo"
sleep 1
assert_eq "$(sql postgres "SELECT count(*) FROM pg_stat_activity WHERE datname = '$antigo'")" 1 'conexão aberta no _before_restore_'
inicio=$SECONDS
bash "$WORK/desfazer.sh" >"$WORK/desfazer.log" 2>&1 || { cat "$WORK/desfazer.log" >&2; fail 'bloco de desfazer saiu com erro'; }
duracao=$((SECONDS - inicio))
(( duracao < 10 )) || fail "desfazer com conexão aberta demorou ${duracao}s (o laço não enxerga as conexões saindo)"
grep -q 'DESFAZER OK' "$WORK/desfazer.log" || { cat "$WORK/desfazer.log" >&2; fail 'desfazer não informou DESFAZER OK'; }
! grep -q 'WARNING' "$WORK/desfazer.log" || { cat "$WORK/desfazer.log" >&2; fail 'desfazer imprimiu WARNING'; }
assert_eq "$(sql "$POSTGRES_DB" 'SELECT valor FROM estado')" antigo 'desfazer devolveu o banco anterior'
assert_eq "$(sql postgres "SELECT count(*) FROM pg_database WHERE datname = '$antigo'")" 0 'o _before_restore_ voltou ao nome original'
assert_eq "$(sql postgres "SELECT datallowconn FROM pg_database WHERE datname = '$POSTGRES_DB'")" t 'banco no ar reaberto'
sql postgres "ALTER DATABASE \"$descartado\" ALLOW_CONNECTIONS true;"

cenario 'cancelamento na confirmação não altera nada'
reset_old
restore_resp nao "$WORK/historical.tar.gz" || fail 'cancelamento deveria sair com 0'
grep -q 'nenhum dado existente foi alterado' "$WORK/saida.log" || fail 'mensagem de cancelamento ausente'
assert_old

cenario 'uploads ausentes são recusados sem alterar nada'
reset_old
mv "$WORK/uploads_20261009_120000.tar" "$WORK/uploads.guardado"
deve_recusar 'backup sem uploads' "$WORK/jusprime_20261009_120000.tar.gz"
mv "$WORK/uploads.guardado" "$WORK/uploads_20261009_120000.tar"

cenario 'artefatos corrompidos não alteram nada'
reset_old
deve_recusar 'gzip do banco corrompido' "$WORK/corrupt-db.tar.gz" "$WORK/uploads_20261009_120000.tar"
deve_recusar 'tar.gz truncado' "$WORK/truncado.tar.gz"
deve_recusar 'uploads.tar corrompido' "$WORK/jusprime_20261009_120000.tar.gz" "$WORK/uploads-corrupt.tar"
deve_recusar '.dump truncado' "$WORK/truncado.dump"

cenario 'erro SQL no dump não altera nada'
reset_old
deve_recusar 'erro SQL' "$WORK/sql-error.tar.gz" "$WORK/uploads_20261009_120000.tar"

cenario 'dump com 0 tabelas é recusado'
reset_old
deve_recusar 'backup sem tabelas' --database-only "$WORK/sem-tabelas.tar.gz"
grep -q '0 tabelas' "$WORK/saida.log" || fail 'motivo (0 tabelas) não informado'

cenario 'TAR com ../, caminho absoluto, link ou conteúdo fora de uploads/ é recusado'
reset_old
deve_recusar 'backup com ../' "$WORK/mal/travessia.tar.gz"
grep -q 'travessia' "$WORK/saida.log" || fail 'motivo da recusa (travessia) não informado'
deve_recusar 'backup com caminho absoluto' "$WORK/mal/absoluto.tar.gz"
deve_recusar 'backup com link simbólico' "$WORK/mal/symlink.tar.gz"
grep -q 'link' "$WORK/saida.log" || fail 'motivo da recusa (link) não informado'
deve_recusar 'backup histórico com pasta fora de uploads/' "$WORK/mal/intruso.tar.gz"
deve_recusar 'uploads com ../' "$WORK/jusprime_20261009_120000.tar.gz" "$WORK/mal/uploads-travessia.tar"
deve_recusar 'uploads com link simbólico' "$WORK/jusprime_20261009_120000.tar.gz" "$WORK/mal/uploads-symlink.tar"
deve_recusar 'uploads com link físico' "$WORK/jusprime_20261009_120000.tar.gz" "$WORK/mal/uploads-hardlink.tar"
[[ ! -e "$WORK/fora.txt" && ! -e /tmp/absoluto.txt ]] || fail 'arquivo escapou para fora do destino'

cenario 'falha de escrita no disco do host (ulimit) não altera nada'
reset_old
if (ulimit -f 1; restore_resp CONFIRMO --database-only "$WORK/banco-grande.tar.gz"); then fail 'falha limitada por espaço foi aceita'; fi
assert_old

cenario 'espaço insuficiente no disco do docker é recusado no preflight'
reset_old
if RESTORE_LIVRE_DOCKER_KB=1 restore_resp CONFIRMO "$WORK/historical.tar.gz"; then fail 'falta de espaço foi aceita'; fi
grep -q 'Espaço insuficiente no disco do docker' "$WORK/saida.log" || fail 'mensagem de espaço ausente'
assert_old

cenario 'worker rodando é recusado (em qualquer modo); php só quando há uploads'
reset_old
docker run -d --name "$WORKER_CONTAINER" alpine sleep 600 >/dev/null
deve_recusar 'worker rodando' --database-only "$WORK/jusprime_20261009_120000.tar.gz"
grep -qF "docker stop $WORKER_CONTAINER" "$WORK/saida.log" || fail 'mensagem não traz o docker stop do worker'
docker rm -f "$WORKER_CONTAINER" >/dev/null
docker run -d --name "$APP_CONTAINER" alpine sleep 600 >/dev/null
deve_recusar 'php rodando com uploads' "$WORK/historical.tar.gz"
grep -qF "docker stop $APP_CONTAINER" "$WORK/saida.log" || fail 'mensagem não traz o docker stop do php'
restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz" || fail 'php rodando bloqueou o modo somente banco'
assert_new_db
docker rm -f "$APP_CONTAINER" >/dev/null

cenario 'trava do deploy ocupada é recusada'
reset_old
( exec 9>"$TRAVA"; flock 9; exec sleep 600 ) &
TRAVA_PID=$!
sleep 1
deve_recusar 'restore com deploy em andamento' --database-only "$WORK/jusprime_20261009_120000.tar.gz"
grep -q 'deploy está em andamento' "$WORK/saida.log" || fail 'mensagem da trava ausente'
kill "$TRAVA_PID"; wait "$TRAVA_PID" 2>/dev/null || true; TRAVA_PID=""

cenario 'POSTGRES_USER do .env divergente do container é recusado'
reset_old
if ENV_TESTE="$WORK/user-divergente.env" restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz"; then
    fail 'POSTGRES_USER divergente foi aceito'
fi
grep -q 'POSTGRES_USER diverge' "$WORK/saida.log" || fail 'mensagem de divergência ausente'
assert_old

cenario 'falha depois da troca dos uploads: rollback devolve uploads e banco antigos'
reset_old
if RESTORE_INJETAR_FALHA=apos_uploads restore_resp CONFIRMO "$WORK/historical.tar.gz"; then
    fail 'falha injetada não foi propagada'
fi
grep -q 'conferidos por checksum' "$WORK/saida.log" || fail 'rollback dos uploads não foi conferido'
assert_old

cenario 'falha no meio da troca do banco (transação): tudo volta ao anterior'
reset_old
if RESTORE_INJETAR_FALHA=durante_banco restore_resp CONFIRMO "$WORK/jusprime_20261009_120000.tar.gz"; then
    fail 'falha injetada não foi propagada'
fi
assert_old

cenario 'falha depois do COMMIT do banco: uploads NÃO são devolvidos (banco e uploads novos ficam juntos)'
reset_old
if RESTORE_INJETAR_FALHA=apos_commit restore_resp CONFIRMO "$WORK/historical.tar.gz"; then
    fail 'falha injetada não foi propagada'
fi
grep -q 'A troca do banco FOI gravada' "$WORK/saida.log" || fail 'cleanup não reconheceu a troca gravada'
assert_eq "$(sql "$POSTGRES_DB" 'SELECT valor FROM estado')" novo 'banco novo no ar'
assert_eq "$(uploads_atuais)" "$(printf './pastas/grande.bin\n./pastas/historico.txt')" 'uploads novos mantidos'
rollback=$(volumes_do_restore)
[[ "$rollback" == *_rollback ]] || fail "volume de volta deveria ter sido preservado (achei: '$rollback')"
grep -qF "$rollback" "$WORK/saida.log" || fail 'mensagem não cita o volume de volta'
docker volume rm "$rollback" >/dev/null
sem_sobras

cenario 'gancho de falha é ignorado fora do modo de teste'
reset_old
MODO_TESTE=0 RESTORE_INJETAR_FALHA=durante_banco restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz" \
    || fail 'gancho de falha valeu fora do modo de teste'
grep -q 'ignorados' "$WORK/saida.log" || fail 'aviso de gancho ignorado ausente'
assert_new_db

cenario 'restauração repetida: cada _before_restore_ guarda o banco que estava no ar'
reset_old
restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz" || fail 'primeiro restore falhou'
primeiro=$(banco_preservado)
restore_resp CONFIRMO --database-only "$WORK/jusprime_20261009_120000.tar.gz" || fail 'segundo restore falhou'
segundo=$(banco_preservado)
assert_eq "$(bancos_extras | wc -l)" 2 'dois bancos _before_restore_ preservados'
assert_eq "$(sql "$primeiro" 'SELECT valor FROM estado')" antigo 'primeiro _before_restore_ tem o banco original'
assert_eq "$(sql "$segundo" 'SELECT valor FROM estado')" novo 'segundo _before_restore_ tem o banco do primeiro restore'
sem_sobras

echo "OK: $CENARIOS cenários passaram em recursos Docker descartáveis."
