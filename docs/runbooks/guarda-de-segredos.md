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
apaga cópias de terceiros. O remédio é revogar a senha.

**Revogação confirmada pelo dono em 12/10/2026:** a conta Gmail antiga não é mais usada por nenhum
sistema, e as senhas de aplicativo dela foram revogadas. É o relato do dono; a sessão que preparou
esta etapa não tem acesso à conta e não verificou de forma independente.

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
- no CI, em commits de merge, olha só o que a **resolução** introduziu (`--remerge-diff`): as linhas
  adicionadas, o **nome** dos arquivos criados só na resolução (ausentes em todos os pais) e, se um
  deles for binário, recusa com `binario-na-resolucao` (auditoria 4.10, 05/11/2026).

**Regras:**

| Regra | Onde vale |
|---|---|
| Senha em URL/DSN (aceita `@` no usuário e senha codificada com `%`) | qualquer arquivo |
| Chave privada | qualquer arquivo |
| Tokens de formato conhecido (AWS, GitHub, Google, Slack, Stripe, Anthropic, OpenAI, npm, chave do Asaas `$aact_…`) | qualquer arquivo |
| `NOME=valor` para chaves com `PASSWORD`, `SENHA`, `SECRET`, `TOKEN`, `API_KEY`, `CREDENCIAL`/`CREDENTIAL`… e `PASS`/`PWD` como pedaço do nome (`DB_PASS`, não `BYPASS`); `CHAVE` e `SEGREDO` só em maiúsculas (`ESPELHO_R2_CHAVE`); valor entre aspas lido inteiro (senha do Gmail com espaços) | **só arquivo de configuração**: `.env*`, yaml, json, toml, ini, conf, sh, Dockerfile, compose |
| Binário criado só na resolução de um merge (`binario-na-resolucao`) | só no CI (`--intervalo`) |
| Nome de arquivo proibido (`.env` fora de `app/`, `.env.local`, `.env.prod*`, `*.pem`/`*.key`, `id_rsa*`, `client_secret*.json`, `*.dump`, `*.sql.gz`, `rclone.conf`, `.netrc`, `app/auth.json`…). Também cobre `git add -f` | qualquer caminho |

**Nunca imprime o valor**, só `arquivo:linha [regra] (N caracteres)`.

### 2. gitleaks v8.21.2

Roda só no CI, com a imagem fixada por digest. Soma as cerca de 150 regras da comunidade às regras
do `.gitleaks.toml`, que espelham a camada 1:
- `url-com-credencial`;
- `senha-em-variavel`;
- `senha-em-variavel-entre-aspas`;
- `asaas-api-key` (`$aact_…`, em qualquer arquivo e sem a exceção de `$`);
- `segredo-em-variavel-pt` (`*_CHAVE`, `*_SEGREDO`, `*_CREDENCIAL`, em maiúsculas, só em arquivo de
  configuração).

São dois passos no workflow:
1. `gitleaks git` sobre os commits do intervalo;
2. `gitleaks dir` sobre a **resolução dos merges** do intervalo. O
   `scripts/git-hooks/extrair-resolucao-merges.py` roda `git show --remerge-diff` em cada merge e grava
   só as linhas adicionadas, com o caminho original, numa pasta temporária. O número da linha no
   relatório desse passo é o do arquivo extraído, não o do repositório.

🔴 As regras **padrão** do gitleaks **não** pegam senha em DSN nem senha em `*_PASS`. Isso foi medido
duas vezes: a varredura do histórico não acusava o vazamento, e a revisão adversarial mostrou senhas
`DB_PASS=…` passando.

### O que conta como placeholder (as duas camadas aceitam)

- **Referências:**
  - `$VAR` e `${VAR}`, só quando o valor **inteiro** é uma variável curta (até 41 caracteres) e nunca
    `$aact_…`. Antes da auditoria 4.09 todo valor começado por `$` passava, e a chave do Asaas tem
    esse formato. As regras antigas do gitleaks (`url-com-credencial` e `senha-em-variavel*`) ainda
    aceitam qualquer `$` no início;
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

Para valor **fictício de teste** já versionado, use `VALORES_DE_TESTE_DECLARADOS`: a exceção vale só
para a combinação exata (arquivo, chave, sha256 do valor), com o motivo comentado. Foi o caso do
`APP_SECRET` do job efêmero em `.github/workflows/ci.yml`. Outro valor na mesma chave, ou o mesmo
valor em outro arquivo ou chave, continua sendo acusado.

Achados antigos do gitleaks já conferidos ficam no `.gitleaksignore`. A impressão digital é
`commit:arquivo:regra:linha`, então só vale no modo `git`, não no modo `dir`.

## Comandos

```bash
# testes da guarda, do hook, do intervalo do CI e da extração da resolução (66): rodam também no CI
python3 -m unittest discover -s scripts/git-hooks -p 'test_*.py' -v

# auditar a árvore atual inteira (só caminho/linha/regra)
git ls-files -z -- . ':(exclude)app/vendor' ':(exclude)*.lock' \
  | xargs -0 python3 -I scripts/git-hooks/verificar-segredos.py --arquivos

# auditar o histórico inteiro com o gitleaks (relatório redigido)
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD:/repo:ro" -w /repo \
  zricethezav/gitleaks:v8.21.2 git . --config .gitleaks.toml \
  --gitleaks-ignore-path .gitleaksignore --redact --verbose --no-banner
```

