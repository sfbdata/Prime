# Guarda de segredos (pre-commit + CI)

Etapa S1 de segurança (12/10/2026). Objetivo: nenhuma credencial nova entra no repositório, que é
**público** (`sfbdata/Prime`).

## Por que existe

De 15/02 a 12/10/2026 a senha de app do Gmail que envia os e-mails de **produção** ficou em
`app/.env` (commit `3ee0082a`, linhas 14 e 15), num repositório público. O `.gitignore` tinha uma
regra `/app/.env`, mas ela não fazia nada: o arquivo já era rastreado, e ignore não desfaz rastreio.

Evidência registrada na memória do projeto: o valor que a produção mandava ao Gmail quando o Gmail
respondeu `535` (revogações de 27/08 a 23/09) é **o mesmo** que estava publicado. Isso é a causa
provável das revogações, não provada.

O histórico **não** foi reescrito (decisão de 12/10): a senha já estava pública, e reescrever não
apaga cópias de terceiros. O remédio é revogar a senha; ver **Pendências do dono**.

## Onde mora cada valor

| Arquivo | Versionado? | Pode ter segredo real? |
|---|---|---|
| `app/.env` | sim | **não**: só padrões de dev (`MAILER_DSN=null://null`, banco do compose) |
| `app/.env.dev`, `app/.env.test` | sim | não: valores de dev e teste, por convenção do Symfony |
| `app/.env.local` | não (ignorado) | sim: segredos de **dev** |
| `.env.prod` (na VPS) | não (ignorado) | sim: segredos de **produção**. O build apaga o `app/.env` |
| `.env.prod.example` | sim | não: modelo com placeholders |

🔴 **Efeito no dev depois da integração:** e-mail passa a ser **descartado em silêncio**
(`null://`), sem erro.

Para testar envio em dev, defina `MAILER_DSN` em `app/.env.local`, de preferência apontando para um
capturador local. **Nunca** use a conta de produção.

## As duas camadas

### 1. `scripts/git-hooks/verificar-segredos.py`

Roda no `pre-commit` e no CI.

**O que olha:**
- no pre-commit, só as linhas **adicionadas** no stage e os arquivos **novos**. Remover um segredo
  nunca é barrado;
- a detecção de renomeação fica desligada, para que `git mv x chave.pem` seja visto como arquivo novo;
- no CI, em commits de merge, olha só o que a **resolução** introduziu (`--remerge-diff`).

**Regras:**

| Regra | Onde vale |
|---|---|
| Senha em URL/DSN (aceita `@` no usuário e senha codificada com `%`) | qualquer arquivo |
| Chave privada | qualquer arquivo |
| Tokens de formato conhecido (AWS, GitHub, Google, Slack, Stripe, Anthropic, OpenAI, npm) | qualquer arquivo |
| `NOME=valor` para chaves com `PASSWORD`, `SENHA`, `SECRET`, `TOKEN`, `API_KEY`… e `PASS`/`PWD` como pedaço do nome (`DB_PASS`, não `BYPASS`); valor entre aspas lido inteiro (senha do Gmail com espaços) | **só arquivo de configuração**: `.env*`, yaml, json, toml, ini, conf, sh, Dockerfile, compose |
| Nome de arquivo proibido (`.env` fora de `app/`, `.env.local`, `.env.prod*`, `*.pem`/`*.key`, `id_rsa*`, `client_secret*.json`, `*.dump`, `*.sql.gz`, `rclone.conf`, `.netrc`, `app/auth.json`…). Também cobre `git add -f` | qualquer caminho |

**Nunca imprime o valor**, só `arquivo:linha [regra] (N caracteres)`.

### 2. gitleaks v8.21.2

Roda só no CI, com a imagem fixada por digest. Soma as cerca de 150 regras da comunidade a três
regras do `.gitleaks.toml`, que espelham a camada 1:
- `url-com-credencial`;
- `senha-em-variavel`;
- `senha-em-variavel-entre-aspas`.

🔴 As regras **padrão** do gitleaks **não** pegam senha em DSN nem senha em `*_PASS`. Isso foi medido
duas vezes: a varredura do histórico não acusava o vazamento, e a revisão adversarial mostrou senhas
`DB_PASS=…` passando.

### O que conta como placeholder (as duas camadas aceitam)

- **Referências:**
  - `$VAR` e `${VAR}`;
  - `%env(...)%`, `%parametro%` e `%s` do `printf`;
  - `@servico`, `{{ twig }}` e `<valor>`.
- **Placeholders escritos:**
  - valores curtos conhecidos: `senha`, `password`, `symfony` (banco do compose), `ci` (Postgres do
    Actions), `!ChangeMe!` (receita do Symfony);
  - máscaras (`****`, `xxxxxxxx`, 8 ou mais caracteres iguais seguidos);
  - valor **de caixa única** com separador e com palavra de placeholder: `troque_esta_senha`,
    `SENHA_DA_LEITURA`.
- **Montado em tempo de execução**, só na regra de variável: `$(...)` e crase.

**Não** conta como placeholder:
- senha de caixa mista com a palavra dentro (`Kx9p…Wchange`);
- senha com sequência (`Empresa1234567`);
- senha que começa com `%`, `!` ou `*`.

A exceção de sequência (`0123456`, `abcdefg`) vale só para **tokens de formato**, como o token
fictício do `testar-deploy-guardas.sh`.

## O CI

