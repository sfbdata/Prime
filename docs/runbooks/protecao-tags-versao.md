# Proteção das tags de versão (v*) no GitHub

Como impedir que alguém crie, mova ou apague uma tag `vX.Y.Z` fora do workflow Release, e como
reverter. Escrito em 14/10/2026, antes da aplicação — nada daqui foi configurado ainda.
Política de versões: [docs/versionamento.md](../versionamento.md).

## 1. Diagnóstico (medido em 14/10/2026)

| Item | Estado |
|---|---|
| Repositório | `sfbdata/Prime`, **público**, de **conta pessoal** (não organização); um colaborador, `sfbdata` (admin) |
| Rulesets | nenhum |
| Proteção do master | PR com "Segredos" e "Lints e testes unitários" (app 15368), `enforce_admins`, sem force push |
| Permissão padrão dos workflows | leitura (`default_workflow_permissions: read`) |
| Tags | `v1.0.0` (leve, `4940cc87cc833f084379124a97e38ce49e2052ed`) e `pre-refactor-identidade-global` |
| Ambientes | só `copilot`, sem regra (com a exceção de admin ligada, o padrão; não guarda segredo e não tem relação com o `release`) |
| Deploy keys | uma: `deploy-prime-vps` (id 154375478), **com escrita** (`read_only: false`), usada pela VPS no `git pull` |

Quem pode ser exceção (bypass) num ruleset, pela API (`POST /repos/{owner}/{repo}/rulesets`):
`Integration` (GitHub App, por ID), `RepositoryRole`, `Team`, `OrganizationAdmin`, `DeployKey` e
`User`. Na prática, aqui:

- **O `GITHUB_TOKEN` do Actions não está disponível como exceção:** a API não tem esse tipo de ator
  (os tipos são os listados acima), e a identidade `github-actions[bot]` não é um App instalável.
  Por isso um ruleset ligado sobre o Release de antes (que criava a tag com o `GITHUB_TOKEN`)
  quebraria a primeira release.
- **`DeployKey` não é por chave:** o `actor_id` é `null` e a exceção vale para TODAS as deploy keys.
  Hoje isso incluiria a chave da VPS, que tem escrita — um invasor da VPS poderia mexer nas tags.
- **`RepositoryRole` (admin ou write) é largo demais:** é exatamente quem o ruleset quer conter.
- **`Team` e `OrganizationAdmin` não existem em conta pessoal.**
- **Resta o GitHub App próprio**, identificado pelo ID: é a única exceção nominal e estreita.

## 2. Desenho

1. **GitHub App "BlueJus Release"** da conta `sfbdata`, instalado só no `Prime`, com uma única
   permissão de repositório: `Contents: Read and write`. Sem webhook. **A chave privada existe só
   como segredo do ambiente `release`** — quem tiver uma cópia gera token do App de qualquer lugar,
   por fora do ambiente e da aprovação, e esse token é a exceção do ruleset.
2. **Ruleset de tags** para `refs/tags/v*`, ativo, com as regras `creation`, `update` e `deletion`.
   Exceção: só o App. **O admin não é exceção** — enquanto o ruleset estiver ligado, ninguém cria,
   move ou apaga tag `v*` à mão. (O admin pode desligar o ruleset; fica registrado. Contra uma
   credencial de admin roubada, o ruleset é um obstáculo, não uma barreira.)
3. **Ambiente `release`** que só aceita jobs do branch `master`, com `sfbdata` como revisor
   obrigatório e **sem** a exceção de administrador do ambiente. A chave privada do App fica
   **só** como segredo desse ambiente.
4. **Workflow Release** (`.github/workflows/release.yml`) roda no ambiente `release`; o
   `GITHUB_TOKEN` só lê; o token do App é gerado no último passo, depois de todas as recusas, só
   para este repositório e só com `contents: write`, e é revogado no fim do job. (A chave em si
   está disponível ao job inteiro, como todo segredo de ambiente; os passos antes do token rodam
   só código do master.)

### Por que disparar de outra branch não fura (item que motivou o ambiente)

`workflow_dispatch` aceita qualquer branch, e o GitHub roda o `release.yml` **daquela** branch —
que quem tem escrita pode ter alterado. As três barreiras, em ordem:

1. Job que referencia o ambiente `release` a partir de outra branch é recusado pela política de
   branches do ambiente **antes de começar**: não recebe a chave do App.
2. Job que não referencia o ambiente não tem a chave (ela não é segredo do repositório), e o
   `GITHUB_TOKEN` não é exceção do ruleset: a criação da tag é recusada.
3. O revisor obrigatório vê cada execução e aprova antes de a chave ser entregue.

