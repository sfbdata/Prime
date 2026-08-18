#!/usr/bin/env bash
# Prova as duas guardas do deploy, sem tocar em produção nem no docker de verdade:
#
#   1. a validação da credencial de build (scripts/lib/composer-auth.sh);
#   2. a ORDEM entre construir a imagem e ligar o modo manutenção.
#
# O deploy roda inteiro numa cópia descartável, com um `docker` FALSO no PATH que só
# registra o que foi chamado. É assim que dá para afirmar "o site não saiu do ar":
# o docker falso anota, no instante do build, se a flag de manutenção já existia.
#
# Uso: bash scripts/testar-deploy-guardas.sh
set -uo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
ok=0; falhou=0

verde()   { printf '  \033[32m✓\033[0m %s\n' "$1"; ok=$((ok+1)); }
vermelho(){ printf '  \033[31m✗ %s\033[0m\n' "$1"; falhou=$((falhou+1)); }

# ─── Parte 1: a validação da credencial, caso a caso ──────────────────────────
echo "── Parte 1: validação de .composer-auth.json ──"
source "$RAIZ/scripts/lib/composer-auth.sh"

caso() {
    local nome="$1" conteudo="$2" esperado="$3"   # esperado: 0=aceita 1=recusa
    local dir; dir="$(mktemp -d)"
    if [[ "$conteudo" != "__AUSENTE__" ]]; then
        printf '%s' "$conteudo" > "$dir/.composer-auth.json"
    fi
    local saida rc
    saida="$(cd "$dir" && COMPOSER_AUTH_SEM_REDE=1 validar_composer_auth ".composer-auth.json" 2>&1)"
    rc=$?
    if [[ "$rc" -eq "$esperado" ]]; then
        verde "$nome (rc=$rc)"
    else
        vermelho "$nome — esperava rc=$esperado, veio rc=$rc"
        echo "$saida" | sed 's/^/      /'
    fi
    rm -rf "$dir"
}

# O caso de 18/08: o texto de exemplo LITERAL do modelo versionado.
caso "recusa o texto de exemplo do modelo (.example)" \
     "$(cat "$RAIZ/.composer-auth.json.example")" 1
# O texto que o dono relatou ter colado.
caso "recusa o placeholder 'ghp_SEU_TOKEN_AQUI'" \
     '{"github-oauth":{"github.com":"ghp_SEU_TOKEN_AQUI"}}' 1
caso "recusa JSON inválido (o grep antigo não via)" \
     '{"github-oauth": {"github.com": "ghp_' 1
caso "recusa JSON que não é objeto (array)" \
     '["github-oauth"]' 1
caso "recusa token truncado (forma errada)" \
     '{"github-oauth":{"github.com":"ghp_abc"}}' 1
caso "aceita ausência do arquivo (anônimo funciona)" \
     "__AUSENTE__" 0
caso "aceita {} vazio (anônimo consciente)" \
     '{}' 0
caso "aceita github-oauth sem a chave github.com" \
     '{"github-oauth":{"gitlab.com":"x"}}' 0
caso "aceita token com forma de PAT clássico" \
     '{"github-oauth":{"github.com":"ghp_0123456789abcdefghijABCDEFGHIJ012345"}}' 0
caso "aceita token legado de 40 hexadecimais" \
     '{"github-oauth":{"github.com":"0123456789abcdef0123456789abcdef01234567"}}' 0
# `null` é "sem token", não é placeholder: tem de AVISAR, não abortar o deploy.
caso "trata github.com:null como ausência (avisa, não aborta)" \
     '{"github-oauth":{"github.com":null}}' 0
caso "trata github.com numérico como ausência" \
     '{"github-oauth":{"github.com":12345}}' 0
caso "trata github-oauth não-dicionário como ausência" \
     '{"github-oauth":"ghp_x"}' 0
caso "reconhece http-basic no github.com (não diz 'anônimo' à toa)" \
     '{"http-basic":{"github.com":{"username":"u","password":"p"}}}' 0
caso "aceita token com espaços em volta" \
     '{"github-oauth":{"github.com":"  ghp_0123456789abcdefghijABCDEFGHIJ012345  "}}' 0
caso "aceita PAT de escopo fino (github_pat_)" \
     "{\"github-oauth\":{\"github.com\":\"github_pat_$(printf 'a%.0s' {1..60})\"}}" 0

# Forma certa, mas o GitHub recusa: só um 401/403 autoritativo reprova.
echo "── Parte 1b: token bem-formado porém inválido (consulta real ao GitHub) ──"
# OPT-IN: rodar isto na VPS soma tentativas de auth falhas no MESMO IP que o build usa
# anonimamente — pode agravar justamente o HTTP 429 que esta frente combate.
if [[ "${TESTAR_GITHUB_DE_VERDADE:-0}" != "1" ]]; then
    printf '  \033[33m~\033[0m pulado (defina TESTAR_GITHUB_DE_VERDADE=1 para consultar o GitHub)\n'