O workflow `.github/workflows/segredos.yml` varre só os commits novos do evento. O intervalo é
calculado por `scripts/git-hooks/intervalo-ci.sh`:
- **PR:** `base..head`.
- **Push no master:** `before..after`.
- **Falha fechado (vermelho)** quando não sabe o que é novo: primeiro push, push forçado que
  reescreveu o master, intervalo vazio ou evento desconhecido. A resposta nesses casos é a auditoria
  completa abaixo.

O segredo antigo (`3ee0082a`) não trava PR nenhum. Um segredo novo trava sempre.

**Concorrência:**
- num PR, o run novo cancela o anterior, porque ele varre o PR inteiro de novo;
- num push, cada run tem grupo próprio, porque cancelar perderia os commits daquele push.

## Falso positivo

Acrescente `segredo:permitido` (ou `gitleaks:allow`) **na própria linha**, com o motivo. As duas
camadas aceitam a marca, e o revisor do commit a vê.

Para valor público conhecido, prefira o allowlist por hash em `VALORES_PUBLICOS_SHA256`, como foi
feito com a chave pública do DataJud.

Achados antigos do gitleaks já conferidos ficam no `.gitleaksignore`. A impressão digital é
`commit:arquivo:regra:linha`, então só vale no modo `git`, não no modo `dir`.

## Comandos

```bash
# testes da guarda, do hook e do intervalo do CI (48) — rodam também no CI
python3 -m unittest discover -s scripts/git-hooks -p 'test_*.py' -v

# auditar a árvore atual inteira (só caminho/linha/regra)
git ls-files -z -- . ':(exclude)app/vendor' ':(exclude)*.lock' \
  | xargs -0 python3 -I scripts/git-hooks/verificar-segredos.py --arquivos

# auditar o histórico inteiro com o gitleaks (relatório redigido)
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD:/repo:ro" -w /repo \
  zricethezav/gitleaks:v8.21.2 git . --config .gitleaks.toml \
  --gitleaks-ignore-path .gitleaksignore --redact --verbose --no-banner
```

Estado medido em 12/10/2026, depois da revisão:
- **Histórico inteiro (2.357 commits, gitleaks):** exatamente 2 achados, `3ee0082a app/.env:14` e
  `:15`.
- **Árvore atual, camada 1:** limpa.
- **Intervalo `origin/master..HEAD` (204 commits), nas duas camadas:** limpo.
- **Casos da revisão:** os 9 de segredo são pegos pelas duas camadas, e os 11 legítimos passam.

## Limitações

- **O pre-commit só passa a valer depois da integração no master, e depende do checkout principal.**
  O `core.hooksPath` aponta para `scripts/git-hooks` do checkout **principal**. Se esse checkout
  estiver numa branch sem a guarda, o hook dessa branch não a chama, e ela some sem aviso. Por isso o
  CI é a camada que não depende disso.
- **Hook se contorna** (`--no-verify` por humano; o do Claude é barrado pelo
  `block-git-writes.py`). Quem segura é o CI, e o CI só **bloqueia merge** se o check *Segredos* for
  exigido na proteção da branch `master`. Sem isso ele só avisa.
- **Placeholder minúsculo com palavra conhecida passa na camada 2.** O gitleaks não exige separador.
  Um valor como `kx9pq2mz7vwchange` (todo minúsculo, sem separador) passa no gitleaks e é pego só na
  camada 1, que exige separador. Senha fraca escrita como placeholder (`minha_senha_123`) passa nas
  duas.
- **Em código-fonte e testes, senha em constante não é avaliada.** Isso é de propósito: medimos 23
  falsos positivos no histórico. Lá valem só a regra de URL, as de formato e as regras padrão do
  gitleaks.
- **O gitleaks varre merges com `git log -p`, que não mostra a resolução de conflito.** Isso é
  dedução pela documentação do git, não foi medido no gitleaks. Quem cobre
  esse caso é a camada 1, com `--remerge-diff`.
- A camada 1 ignora arquivo binário.
- Os valores de dev versionados de propósito continuam no repositório: `APP_SECRET` em `.env.dev` e
  `.env.test`, `SYNC_CRYPTO_KEY` em `.env.test` e a chave pública do DataJud. Nenhum deles pode ser
  usado em produção. A camada 1 aceita **qualquer** valor novo nessas três chaves desses arquivos.
- `*.sql` cru não é ignorado nem barrado pelo nome, porque é legítimo em `docs/`. Dump é barrado pelo
  nome compactado (`.sql.gz`…) e pelo conteúdo.
- A imagem do gitleaks é de out/2024 (v8.21.2). Para atualizar, troque a tag **e** o digest no
  workflow e rode de novo a auditoria do histórico.
- O workflow não foi executado no GitHub, só simulado localmente com a mesma imagem e os mesmos
  argumentos. A primeira execução real acontece no primeiro PR.

## Pendências do dono

1. **Revogar** na conta Google de envio toda senha de app que não seja a de 23/09. Revisar a atividade
   de segurança e a pasta Enviados. Só depois disso as linhas `3ee0082a` podem entrar no
   `.gitleaksignore`.
2. Conferir na VPS, por hash, que `APP_SECRET`, a senha do banco e a `SYNC_CRYPTO_KEY` de produção
   **diferem** das versionadas.
3. No GitHub: ligar *secret scanning* e *push protection* e exigir o check **Segredos** na proteção da
   branch `master`.
4. Decidir a visibilidade do repositório.
5. Higiene local, não feita nesta etapa por instrução:
   - o arquivo de memória `project_email_prod_senha_app_revogada.md` guarda a senha em base64;
   - 2 transcrições `.jsonl` do Claude Code contêm a senha em texto puro.