O que sobra: quem tem escrita **e** consegue mergear no master (PR com os checks; hoje só o
`sfbdata`) poderia alterar o `release.yml` do master. Isso é o mesmo poder que já existe sobre todo
o código, e o revisor do ambiente aprova cada release.

## 3. Configuração — na ordem (o dono executa)

A ordem importa: o ruleset é o **último** passo. Ligado antes de o workflow novo estar no master,
ele bloqueia a release (falha segura: nenhuma tag é criada).

### 3.1 Criar e instalar o App (interface do GitHub)

1. `https://github.com/settings/apps/new`:
   - **GitHub App name:** `BlueJus Release (sfbdata)` (o nome é global no GitHub; se estiver em uso,
     acrescente um sufixo);
   - **Homepage URL:** `https://github.com/sfbdata/Prime`;
   - **Webhook:** desmarque **Active**;
   - **Repository permissions → Contents:** `Read and write` (o `Metadata: Read-only` vem junto e
     é obrigatório). Nenhuma outra permissão;
   - **Where can this GitHub App be installed?** `Only on this account`.
2. Na página do App, anote o **App ID** e o **Client ID**; em **Private keys**, clique
   **Generate a private key** (baixa um `.pem`).
3. **Install App** → conta `sfbdata` → **Only select repositories** → `Prime`.

### 3.2 Ambiente `release`, variável e segredo

```bash
# Execute manualmente no terminal externo
gh api -X PUT repos/sfbdata/Prime/environments/release --input - <<'JSON'
{"wait_timer": 0, "prevent_self_review": false,
 "reviewers": [{"type": "User", "id": 56936359}],
 "deployment_branch_policy": {"protected_branches": false, "custom_branch_policies": true}}
JSON
gh api -X POST repos/sfbdata/Prime/environments/release/deployment-branch-policies -f name=master -f type=branch

gh variable set RELEASE_APP_CLIENT_ID --env release --repo sfbdata/Prime --body "<CLIENT_ID>"
gh secret set RELEASE_APP_PRIVATE_KEY --env release --repo sfbdata/Prime < "<CAMINHO>/<arquivo>.private-key.pem"

# Na interface: Settings → Environments → release → DESMARQUE "Allow administrators to bypass
# configured protection rules" → Save protection rules. (A API documentada não aceita esse campo
# no PUT; o padrão é permitir. A conferência abaixo tem de mostrar can_admins_bypass: false.)

# Conferir
gh api repos/sfbdata/Prime/environments/release -q '{can_admins_bypass, reviewers:[.protection_rules[]?.reviewers[]?.reviewer.login], branch_policy:.deployment_branch_policy}'
gh api repos/sfbdata/Prime/environments/release/deployment-branch-policies -q '[.branch_policies[].name]'   # ["master"]
gh secret list --env release --repo sfbdata/Prime                                                            # RELEASE_APP_PRIVATE_KEY
```

`56936359` é o id da conta `sfbdata`. `prevent_self_review` fica `false` porque há um único
mantenedor: com `true`, nenhuma release seria aprovada. A aprovação vira um clique deliberado,
não uma segunda pessoa.

**Autoaprovação (quem dispara aprova):** a documentação do GitHub trata "Prevent self-reviews"
como opção a LIGAR, e diz que, ligada, quem iniciou o deployment não aprova "mesmo sendo revisor
obrigatório" — ou seja, desligada, aprova. Vale em repositório público no plano Free. A prova
prática é a primeira release: ao disparar, a execução para em "Waiting" e o botão **Review
deployments** aparece para você. Se o GitHub recusar a autoaprovação, a alternativa que não
enfraquece a proteção contra tag é tirar o revisor e manter o resto
(`{"reviewers": [], "wait_timer": 5, ...}` no mesmo PUT): a política de branches, o ruleset e a
proteção do master continuam fechando os caminhos da seção 2; o que se perde é o clique de
confirmação, trocado por 5 minutos em que a execução pode ser cancelada.

**Depois de gravar o segredo, apague o `.pem` e não guarde cópia, nem em cofre de senhas.** O
navegador o baixa no **Windows** (`C:\Users\<você>\Downloads`, que no WSL é
`/mnt/c/Users/<você>/Downloads`): o `gh secret set` do passo acima pode ler dali mesmo. Um `shred`
sobre `/mnt/c` não garante nada (NTFS via WSL). Apague pelo Windows com **Shift+Delete** (sem
passar pela lixeira) e confira que a pasta Downloads não está no OneDrive nem em outro serviço de
sincronização — se estiver, apague também a versão na nuvem e na lixeira do serviço. Qualquer cópia da chave é um segundo caminho para criar tag `v*`, por
fora do ambiente, da política de branches e da aprovação. Se o segredo se perder ou a chave
vazar: gere uma chave nova no App, grave-a de novo no ambiente e apague a antiga na página do App
(Private keys → Delete). Manter a cópia é decisão consciente do dono, e então o App deixa de ser
"só o workflow".

