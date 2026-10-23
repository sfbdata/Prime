#!/usr/bin/env bash
# Prova o scripts/vps/deploy-automatico.sh sem tocar em produção nem no docker de verdade.
#
# Cada caso monta um "origin" e um checkout de produção descartáveis (git de verdade), com
# `docker`, `curl` e o scripts/deploy-prod-tls.sh FALSOS: eles só registram o que foi chamado e
# respondem conforme o caso (build quebra, conferência falha, site fora...). Assim dá para afirmar,
# por exemplo, "migration destrutiva para antes de qualquer backup" e "falha depois da troca volta
# para o commit e a imagem anteriores".
#
# Uso: bash scripts/testar-deploy-automatico.sh
set -uo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SCRIPT="$RAIZ/scripts/vps/deploy-automatico.sh"
ok=0; falhou=0
verde()   { printf '  \033[32m✓\033[0m %s\n' "$1"; ok=$((ok+1)); }
vermelho(){ printf '  \033[31m✗ %s\033[0m\n' "$1"; falhou=$((falhou+1)); }

montar() {   # $1 = conteúdo da migration nova ("" = sem migration)
    T="$(mktemp -d)"
    git init -q --bare "$T/origin.git"
    git clone -q "$T/origin.git" "$T/prod" 2>/dev/null
    cd "$T/prod" || exit 1
    git config user.email t@t; git config user.name t
    mkdir -p app/src/Shared app/migrations scripts nginx/maintenance
    touch app/migrations/.gitkeep
    printf "<?php\nfinal class VersaoDoSistema { public const ATUAL = '2.0.0'; }\n" > app/src/Shared/VersaoDoSistema.php
    # deploy-prod-tls.sh falso: o comportamento vem de FAKE_DEPLOY
    cat > scripts/deploy-prod-tls.sh <<'EOS'
#!/usr/bin/env bash
echo "deploy-prod-tls PULAR_GIT_PULL=${PULAR_GIT_PULL:-} SEM_AGENDADOR=${SEM_AGENDADOR:-}" >> "$FAKE_LOG"
case "$FAKE_DEPLOY" in
  ok)            echo novo > "$FAKE_ESTADO/imagem" ;;
  falha_build)   exit 1 ;;
  falha_depois)  touch nginx/maintenance/maintenance.on; echo novo > "$FAKE_ESTADO/imagem"; exit 1 ;;
esac
EOS
    git add -A; git commit -qm v2.0.0; git tag v2.0.0; git push -q origin master --tags 2>/dev/null
    ANTES="$(git rev-parse HEAD)"
    # a versão nova, empurrada por "outra máquina"
    git clone -q "$T/origin.git" "$T/dev" 2>/dev/null
    ( cd "$T/dev" && git config user.email t@t && git config user.name t
      sed -i "s/2.0.0/2.1.0/" app/src/Shared/VersaoDoSistema.php
      [[ -n "$1" ]] && printf '%s\n' "$1" > app/migrations/Version20261020000000.php
      git add -A && git commit -qm v2.1.0 && git tag v2.1.0 && git push -q origin master --tags 2>/dev/null )
    SHA="$(git -C "$T/dev" rev-parse HEAD)"
    mkdir -p "$T/bin" "$T/estado" "$T/backups" "$T/logs"
    echo velho > "$T/estado/imagem"; : > "$T/log"
    cat > "$T/bin/docker" <<'EOS'
