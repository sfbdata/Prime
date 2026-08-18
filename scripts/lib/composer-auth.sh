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
# Lê .["github-oauth"]["github.com"] com um parser de verdade. `grep` não serve:
# ele acha a palavra dentro de um arquivo que pode nem ser JSON válido.
#
# Devolve uma de: o token / "" (sem token) / "OUTRO_METODO" / "ERRO_*".
# "OUTRO_METODO" existe porque o composer também autentica por `http-basic` e
# `github-token`: sem distinguir, o script diria "vai baixar ANÔNIMO" para quem tem
# credencial válida em outro formato — aviso falso.
_ler_token() {
    local arquivo="$1" saida
    if command -v python3 >/dev/null 2>&1; then
        saida="$(python3 -c '
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as f:
        dados = json.load(f)
except UnicodeDecodeError:
    print("ERRO_JSON:o arquivo nao e texto UTF-8 valido", end=""); sys.exit(0)
except json.JSONDecodeError as e:
    print("ERRO_JSON:%s" % e, end=""); sys.exit(0)
except OSError as e:
    print("ERRO_LEITURA:%s" % e, end=""); sys.exit(0)
except Exception as e:                      # noqa: BLE001 - nunca derrubar o deploy pelo parser
    print("ERRO_LEITURA:%s" % e, end=""); sys.exit(0)

if not isinstance(dados, dict):
    print("ERRO_JSON:o conteudo nao e um objeto JSON", end=""); sys.exit(0)

oauth = dados.get("github-oauth")
token = oauth.get("github.com") if isinstance(oauth, dict) else None
# null, numero, lista: nao e token. Trata como AUSENTE, nao como invalido.
if isinstance(token, str) and token.strip():
    print(token.strip(), end=""); sys.exit(0)

for metodo in ("http-basic", "github-token", "bearer"):
    valor = dados.get(metodo)
    if isinstance(valor, dict) and any("github" in str(k) for k in valor):
        print("OUTRO_METODO", end=""); sys.exit(0)

print("", end="")
' "$arquivo" 2>/dev/null)" && { printf '%s' "$saida"; return 0; }
        printf 'ERRO_LEITURA:o parser JSON falhou'
        return 0
    fi

    # Sem python3 na máquina. A validação NÃO pode virar o novo motivo de deploy
    # travado — ela existe para o contrário. Degrada para uma extração conservadora
    # e deixa o chamador seguir com aviso.
    printf 'SEM_PARSER'
}

# Pergunta ao GitHub se o token vale. Só um 401/403 AUTORITATIVO reprova.
# Se o GitHub estiver fora do ar, isto NÃO reprova: é exatamente a hora em que
# mais queremos deployar a partir do cache local.
# Pergunta ao GitHub se o token vale. Só um 401/403 AUTORITATIVO reprova.
# Se o GitHub estiver fora do ar, isto NÃO reprova: é exatamente a hora em que
# mais queremos deployar a partir do cache local.
#
# O token entra pelo STDIN do curl (`-K -`), não pelo argv: em `ps aux` a linha de
# comando é pública para qualquer usuário da máquina, e esta é a única vez que o
# token real sai do arquivo.
_github_aceita_token() {
    local token="$1" http
    http=$(printf 'header = "Authorization: Bearer %s"\nheader = "Accept: application/vnd.github+json"\nurl = "https://api.github.com/rate_limit"\nsilent\nshow-error\noutput = "/dev/null"\nmax-time = 15\nwrite-out = "%%{http_code}"\n' "$token" \
        | curl -K - 2>/dev/null) || http="000"
    echo "${http:-000}"
}

validar_composer_auth() {
    local arquivo="${1:-.composer-auth.json}"

    # Ausente: o compose exige que o arquivo do secret exista. Criamos `{}` e seguimos
    # anônimo — funciona, só fica sujeito ao limite por IP do GitHub.
    if [[ ! -f "$arquivo" ]]; then
        if ! echo '{}' > "$arquivo" 2>/dev/null; then
            echo "ERRO: $arquivo não existe e não consegui criá-lo (diretório sem permissão de"
            echo "      escrita?). O compose exige que o arquivo do secret exista."
            echo "      NADA foi alterado — o site continua no ar."
            return 1
        fi
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
        OUTRO_METODO)
            echo "OK: $arquivo autentica no GitHub por outro método (http-basic/github-token)."
            return 0
            ;;
        SEM_PARSER)
            echo "AVISO: python3 não está disponível — não deu para validar $arquivo."
            echo "       Seguindo sem validar. Se o token estiver errado, o build falha (mas o"
            echo "       site só sai do ar depois que o build passar)."
            return 0
            ;;
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