### 3.3 Mergear o workflow novo

O PR desta frente (`eng-protecao-tags`), com os checks verdes. Depois do merge, ainda sem ruleset,
nada muda para quem usa o sistema.

### 3.4 Ligar o ruleset

```bash
# Execute manualmente no terminal externo
APP_ID=<APP_ID>   # o App ID numérico do passo 3.1, NÃO o Client ID
jq -n --argjson app "$APP_ID" '{
  name: "Tags de versão (v*)",
  target: "tag",
  enforcement: "active",
  conditions: {ref_name: {include: ["refs/tags/v*"], exclude: []}},
  rules: [{type: "creation"}, {type: "update"}, {type: "deletion"}],
  bypass_actors: [{actor_id: $app, actor_type: "Integration", bypass_mode: "always"}]
}' | gh api -X POST repos/sfbdata/Prime/rulesets --input - -q '"ruleset id: \(.id)"'

gh api repos/sfbdata/Prime/rulesets -q '.[]|{id,name,target,enforcement}'

# Conferir a exceção: o admin NÃO pode passar (tem de dizer "never") e só o App está na lista
RULESET_ID=$(gh api repos/sfbdata/Prime/rulesets -q '.[]|select(.name=="Tags de versão (v*)")|.id')
gh api "repos/sfbdata/Prime/rulesets/$RULESET_ID" -q '{enforcement, current_user_can_bypass, bypass_actors, conditions, rules:[.rules[].type]}'
```

Na interface (Settings → Rules → Rulesets → "Tags de versão (v*)"), a lista **Bypass list** tem de
mostrar o App pelo nome. Se mostrar outra coisa — ou nada —, o `actor_id` não era o App ID:
corrija pela própria interface (remova a entrada e adicione o App) antes dos testes.

Não há modo de ensaio: o `evaluate` dos rulesets é só do GitHub Enterprise. O ruleset nasce ativo,
e os testes abaixo são feitos com ele ativo.

## 4. Testes (com o ruleset ativo)

Nenhum teste cria tag oficial. Rode a partir do checkout principal, que já tem o remoto `origin`.

```bash
# Execute manualmente no terminal externo
cd /home/prime/projetos/jusprime

# T1 — o admin NÃO cria tag v* (esperado: push rejeitado por violação de regra)
git tag v0.0.0-teste-ruleset origin/master
git push origin refs/tags/v0.0.0-teste-ruleset
git tag -d v0.0.0-teste-ruleset
#   Se o push foi ACEITO, o ruleset não está valendo: apague a tag de teste
#   (git push origin --delete v0.0.0-teste-ruleset) e revise o passo 3.4 antes de seguir.

# T2 — a release a partir de outra branch NÃO entra no ambiente
#   (esperado: o job falha com "Branch ... is not allowed to deploy to release", sem pedir
#    aprovação e sem rodar nenhum passo)
gh workflow run release.yml --repo sfbdata/Prime --ref eng-protecao-tags -f versao=9.9.9
gh run watch --repo sfbdata/Prime "$(gh run list --repo sfbdata/Prime --workflow release.yml -L 1 --json databaseId -q '.[0].databaseId')"

# T3 — o GitHub registrou a recusa do T1
gh api "repos/sfbdata/Prime/rulesets/rule-suites?ref=refs/tags/v0.0.0-teste-ruleset" -q '.[]|{ref,actor_name,result}'
```

T2 só prova a barreira do ambiente numa branch que tenha o `release.yml` NOVO (com
`environment: release`): a própria `eng-protecao-tags`, que continua no remoto depois do merge (o
repositório não apaga branch mergeada), ou uma branch nova cortada do master depois do merge. Em
qualquer outra branch o teste "passa" pelo motivo errado: o `release.yml` antigo não usa o
ambiente e é recusado pela própria checagem de master, e branches sem o arquivo dão "workflow não
encontrado". Se aparecer o pedido de aprovação, a política de branches NÃO está valendo: rejeite
a execução e revise o passo 3.2.

**Opcional (prova que tag publicada não se apaga):** `git push origin --delete v1.0.0` tem de ser
rejeitado. Se por erro for aceito, a tag volta sem perda — ela é leve e o commit é conhecido:
desligue o ruleset (seção 5), `git push origin 4940cc87cc833f084379124a97e38ce49e2052ed:refs/tags/v1.0.0`
e religue.