#!/usr/bin/env bash
echo "docker $*" >> "$FAKE_LOG"
case "$1" in
  inspect)
    # Por padrão o agendador já existe; FAKE_SEM_AGENDADOR=1 simula o primeiro deploy com ele.
    [[ "${FAKE_SEM_AGENDADOR:-0}" == 1 && "${*: -1}" == jusprime_scheduler_prod ]] && exit 1
    case "$3" in
      *State.Running*) [[ "${FAKE_AGENDADOR_PARADO:-0}" == 1 ]] && echo false || echo true ;;
      *Config.Image*) echo "jusprime-php" ;;
      *State.Health*) echo healthy ;;
      *'.Image}}'*)   echo "sha256:$(cat "$FAKE_ESTADO/imagem")" ;;
    esac ;;
  exec)
    if [[ "$*" == *pg_dump* ]]; then printf 'PGDMP-falso'; fi
    if [[ "$*" == *pg_restore* ]]; then cat > /dev/null; echo "1; 1259 1 TABLE public pasta symfony"; fi
    if [[ "$*" == *"grep -q"* ]]; then [[ "${FAKE_VERSAO_OK:-1}" == 1 ]]; exit $?; fi ;;
  save) [[ "${FAKE_SAVE_FALHA:-0}" == 1 ]] && exit 1; printf 'imagem-falsa' ;;
  ps) printf '%s\n' "2026-10-01 10:00:00|jusprime_rollback_jusprime_php_prod_pre_v1.9.0" \
        "2026-10-01 10:00:00|jusprime_rollback_jusprime_worker_prod_pre_v1.9.0" \
        "2026-10-10 10:00:00|jusprime_rollback_jusprime_php_prod_pre_v2.0.0" \
        "2026-10-10 10:00:00|jusprime_rollback_jusprime_worker_prod_pre_v2.0.0" \
        "2026-10-20 10:00:00|jusprime_rollback_jusprime_php_prod_pre_v2.1.0" \
        "2026-10-20 10:00:00|jusprime_rollback_jusprime_worker_prod_pre_v2.1.0" \
        "2026-09-01 10:00:00|jusprime_rollback_php_preloop1" \
        "2026-09-01 10:00:00|jusprime_rollback_worker_preargv" ;;
  compose) [[ "$*" == *"up -d --no-build"* ]] && echo velho > "$FAKE_ESTADO/imagem" ;;
esac
exit 0
EOS
    printf '#!/usr/bin/env bash\necho "curl $*" >> "$FAKE_LOG"\nprintf "%%s" "${FAKE_HTTP:-200}"\n' > "$T/bin/curl"
    # crontab -l falso: FAKE_CRONTAB vazio = sem crontab (o real sai com erro "no crontab for root").
    cat > "$T/bin/crontab" <<'EOS'
#!/usr/bin/env bash
[[ -n "${FAKE_CRONTAB:-}" ]] || { echo "no crontab for root" >&2; exit 1; }
printf '%s\n' "$FAKE_CRONTAB"
EOS
    chmod +x "$T/bin/docker" "$T/bin/curl" "$T/bin/crontab"
}

rodar() {    # $1 = modo; demais: variáveis FAKE_*
    local modo="$1"; shift
    env PATH="$T/bin:$PATH" FAKE_LOG="$T/log" FAKE_ESTADO="$T/estado" FAKE_DEPLOY=ok \
        JUSPRIME_DIR="$T/prod" JUSPRIME_BACKUP_DEPLOY="$T/backups/db" JUSPRIME_BACKUP_IMAGENS="$T/backups/img" \
        JUSPRIME_LOGS_DEPLOY="$T/logs" JUSPRIME_TRAVA_DEPLOY="$T/trava" JUSPRIME_URL="https://teste" \
        "$@" bash "$SCRIPT" "$modo" v2.1.0 "$SHA" > "$T/saida" 2>&1
    echo $?
}
head_prod() { git -C "$T/prod" rev-parse HEAD; }
confere() {  # nome, condição
    if eval "$2"; then verde "$1"; else vermelho "$1"; sed 's/^/      /' "$T/saida" | tail -8; fi
}

echo "── Simulação ──"
montar ""
rc=$(rodar simular)
confere "simulação sai 0" '[[ $rc == 0 ]]'
confere "simulação não troca o código" '[[ $(head_prod) == $ANTES ]]'
confere "simulação não guarda nem troca imagem" '! grep -qE "docker (tag|save|compose)" "$T/log"'
confere "simulação confere e APAGA o backup" '[[ -z "$(ls "$T/backups/db" 2>/dev/null)" ]] && grep -q pg_restore "$T/log"'

echo "── Publicação ok ──"
montar ""
rc=$(rodar publicar)
confere "publicar sai 0" '[[ $rc == 0 ]]'
confere "o checkout fica na tag" '[[ $(head_prod) == $SHA ]]'
confere "o deploy antigo roda SEM git pull" 'grep -q "deploy-prod-tls PULAR_GIT_PULL=1" "$T/log"'
confere "guarda o backup do banco" '[[ -n "$(ls "$T/backups/db"/jusprime-pre-v2.1.0-*.dump 2>/dev/null)" ]]'
confere "guarda a imagem anterior (tag + container parado + arquivo)" \
    'grep -q "docker tag sha256:velho jusprime-rollback:jusprime_php_prod-pre-v2.1.0" "$T/log" && grep -q "docker create" "$T/log" && [[ -f "$T/backups/img/jusprime-pre-v2.1.0.tar.gz" ]]'