Estado medido em 12/10/2026 na branch de publicação `seguranca-s1-publicacao`, base `origin/master`
@ `ea71ad4e`:
- **Histórico inteiro (gitleaks):** exatamente 2 achados, `3ee0082a app/.env:14` e `:15`, o vazamento
  original. Desde 05/11/2026 estão no `.gitleaksignore` (revogação conferida pelo Samuel).
- **Árvore atual, camada 1:** limpa.
- **Intervalo `origin/master..HEAD` (só os commits da S1), nas duas camadas:** limpo.
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
- **Resolução de merge (auditoria 4.10).** O `gitleaks git` lê `git log -p`, que não mostra a
  resolução de conflito. Medido em 05/11/2026: num repositório sintético, um token SendGrid digitado
  só na resolução passou no `gitleaks git` (saída 0) e foi pego pelo passo novo `gitleaks dir` sobre a
  resolução extraída (saída 1, `sendgrid-api-token`). No merge real `885d9fc66`, `git log -p` traz 0
  arquivos e o `--remerge-diff` traz 2 (`app/.env` e `app/config/services.yaml`). Nos 1.297 merges
  do histórico, 4 criaram arquivo na resolução e nenhum seria acusado (nem pelo nome nem como
  binário); a árvore atual segue limpa na camada 1 com as regras de 4.09 e 4.10.
  - O passo novo só vê **linhas adicionadas em texto**. Binário criado na resolução é recusado pela
    camada 1 (`binario-na-resolucao`), sem ler o conteúdo; binário **alterado** na resolução não é
    acusado por nenhuma das duas.
  - O `.gitleaksignore` não vale no passo novo (a impressão digital do modo `dir` é outra). Falso
    positivo lá se resolve com `segredo:permitido`/`gitleaks:allow` na linha.
  - O pre-commit cobre o nome do arquivo novo da resolução quando está ativo (`modo_stage` lista os
    arquivos novos do índice). Merge feito pelo botão *Resolve conflicts* do GitHub não passa por
    hook: fica só o CI.
- A camada 1 ignora o **conteúdo** de arquivo binário (só olha o nome e, em merge, o binário novo da
  resolução).
- `CHAVE` e `SEGREDO` só contam em **maiúsculas**, nas duas camadas: em minúsculas, "chave" é rótulo
  comum nas traduções, no JSON de questões e em comentários (17 falsos positivos medidos na árvore
  em 05/11/2026). Um `chave_api: <valor real>` em yaml minúsculo não é acusado por nome.
- Os valores de dev versionados de propósito continuam no repositório: `APP_SECRET` em `.env.dev` e
  `.env.test`, `SYNC_CRYPTO_KEY` em `.env.test` e a chave pública do DataJud. Em
  `.env.prod.example`, o `GOOGLE_DRIVE_CREDENTIALS` (caminho do JSON da credencial, não a
  credencial) está declarado por hash em `VALORES_DE_TESTE_DECLARADOS`. Nenhum deles pode ser
  usado em produção. A camada 1 aceita **qualquer** valor novo nessas três chaves desses arquivos.
- `*.sql` cru não é ignorado nem barrado pelo nome, porque é legítimo em `docs/`. Dump é barrado pelo
  nome compactado (`.sql.gz`…) e pelo conteúdo.
- A imagem do gitleaks é de out/2024 (v8.21.2). Para atualizar, troque a tag **e** o digest no
  workflow e rode de novo a auditoria do histórico.
- O workflow não foi executado no GitHub, só simulado localmente com a mesma imagem e os mesmos
  argumentos. A primeira execução real acontece no primeiro PR.

## Pendências do dono

1. ✅ **Revogação confirmada pelo dono em 12/10/2026.** Falta decidir se as linhas `3ee0082a` entram no
   `.gitleaksignore`. A condição para isso era exatamente esta revogação. Se entrarem, a auditoria do
   histórico inteiro passa a dar zero achados.
   - 05/11/2026: conferido pelo Samuel em Senhas de app da conta Google antiga. A senha de fevereiro
     não existe mais. A 'Bluejus producao' (criada em 23/09, sem uso desde 08/10) foi apagada.
   - ✅ 05/11/2026 (auditoria 4.06, passo 2): as impressões digitais `3ee0082a:app/.env:url-com-credencial:14`
     e `:15` entraram no `.gitleaksignore`.
2. Conferir na VPS, por hash, que `APP_SECRET`, a senha do banco e a `SYNC_CRYPTO_KEY` de produção
   **diferem** das versionadas.
3. No GitHub: ligar *secret scanning* e *push protection* e exigir o check **Segredos** na proteção da
   branch `master`.
4. Decidir a visibilidade do repositório.
5. Higiene local, não feita nesta etapa por instrução:
   - o arquivo de memória `project_email_prod_senha_app_revogada.md` guarda a senha em base64;
   - 2 transcrições `.jsonl` do Claude Code contêm a senha em texto puro.