**O caminho positivo** (o App cria a tag, e você aprova a própria execução) só é exercido na
primeira release de verdade. Se ali o passo "Criar tag e release" falhar, nada foi criado:
confira a exceção do ruleset (seção 3.4), a instalação do App no `Prime` e o segredo do ambiente.
Em último caso, desligue o ruleset, rode de novo e **religue logo em seguida** — enquanto ele
estiver desligado, qualquer um com escrita cria tag `v*`.

## 5. Reversão

```bash
# Execute manualmente no terminal externo
RULESET_ID=$(gh api repos/sfbdata/Prime/rulesets -q '.[]|select(.name=="Tags de versão (v*)")|.id')

# Desligar sem perder a configuração (emergência: é a "chave de quebra-vidro" do admin)
gh api -X PUT "repos/sfbdata/Prime/rulesets/$RULESET_ID" -f enforcement=disabled
# Religar
gh api -X PUT "repos/sfbdata/Prime/rulesets/$RULESET_ID" -f enforcement=active
# Remover de vez
gh api -X DELETE "repos/sfbdata/Prime/rulesets/$RULESET_ID"

# Ambiente (apaga junto o segredo e a variável)
gh api -X DELETE repos/sfbdata/Prime/environments/release
```

O App se desinstala em `https://github.com/settings/installations` e se apaga na página dele
(Advanced → Delete). Voltar o workflow para o `GITHUB_TOKEN` é reverter o merge desta frente — e
só funciona com o ruleset desligado.

**Recuperação por incidente:**

| Situação | O que fazer |
|---|---|
| Chave privada vazou (ou apareceu fora do segredo) | **1º suspenda a instalação** (`https://github.com/settings/installations` → o App → **Suspend**): isso invalida os tokens já emitidos, que valem até 1 hora mesmo depois de a chave ser apagada. 2º na página do App, **Generate a private key**, grave a nova no ambiente (`gh secret set RELEASE_APP_PRIVATE_KEY --env release ...`) e **Delete** na antiga. 3º reative a instalação. Confira as tags `v*` (`git ls-remote --tags origin 'v*'`), o histórico de releases **e as branches** (`git ls-remote --heads origin`): o token tem `contents: write` e empurra para qualquer branch sem proteção. |
| App comprometido | Desinstale o App (`https://github.com/settings/installations`): o token deixa de ser emitido e a exceção do ruleset fica sem ninguém. Releases param até um App novo ser criado e posto no ruleset. |
| Exceção com o ID errado | O Release falha em "Criar tag e release" sem criar nada. Corrija a exceção na interface do ruleset (seção 3.4) e rode de novo. |
| Tag `v*` errada criada pelo próprio workflow | Tag publicada não se move: lance uma versão de correção (PATCH). Apagar exige desligar o ruleset, o que fica registrado. |
| Release bloqueada e urgente | Desligue o ruleset, rode o Release, **religue**. Registre o motivo no PR de release. |

## 6. Limitações do plano e das permissões

- **Funciona porque o repositório é público.** No plano Free, rulesets, revisores de ambiente e
  segredos de ambiente só existem em repositório público. Se o `Prime` virar privado, cada recurso
  passa a depender de plano pago (e os revisores obrigatórios em repositório privado, de um plano
  mais alto que o Pro): confira na documentação antes de mudar a visibilidade. Sem eles, a
  proteção some e o Release para (falha segura, sem tag).
- **Sem modo de ensaio** (`evaluate` é do Enterprise): o ruleset é testado já ativo.
- **O admin fica sem atalho:** nem o dono cria tag `v*` à mão. A saída de emergência é desligar o
  ruleset (seção 5), que fica registrado no histórico do ruleset.
- **O ruleset protege a tag, não a release.** Quem tem escrita ainda edita ou apaga a release do
  GitHub (o texto); a tag e o commit ficam. Apagar a release não apaga a tag protegida.
- **Revisor único:** com um só mantenedor, a aprovação do ambiente é um clique deliberado, não um
  segundo par de olhos.

## 7. Fora deste escopo, mas encontrado aqui

A deploy key `deploy-prime-vps` tem **escrita**, e a VPS só faz `git pull`. Uma chave só de
leitura basta e tira da VPS o poder de empurrar branches (o master já está protegido). Trocar exige
gerar a chave nova na VPS e cadastrá-la como somente leitura antes de apagar a antiga, para o
deploy não parar. Procedimento: [deploy-key-somente-leitura.md](deploy-key-somente-leitura.md).