confere "backup ANTES do deploy" '[[ $(grep -n pg_dump "$T/log" | cut -d: -f1) -lt $(grep -n deploy-prod-tls "$T/log" | cut -d: -f1) ]]'

echo "── Migration destrutiva (sintaxe que o Doctrine gera, sem COLUMN) ──"
montar "$(printf '%s\n' '<?php' 'final class V {' \
  '    public function up(Schema $schema): void' '    {' \
  "        \$this->addSql('ALTER TABLE pasta DROP descricao');" '    }' \
  '    public function down(Schema $schema): void' '    {' \
  "        \$this->addSql('ALTER TABLE pasta ADD descricao TEXT');" '    }' '}')"
rc=$(rodar publicar)
confere "para com saída 3 (precisa do dono)" '[[ $rc == 3 ]]'
confere "antes de qualquer backup ou troca" '! grep -qE "pg_dump|docker tag|deploy-prod-tls" "$T/log" && [[ $(head_prod) == $ANTES ]]'

echo "── Migration que só acrescenta (DROP só no down() de várias linhas e em comentário) ──"
montar "$(printf '%s\n' '<?php' 'final class V {' \
  '    public function up(Schema $schema): void' '    {' \
  '        // o Doctrine propõe DROP TABLE x: fica de fora' \
  "        \$this->addSql('CREATE TABLE a (id INT)');" \
  "        \$this->addSql('ALTER TABLE a DROP CONSTRAINT fk_x');" \
  "        \$this->addSql('DROP INDEX idx_y');" \
  "        \$this->addSql('ALTER TABLE a ALTER b DROP NOT NULL');" '    }' \
  '    public function down(Schema $schema): void' '    {' \
  "        \$this->addSql('DROP TABLE a');" '    }' '}')"
rc=$(rodar publicar)
confere "segue normalmente" '[[ $rc == 0 && $(head_prod) == $SHA ]]'

echo "── Detector contra casos e migrations reais do repositório ──"
D="$(mktemp -d)"
caso_mig() {  # nome, esperado(1=destrutiva), corpo do up
    printf '<?php\nfinal class V {\n    public function up(Schema $schema): void\n    {\n        %s\n    }\n    public function down(Schema $schema): void\n    {\n        $this->addSql(%s);\n    }\n}\n' "$3" "'DROP TABLE z'" > "$D/m.php"
    local achou; achou="$(bash "$SCRIPT" --detectar "$D/m.php")"
    if [[ ( "$2" == 1 && -n "$achou" ) || ( "$2" == 0 && -z "$achou" ) ]]; then verde "$1"; else vermelho "$1 → '$achou'"; fi
}
caso_mig "ALTER TABLE x DROP coluna"            1 "\$this->addSql('ALTER TABLE x DROP coluna');"
caso_mig "ALTER TABLE x ADD a, DROP b"          1 "\$this->addSql('ALTER TABLE x ADD a INT, DROP b');"
caso_mig "ALTER x TYPE (mudança de tipo)"       1 "\$this->addSql('ALTER TABLE x ALTER tipo TYPE VARCHAR(30)');"
caso_mig "UPDATE ... SET"                       1 "\$this->addSql('UPDATE \"user\" SET email = lower(email)');"
caso_mig "DELETE FROM"                          1 "\$this->addSql('DELETE FROM x WHERE id = 1');"
caso_mig "DROP TABLE IF EXISTS"                 1 "\$this->addSql('DROP TABLE IF EXISTS x');"
caso_mig "RENAME"                               1 "\$this->addSql('ALTER TABLE x RENAME COLUMN a TO b');"
caso_mig "CREATE TABLE + ADD coluna"            0 "\$this->addSql('CREATE TABLE x (id INT)'); \$this->addSql('ALTER TABLE x ADD y INT');"
caso_mig "DROP CONSTRAINT / INDEX / DEFAULT"    0 "\$this->addSql('ALTER TABLE x DROP CONSTRAINT c'); \$this->addSql('DROP INDEX IF EXISTS i'); \$this->addSql('ALTER TABLE x ALTER y DROP DEFAULT');"
printf '<?php\nfinal class V {\n    public function up(Schema $schema): void {}\n    public function down(Schema $schema): void {}\n    public function postUp(Schema $schema): void\n    {\n        $this->connection->executeStatement(%s);\n    }\n}\n' "'DELETE FROM x'" > "$D/m.php"
achou="$(bash "$SCRIPT" --detectar "$D/m.php")"
[[ -n "$achou" ]] && verde "postUp depois do down() também conta" || vermelho "postUp depois do down() escapou"
for v in 20260413201123 20260805150000 20260516120000; do
    [[ -n "$(bash "$SCRIPT" --detectar "$RAIZ/app/migrations/Version$v.php")" ]] \
        && verde "real: Version$v marcada" || vermelho "real: Version$v NÃO marcada"
