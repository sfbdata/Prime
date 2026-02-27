#!/usr/bin/env bash
set -euo pipefail

if command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD="docker-compose"
elif docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD="docker compose"
else
  echo "[ERRO] Docker Compose não encontrado (nem docker-compose nem docker compose)."
  exit 1
fi

if [[ ! -f ".env.prod" ]]; then
  echo "[ERRO] Arquivo .env.prod não encontrado."
  exit 1
fi

DOMAIN="${1:-${LETSENCRYPT_DOMAIN:-}}"
if [[ -z "${DOMAIN}" ]]; then
  DOMAIN="$(grep -E '^DEFAULT_URI=' .env.prod | head -n1 | cut -d'=' -f2- | sed -E 's#^https?://##; s#/.*$##; s#"##g')"
fi

if [[ -z "${DOMAIN}" ]]; then
  echo "[ERRO] Não foi possível determinar o domínio. Passe como argumento ou defina LETSENCRYPT_DOMAIN/DEFAULT_URI."
  exit 1
fi

PASS_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

pass() {
  echo "[OK] $1"
  PASS_COUNT=$((PASS_COUNT + 1))
}

warn() {
  echo "[WARN] $1"
  WARN_COUNT=$((WARN_COUNT + 1))
}

fail() {
  echo "[FAIL] $1"
  FAIL_COUNT=$((FAIL_COUNT + 1))
}

check_required_env() {
  local key="$1"
  local value
  value="$(grep -E "^${key}=" .env.prod | head -n1 | cut -d'=' -f2- || true)"

  if [[ -z "${value}" ]]; then
    fail "${key} ausente em .env.prod"
    return
  fi

  case "${value}" in
    *troque_esta_senha*|*defina_um_secret_forte*|*defina_sua_chave*|*app.seudominio.com*|*smtp.exemplo.com*|*usuario:senha*)
      warn "${key} parece valor de exemplo; confirme antes do go-live"
      ;;
    *)
      pass "${key} definido"
      ;;
  esac
}

echo "==> Validando variáveis essenciais"
check_required_env "APP_SECRET"
check_required_env "DATABASE_URL"
check_required_env "MAILER_DSN"
check_required_env "DATAJUD_API_KEY"
check_required_env "DEFAULT_URI"
check_required_env "SYMFONY_TRUSTED_PROXIES"
check_required_env "SYMFONY_TRUSTED_HOSTS"

echo "==> Validando containers da stack TLS"
if $COMPOSE_CMD -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod ps >/tmp/jusprime_ps.txt 2>/dev/null; then
  pass "docker-compose ps executado"
else
  fail "falha ao executar docker-compose ps"
fi

for service in php nginx db; do
  if $COMPOSE_CMD -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod ps --services --filter "status=running" | grep -qx "${service}"; then
    pass "serviço ${service} em execução"
  else
    fail "serviço ${service} não está em execução"
  fi
done

echo "==> Validando saúde do banco"
if $COMPOSE_CMD -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod exec -T db pg_isready -U "${POSTGRES_USER:-symfony}" -d "${POSTGRES_DB:-saas}" >/dev/null 2>&1; then
  pass "PostgreSQL responde ao pg_isready"
else
  fail "PostgreSQL não está pronto"
fi

echo "==> Validando Nginx e certificados"
if $COMPOSE_CMD -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod exec -T nginx nginx -t >/dev/null 2>&1; then
  pass "nginx -t sem erros"
else
  fail "nginx -t reportou erro"
fi

if [[ -f "certs/fullchain.pem" && -f "certs/privkey.pem" ]]; then
  pass "arquivos de certificado presentes"
  if command -v openssl >/dev/null 2>&1; then
    CERT_END="$(openssl x509 -enddate -noout -in certs/fullchain.pem 2>/dev/null | cut -d= -f2- || true)"
    if [[ -n "${CERT_END}" ]]; then
      pass "validade do certificado: ${CERT_END}"
    else
      warn "não foi possível ler validade de certs/fullchain.pem"
    fi
  else
    warn "openssl não encontrado para validar expiração do certificado"
  fi
else
  fail "certificados ausentes em certs/fullchain.pem e/ou certs/privkey.pem"
fi

echo "==> Validando DNS"
if command -v dig >/dev/null 2>&1; then
  DNS_IPS="$(dig +short "${DOMAIN}" A | tr '\n' ' ' | xargs || true)"
  if [[ -n "${DNS_IPS}" ]]; then
    pass "DNS A para ${DOMAIN}: ${DNS_IPS}"
  else
    warn "DNS sem resposta A para ${DOMAIN}"
  fi
else
  warn "dig não encontrado; pulando validação DNS"
fi

echo "==> Validando HTTP -> HTTPS e endpoint principal"
HTTP_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "http://${DOMAIN}" || true)"
HTTPS_CODE="$(curl -k -sS -o /dev/null -w '%{http_code}' "https://${DOMAIN}" || true)"
LOCATION="$(curl -sSI "http://${DOMAIN}" | awk -F': ' '/^Location:/ {print $2}' | tr -d '\r' || true)"

if [[ "${HTTP_CODE}" =~ ^30[1278]$ ]]; then
  pass "HTTP retorna redirecionamento (${HTTP_CODE})"
else
  warn "HTTP não retornou redirecionamento esperado (status: ${HTTP_CODE:-n/a})"
fi

if [[ "${LOCATION}" == https://* ]]; then
  pass "redirect Location aponta para HTTPS"
else
  warn "Location de redirect não aponta para HTTPS (${LOCATION:-vazio})"
fi

if [[ "${HTTPS_CODE}" =~ ^2|3[0-9]{2}$ ]]; then
  pass "HTTPS responde (status ${HTTPS_CODE})"
else
  fail "HTTPS não respondeu corretamente (status: ${HTTPS_CODE:-n/a})"
fi

echo "==> Validando status de migrations"
if MIGRATIONS_STATUS="$($COMPOSE_CMD -f docker-compose.prod.yml -f docker-compose.prod.tls.yml --env-file .env.prod exec -T php php /var/www/app/bin/console doctrine:migrations:status --no-interaction 2>&1)"; then
  pass "doctrine:migrations:status executado"
  if echo "${MIGRATIONS_STATUS}" | grep -Eq 'New Migrations:[[:space:]]+[1-9]'; then
    warn "existem migrations pendentes"
  else
    pass "sem migrations pendentes detectadas"
  fi
else
  fail "falha ao executar doctrine:migrations:status"
fi

echo "==> Firewall"
if command -v ufw >/dev/null 2>&1; then
  UFW_STATUS="$(sudo ufw status 2>/dev/null || true)"
  if echo "${UFW_STATUS}" | grep -qi "Status: active"; then
    pass "UFW ativo"
  else
    warn "UFW inativo ou sem permissão para leitura"
  fi
else
  warn "ufw não encontrado; validar firewall manualmente"
fi

echo
echo "Resumo: OK=${PASS_COUNT} WARN=${WARN_COUNT} FAIL=${FAIL_COUNT}"

if [[ "${FAIL_COUNT}" -gt 0 ]]; then
  exit 1
fi

exit 0