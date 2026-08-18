#!/usr/bin/env bash
# Validação da credencial de build (.composer-auth.json), compartilhada pelos dois
# scripts de deploy.
#
# POR QUE ISTO EXISTE
# -------------------
# Em 18/08/2026 o arquivo foi criado com o texto de EXEMPLO literal do modelo. A
# verificação da época era `grep -q "github-oauth"` — e o exemplo contém essa palavra,
# então ela não reclamou de nada. O deploy seguiu, ligou o modo manutenção, derrubou o
# site, e só então o build quebrou com "Could not authenticate against github.com".
#
# A assimetria que orienta o desenho: credencial AUSENTE é inofensiva (o download vira
# anônimo e funciona, só sujeito a limite por IP); credencial INVÁLIDA é fatal (o GitHub
# recusa na entrada e nenhum pacote baixa). Por isso ausência é AVISO e invalidez é ERRO.
#
# A validação é por FORMA POSITIVA, não por lista de textos proibidos: em vez de procurar
# "COLE_AQUI_O_TOKEN", exige que o valor se pareça com um token do GitHub de verdade.
# Assim ela pega também o placeholder que ninguém previu.
#
# Uso:
#     source scripts/lib/composer-auth.sh
#     validar_composer_auth ".composer-auth.json" || exit 1
#
# Códigos de retorno:
#     0 = pode buildar (com token válido OU anônimo consciente)
#     1 = NÃO pode buildar — aborte ANTES de ligar o modo manutenção
#
# Variável de escape para teste: COMPOSER_AUTH_SEM_REDE=1 pula a consulta ao GitHub.

# Um token do GitHub tem forma conhecida. Qualquer coisa fora disso é placeholder,
# valor truncado ou lixo colado por engano — nunca uma credencial utilizável.
_token_tem_forma_de_github() {
    local t="$1"
    [[ "$t" =~ ^gh[pousr]_[A-Za-z0-9]{36,}$ ]] && return 0   # PAT clássico e tokens de app
    [[ "$t" =~ ^github_pat_[A-Za-z0-9_]{60,}$ ]] && return 0 # PAT de escopo fino
    [[ "$t" =~ ^[a-f0-9]{40}$ ]] && return 0                 # token legado (40 hex)
    return 1
}

# Lê .["github-oauth"]["github.com"] com um parser de verdade. `grep` não serve:
# ele acha a palavra dentro de um arquivo que pode nem ser JSON válido.
_ler_token() {
    local arquivo="$1"
    python3 -c '
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as f:
        dados = json.load(f)
except json.JSONDecodeError as e:
    print("ERRO_JSON:%s" % e, end="")
    sys.exit(0)
except OSError as e:
    print("ERRO_LEITURA:%s" % e, end="")
    sys.exit(0)
if not isinstance(dados, dict):
    print("ERRO_JSON:o conteudo nao e um objeto JSON", end="")
    sys.exit(0)
print((dados.get("github-oauth") or {}).get("github.com", "") if isinstance(dados.get("github-oauth"), dict) else "", end="")
' "$arquivo" 2>/dev/null || echo "ERRO_LEITURA:python3 indisponivel"
}

# Pergunta ao GitHub se o token vale. Só um 401/403 AUTORITATIVO reprova.
# Se o GitHub estiver fora do ar, isto NÃO reprova: é exatamente a hora em que
# mais queremos deployar a partir do cache local.
_github_aceita_token() {
    local token="$1" http
    http=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
        -H "Authorization: Bearer $token" \
        -H "Accept: application/vnd.github+json" \
        https://api.github.com/rate_limit 2>/dev/null) || http="000"
    echo "$http"
}

validar_composer_auth() {
    local arquivo="${1:-.composer-auth.json}"

    # Ausente: o compose exige que o arquivo do secret exista. Criamos `{}` e seguimos
    # anônimo — funciona, só fica sujeito ao limite por IP do GitHub.
    if [[ ! -f "$arquivo" ]]; then
        echo '{}' > "$arquivo"
        echo "AVISO: $arquivo não existia — criado vazio, o build vai baixar ANÔNIMO."
        echo "       Funciona, mas o GitHub limita por IP (HTTP 429). Modelo: ${arquivo}.example"
        return 0
    fi

    if [[ ! -r "$arquivo" ]]; then
        echo "ERRO: $arquivo existe mas não é legível. Corrija a permissão e repita."
        return 1
    fi

    local token
    token="$(_ler_token "$arquivo")"

    case "$token" in
        ERRO_JSON:*)
            echo "ERRO: $arquivo não é JSON válido (${token#ERRO_JSON:})."
            echo "      O composer não vai conseguir lê-lo. Corrija o arquivo e repita."
            echo "      NADA foi alterado — o site continua no ar."
            return 1
            ;;
        ERRO_LEITURA:*)
            echo "ERRO: não consegui ler $arquivo (${token#ERRO_LEITURA:})."
            return 1
            ;;
    esac

    # Chave ausente ou vazia: mesmo caso do arquivo ausente — anônimo funciona.
    if [[ -z "$token" ]]; then
        echo "AVISO: $arquivo sem github-oauth[\"github.com\"] — o build vai baixar ANÔNIMO."
        echo "       Funciona, mas o GitHub limita por IP (HTTP 429). Modelo: ${arquivo}.example"
        return 0
    fi

    # Aqui está o buraco de 18/08: o texto de exemplo do modelo passava batido.
    if ! _token_tem_forma_de_github "$token"; then
        echo "ERRO: o token em $arquivo não tem forma de token do GitHub."
        echo "      Valor encontrado: '$(printf '%.12s' "$token")…' (${#token} caracteres)."
        echo "      Isso é o texto de EXEMPLO do modelo, não uma credencial — foi exatamente"
        echo "      o que derrubou a produção em 18/08/2026. Um token real começa com 'ghp_',"
        echo "      'github_pat_' ou é 40 caracteres hexadecimais."
        echo "      Credencial INVÁLIDA é pior que credencial ausente: sem ela o download é"
        echo "      anônimo e funciona; com ela inválida o GitHub recusa na entrada."
        echo "      Conserto: ponha o token real, ou apague o arquivo para buildar anônimo."
        echo "      NADA foi alterado — o site continua no ar."
        return 1
    fi

    if [[ "${COMPOSER_AUTH_SEM_REDE:-0}" == "1" ]]; then
        echo "OK: token com forma válida (consulta ao GitHub pulada por COMPOSER_AUTH_SEM_REDE=1)."
        return 0
    fi

    local http
    http="$(_github_aceita_token "$token")"
    case "$http" in
        200)
            echo "OK: o GitHub aceitou o token (HTTP 200) — o build vai baixar autenticado."
            return 0
            ;;
        401|403)
            echo "ERRO: o GitHub RECUSOU o token de $arquivo (HTTP $http)."
            echo "      Token revogado, expirado ou digitado errado. Com credencial inválida o"
            echo "      build quebra em 'Could not authenticate against github.com'."
            echo "      Conserto: gere um token novo, ou apague o arquivo para buildar anônimo."
            echo "      NADA foi alterado — o site continua no ar."
            return 1
            ;;
        *)
            # Inclui 000 (sem rota/timeout) e 5xx: pode ser a própria pane do GitHub.
            # Reprovar aqui impediria justamente o deploy que o cache local viabiliza.
            echo "AVISO: não deu para confirmar o token com o GitHub (HTTP $http)."
            echo "       Pode ser pane do GitHub. Seguindo — o build usa o cache local se puder."
            return 0
            ;;
    esac
}