else
dir="$(mktemp -d)"
printf '{"github-oauth":{"github.com":"ghp_0123456789abcdefghijABCDEFGHIJ012345"}}' > "$dir/.composer-auth.json"
saida="$(cd "$dir" && validar_composer_auth ".composer-auth.json" 2>&1)"; rc=$?
if echo "$saida" | grep -q "não deu para confirmar"; then
    printf '  \033[33m~\033[0m sem rede para o GitHub — caso pulado\n'
elif [[ "$rc" -eq 1 ]] && echo "$saida" | grep -q "RECUSOU"; then
    verde "recusa token bem-formado que o GitHub rejeita (HTTP 401)"
else
    vermelho "token inválido deveria ser recusado — rc=$rc"; echo "$saida" | sed 's/^/      /'
fi
rm -rf "$dir"
fi

# ─── Parte 2: o deploy inteiro, com docker falso ──────────────────────────────
echo
echo "── Parte 2: deploy end-to-end (docker falso) — o site sai do ar quando? ──"

montar_sandbox() {
    local dir="$1"
    mkdir -p "$dir/scripts/lib" "$dir/nginx/maintenance" "$dir/bin"
    cp "$RAIZ/scripts/deploy-prod.sh" "$RAIZ/scripts/deploy-prod-tls.sh" "$dir/scripts/"
    cp "$RAIZ/scripts/lib/composer-auth.sh" "$dir/scripts/lib/"
    # Certificados falsos: é o que permite exercitar o script de TLS, que é o que a
    # produção de fato roda (docs/runbooks/mcp-investigacao-prod.md:107).
    for d in bluejus.com.br grupojusprime.tech; do
        mkdir -p "$dir/letsencrypt/live/$d"
        touch "$dir/letsencrypt/live/$d/fullchain.pem" "$dir/letsencrypt/live/$d/privkey.pem"
    done
    printf 'POSTGRES_USER=u\nPOSTGRES_DB=d\n' > "$dir/.env.prod"
    touch "$dir/docker-compose.prod.yml"
    # docker FALSO: registra as chamadas e, no build, anota se a manutenção já estava ligada.
    cat > "$dir/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
raiz="$(dirname "$(dirname "$0")")"
reg="$raiz/chamadas.log"
todos="$*"
case "$todos" in
  "compose version") exit 0 ;;
  *" build"*)
      if [[ -f "$raiz/nginx/maintenance/maintenance.on" ]]; then
          echo "BUILD:MANUTENCAO_JA_LIGADA" >> "$reg"
      else
          echo "BUILD:SITE_NO_AR" >> "$reg"
      fi
      [[ -f "$raiz/FALHAR_BUILD" ]] && { echo "BUILD:FALHOU" >> "$reg"; exit 1; }
      echo "BUILD:OK" >> "$reg"; exit 0 ;;
  *"up -d"*)       echo "UP" >> "$reg"; exit 0 ;;
  *pg_isready*)    exit 0 ;;
  *Health.Status*) echo healthy; exit 0 ;;
  *migrations:migrate*) echo "MIGRATE" >> "$reg"; exit 0 ;;
  *) exit 0 ;;
esac
DOCKER
    chmod +x "$dir/bin/docker"
    # O docker-compose REAL fica antes no PATH e tem precedência na detecção do script;
    # sem sombreá-lo o sandbox chamaria o compose de verdade. Delega ao docker falso.
    cat > "$dir/bin/docker-compose" <<'DC'
#!/usr/bin/env bash
exec "$(dirname "$0")/docker" compose "$@"
DC
    chmod +x "$dir/bin/docker-compose"
    # `git pull` e `ss` aparecem no script de TLS; neutralizados no sandbox.
    for cmd in git ss; do printf '#!/usr/bin/env bash\nexit 0\n' > "$dir/bin/$cmd"; chmod +x "$dir/bin/$cmd"; done
}

rodar_deploy() {
    ( cd "$1" && PATH="$1/bin:$PATH" LETSENCRYPT_DIR="$1/letsencrypt" \
        COMPOSER_AUTH_SEM_REDE=1 bash "scripts/$2" >saida.log 2>&1; echo $? )
}