done
for v in 20261017090000 20261016200000; do
    [[ -z "$(bash "$SCRIPT" --detectar "$RAIZ/app/migrations/Version$v.php")" ]] \
        && verde "real: Version$v (só acrescenta) passa" || vermelho "real: Version$v marcada sem motivo"
done
rm -rf "$D"

echo "── Rotação das imagens de rollback ──"
montar ""
rc=$(rodar publicar)
confere "remove as do deploy mais antigo (v1.9.0)" 'grep -q "docker rmi jusprime-rollback:jusprime_php_prod-pre-v1.9.0" "$T/log" && grep -q "docker rmi jusprime-rollback:jusprime_worker_prod-pre-v1.9.0" "$T/log"'
confere "guarda as dos 2 mais recentes" '! grep -qE "docker rmi .*-pre-v2\.(0|1)\.0" "$T/log"'
confere "não toca nas travas criadas à mão (preloop1, preargv)" '! grep -qE "docker rm -f jusprime_rollback_(php_preloop1|worker_preargv)" "$T/log"'

echo "── Falha inesperada antes da troca (disco cheio no docker save) ──"
montar ""
rc=$(rodar publicar FAKE_SAVE_FALHA=1)
confere "sai 2 (nada alterado), sem chegar ao deploy" '[[ $rc == 2 ]] && ! grep -q deploy-prod-tls "$T/log" && [[ $(head_prod) == $ANTES ]]'

echo "── Build quebrado (antes da manutenção) ──"
montar ""
rc=$(rodar publicar FAKE_DEPLOY=falha_build)
confere "sai 1" '[[ $rc == 1 ]]'
confere "volta o código e não mexe em container" '[[ $(head_prod) == $ANTES ]] && ! grep -q "up -d --no-build" "$T/log"'
confere "devolve o nome das imagens (build parcial)" 'grep -q "docker tag sha256:velho jusprime-php" "$T/log"'

echo "── Falha depois da troca de container ──"
montar ""
rc=$(rodar publicar FAKE_DEPLOY=falha_depois)
confere "sai 1 (voltou)" '[[ $rc == 1 ]]'
confere "volta o código" '[[ $(head_prod) == $ANTES ]]'
confere "volta a imagem e recria php e worker" 'grep -q "docker tag jusprime-rollback:jusprime_php_prod-pre-v2.1.0 jusprime-php" "$T/log" && grep -q "compose .*up -d --no-build php worker" "$T/log"'
confere "desliga a manutenção" '[[ ! -f "$T/prod/nginx/maintenance/maintenance.on" ]]'

echo "── Agendador: guardado e recriado na volta quando já existia ──"
montar ""
rc=$(rodar publicar FAKE_DEPLOY=falha_depois)
confere "guarda a imagem do agendador" 'grep -q "docker tag sha256:velho jusprime-rollback:jusprime_scheduler_prod-pre-v2.1.0" "$T/log"'
confere "recria php, worker e agendador" 'grep -q "compose .*up -d --no-build php worker scheduler" "$T/log"'

echo "── Agendador: primeiro deploy com ele (não existia antes) ──"
montar ""
rc=$(rodar publicar FAKE_SEM_AGENDADOR=1)
confere "publicar sai 0 sem guardar o agendador" '[[ $rc == 0 ]] && ! grep -q "jusprime-rollback:jusprime_scheduler_prod" "$T/log"'
montar ""
rc=$(rodar publicar FAKE_DEPLOY=falha_depois FAKE_SEM_AGENDADOR=1)
confere "na volta, desliga o agendador que subiu com a versão nova" '[[ $rc == 1 ]] && grep -q "docker rm -f jusprime_scheduler_prod" "$T/log" && grep -qE "compose .*up -d --no-build php worker$" "$T/log"'

echo "── Agendador: parado antes do deploy não volta ──"
montar ""
rc=$(rodar publicar FAKE_DEPLOY=falha_depois FAKE_AGENDADOR_PARADO=1)
confere "não guarda o agendador parado e o desliga na volta" '[[ $rc == 1 ]] && ! grep -q "jusprime-rollback:jusprime_scheduler_prod" "$T/log" && grep -q "docker rm -f jusprime_scheduler_prod" "$T/log" && grep -qE "compose .*up -d --no-build php worker$" "$T/log"'

echo "── Crontab do host com as tarefas do sistema (dono esqueceu a virada) ──"
CRON_ATIVO_TXT="$(printf '%s\n' '0 2 * * * certbot renew -q' \
  '0 6 * * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:djen:sincronizar --dias=2 >> /var/log/jusprime-djen.log 2>&1' \
  '0 * * * * /opt/jusprime/scripts/sync-reconciliar.sh' \
  '30 2 * * * /opt/jusprime/scripts/backup-db.sh')"
montar ""
rc=$(rodar publicar FAKE_CRONTAB="$CRON_ATIVO_TXT")
confere "o deploy segue e sai 0" '[[ $rc == 0 && $(head_prod) == $SHA ]]'
confere "pede o deploy SEM o agendador" 'grep -q "deploy-prod-tls PULAR_GIT_PULL=1 SEM_AGENDADOR=1" "$T/log"'
confere "aviso visível com as linhas e o passo para ligar" 'grep -q "::warning::O crontab do host ainda roda" "$T/saida" && grep -q "app:djen:sincronizar --dias=2" "$T/saida" && grep -q "sync-reconciliar.sh" "$T/saida" && grep -q "up -d scheduler" "$T/saida"'
confere "certbot e backup não aparecem como tarefa do sistema" '! grep -qE "^    .*(certbot|backup-db)" "$T/saida"'
montar ""
rc=$(rodar publicar FAKE_DEPLOY=falha_depois FAKE_CRONTAB="$CRON_ATIVO_TXT")
confere "na volta, não religa o agendador (rodaria junto com o cron)" '[[ $rc == 1 ]] && grep -q "docker rm -f jusprime_scheduler_prod" "$T/log" && grep -qE "compose .*up -d --no-build php worker$" "$T/log"'
montar ""
rc=$(rodar simular FAKE_CRONTAB="$CRON_ATIVO_TXT")
confere "a simulação já avisa" '[[ $rc == 0 ]] && grep -q "::warning::O crontab do host ainda roda" "$T/saida"'

echo "── Crontab com as linhas do sistema comentadas: o agendador sobe ──"
montar ""
rc=$(rodar publicar FAKE_CRONTAB="$(printf '%s\n' '0 2 * * * certbot renew -q' \
  '# 0 6 * * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:djen:sincronizar --dias=2' \
  '  #0 * * * * /opt/jusprime/scripts/sync-reconciliar.sh' '30 2 * * * /opt/jusprime/scripts/backup-db.sh')")
confere "sobe com o agendador e sem aviso" '[[ $rc == 0 ]] && grep -q "deploy-prod-tls PULAR_GIT_PULL=1 SEM_AGENDADOR=0" "$T/log" && ! grep -q "::warning::" "$T/saida"'

echo "── Crontab só com certbot e backup ──"
montar ""
rc=$(rodar publicar FAKE_CRONTAB="$(printf '%s\n' '0 2 * * * certbot renew -q' '30 2 * * * /opt/jusprime/scripts/backup-db.sh')")
confere "sobe com o agendador" '[[ $rc == 0 ]] && grep -q "SEM_AGENDADOR=0" "$T/log" && ! grep -q "::warning::" "$T/saida"'

echo "── Sem crontab nenhum (crontab -l: no crontab for root) ──"
montar ""
rc=$(rodar publicar)
confere "sobe com o agendador" '[[ $rc == 0 ]] && grep -q "SEM_AGENDADOR=0" "$T/log" && grep -q "Nenhuma tarefa do sistema ativa no crontab" "$T/saida"'