for SCRIPT in deploy-prod.sh deploy-prod-tls.sh; do
  echo
  echo "  ▸ $SCRIPT$([[ "$SCRIPT" == deploy-prod-tls.sh ]] && echo '  ← É ESTE QUE A PRODUÇÃO RODA')"

  # CASO RUIM — a credencial de 18/08. Nada pode ser derrubado.
  dir="$(mktemp -d)"; montar_sandbox "$dir"
  cp "$RAIZ/.composer-auth.json.example" "$dir/.composer-auth.json"
  rc="$(rodar_deploy "$dir" "$SCRIPT")"
  [[ "$rc" -ne 0 ]] && verde "caso ruim: deploy abortou (rc=$rc)" || vermelho "caso ruim: deploy NÃO abortou"
  [[ ! -f "$dir/nginx/maintenance/maintenance.on" ]] \
      && verde "caso ruim: modo manutenção NUNCA foi ligado — o site não saiu do ar" \
      || vermelho "caso ruim: o site FOI derrubado"
  [[ ! -f "$dir/chamadas.log" ]] \
      && verde "caso ruim: nenhum container/imagem foi tocado" \
      || vermelho "caso ruim: chegou a chamar docker: $(tr '\n' ' ' < "$dir/chamadas.log")"
  grep -q "não tem forma de token do GitHub" "$dir/saida.log" \
      && verde "caso ruim: a mensagem diz o que está errado e como consertar" \
      || vermelho "caso ruim: mensagem não explica o problema"
  rm -rf "$dir"

  # .env.prod SEM as variáveis do banco — o deploy pararia no meio, já em manutenção.
  dir="$(mktemp -d)"; montar_sandbox "$dir"
  printf 'APP_ENV=prod\n' > "$dir/.env.prod"
  printf '{"github-oauth":{"github.com":"ghp_0123456789abcdefghijABCDEFGHIJ012345"}}' > "$dir/.composer-auth.json"
  rc="$(rodar_deploy "$dir" "$SCRIPT")"
  [[ "$rc" -ne 0 ]] && verde ".env.prod incompleto: abortou antes de agir (rc=$rc)" || vermelho ".env.prod incompleto: não abortou"
  [[ ! -f "$dir/nginx/maintenance/maintenance.on" ]] \
      && verde ".env.prod incompleto: o site NÃO saiu do ar" \
      || vermelho ".env.prod incompleto: derrubou o site e travou no meio"
  rm -rf "$dir"

  # CASO LEGÍTIMO — token bem-formado, deploy completo.
  dir="$(mktemp -d)"; montar_sandbox "$dir"
  printf '{"github-oauth":{"github.com":"ghp_0123456789abcdefghijABCDEFGHIJ012345"}}' > "$dir/.composer-auth.json"
  rc="$(rodar_deploy "$dir" "$SCRIPT")"
  [[ "$rc" -eq 0 ]] && verde "caso legítimo: deploy concluiu (rc=0)" || { vermelho "caso legítimo: falhou rc=$rc"; sed 's/^/      /' "$dir/saida.log"; }
  grep -q "^BUILD:SITE_NO_AR$" "$dir/chamadas.log" 2>/dev/null \
      && verde "caso legítimo: a imagem foi construída COM O SITE AINDA NO AR" \
      || vermelho "caso legítimo: o build rodou com o site já derrubado"
  grep -q "^MIGRATE$" "$dir/chamadas.log" 2>/dev/null && verde "caso legítimo: migrations rodaram" || vermelho "caso legítimo: migrations não rodaram"
  [[ ! -f "$dir/nginx/maintenance/maintenance.on" ]] \
      && verde "caso legítimo: modo manutenção desligado no fim" \
      || vermelho "caso legítimo: ficou em manutenção"
  rm -rf "$dir"

  # CASO BUILD QUEBRADO — pane do GitHub. O site também não pode cair.
  dir="$(mktemp -d)"; montar_sandbox "$dir"
  printf '{"github-oauth":{"github.com":"ghp_0123456789abcdefghijABCDEFGHIJ012345"}}' > "$dir/.composer-auth.json"
  touch "$dir/FALHAR_BUILD"
  rc="$(rodar_deploy "$dir" "$SCRIPT")"
  [[ "$rc" -ne 0 ]] && verde "build quebrado: deploy abortou (rc=$rc)" || vermelho "build quebrado: não abortou"
  [[ ! -f "$dir/nginx/maintenance/maintenance.on" ]] \
      && verde "build quebrado: o site NÃO saiu do ar (era o que custou ~40 min em 17/08)" \
      || vermelho "build quebrado: o site foi derrubado por um build que falhou"
  grep -q "^UP$" "$dir/chamadas.log" 2>/dev/null \
      && vermelho "build quebrado: chegou a recriar container" \
      || verde "build quebrado: nenhum container foi recriado"
  rm -rf "$dir"
done

echo
echo "── Resultado: $ok passaram, $falhou falharam ──"
[[ "$falhou" -eq 0 ]]