# O worker consome o scheduler_cadeias (as varreduras do push, do Google Agenda e do Comercial): em nenhuma volta ele
# pode ficar de fora, com ou sem agendador, com ou sem crontab ativo.
echo "── Worker volta em toda volta automática (consome as cadeias) ──"
worker_volta() {   # o worker é recriado e nunca removido nem parado
    grep -qE "compose .*up -d --no-build php worker( |$)" "$T/log" \
        && ! grep -qE "docker (rm -f|stop|kill) jusprime_worker_prod$" "$T/log" \
        && ! grep -qE "scale worker" "$T/log"
}
for caso in "agendador já rodava::" "primeiro deploy com o agendador:FAKE_SEM_AGENDADOR=1:" \
            "agendador parado antes:FAKE_AGENDADOR_PARADO=1:" "crontab com as tarefas::cron"; do
    nome="${caso%%:*}"; resto="${caso#*:}"; extra="${resto%%:*}"; cron="${resto#*:}"
    montar ""
    args=(FAKE_DEPLOY=falha_depois)
    [[ -n "$extra" ]] && args+=("$extra")
    [[ "$cron" == cron ]] && args+=("FAKE_CRONTAB=$CRON_ATIVO_TXT")
    rc=$(rodar publicar "${args[@]}")
    confere "$nome: a volta recria o worker" '[[ $rc == 1 ]] && worker_volta'
done
montar ""
rc=$(rodar publicar FAKE_CRONTAB="$CRON_ATIVO_TXT")
confere "publicação com SEM_AGENDADOR=1 não mexe no worker" '[[ $rc == 0 ]] && ! grep -qE "docker (rm -f|stop|kill) jusprime_worker_prod$|scale worker" "$T/log"'

echo "── Conferência falha (versão nova não está no container) ──"
montar ""
rc=$(rodar publicar FAKE_VERSAO_OK=0)
confere "volta e sai 1" '[[ $rc == 1 && $(head_prod) == $ANTES ]] && grep -q "up -d --no-build" "$T/log"'

echo "── Site fora depois da troca e também depois da volta ──"
montar ""
rc=$(rodar publicar FAKE_HTTP=502)
confere "sai 4 (urgente: a volta não confirmou)" '[[ $rc == 4 ]]'

echo "── Pré-condições ──"
montar ""
echo "mexida" >> "$T/prod/app/src/Shared/VersaoDoSistema.php"
rc=$(rodar publicar)
confere "checkout alterado à mão: sai 2 sem mexer" '[[ $rc == 2 ]] && ! grep -q pg_dump "$T/log"'
montar ""
rc=$(env PATH="$T/bin:$PATH" FAKE_LOG="$T/log" FAKE_ESTADO="$T/estado" JUSPRIME_DIR="$T/prod" \
     JUSPRIME_LOGS_DEPLOY="$T/logs" JUSPRIME_TRAVA_DEPLOY="$T/trava" bash "$SCRIPT" publicar v2.1.0 "$(printf 'a%.0s' {1..40})" > "$T/saida" 2>&1; echo $?)
confere "tag que não aponta para o sha: sai 2" '[[ $rc == 2 ]]'
rc=$(env PATH="$T/bin:$PATH" JUSPRIME_LOGS_DEPLOY="$T/logs" JUSPRIME_TRAVA_DEPLOY="$T/trava" bash "$SCRIPT" publicar 'v2.1.0;rm' "$SHA" > "$T/saida" 2>&1; echo $?)
confere "tag fora do formato: sai 2" '[[ $rc == 2 ]]'

echo "── Versão anterior ao deploy automático ──"
montar ""
( cd "$T/dev" && printf '#!/usr/bin/env bash\ngit pull\n' > scripts/deploy-prod-tls.sh && sed -i "s/2.1.0/2.2.0/" app/src/Shared/VersaoDoSistema.php \
  && git commit -qam v2.2.0 && git tag v2.2.0 && git push -q origin master --tags 2>/dev/null )
SHA_ANTIGA="$(git -C "$T/dev" rev-parse HEAD)"
rc=$(env PATH="$T/bin:$PATH" FAKE_LOG="$T/log" FAKE_ESTADO="$T/estado" JUSPRIME_DIR="$T/prod" \
     JUSPRIME_BACKUP_DEPLOY="$T/backups/db" JUSPRIME_LOGS_DEPLOY="$T/logs" JUSPRIME_TRAVA_DEPLOY="$T/trava" \
     bash "$SCRIPT" publicar v2.2.0 "$SHA_ANTIGA" > "$T/saida" 2>&1; echo $?)
confere "deploy real recusa (sai 2 sem mexer)" '[[ $rc == 2 ]] && ! grep -q pg_dump "$T/log"'

echo
echo "── Resultado: $ok passaram, $falhou falharam ──"
[[ "$falhou" -eq 0 ]]
