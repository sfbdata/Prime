# Frentes paralelas em worktrees — o que já foi verificado

> Handoff para a sessão que investiga o trabalho com múltiplas worktrees.
> Tudo abaixo foi **medido neste repositório** em 2026-07-23, não inferido. Onde há comando, ele foi executado.

## 1. Estado atual (limpeza concluída)

A limpeza das worktrees foi executada pelo humano e **nada de trabalho se perdeu**.

- Sobrou apenas a worktree principal (`/home/prime/projetos/jusprime`, master).
- As 27 branches `worktree-agent-*` foram apagadas. Antes disso foi verificado por **conteúdo** (`git cherry`,
  não `rev-list`, porque o fluxo integra por cherry-pick e o hash sempre muda): 25 já estavam no master.
- As 2 restantes eram de 08/07. Todos os arquivos delas existem no master, **menos um**:
  `app/tests/Cobranca/Unit/CriarObjetoUseCaseTest.php` (115 linhas, 3 casos, incluindo rejeição cross-tenant).
  Resgatado para `~/backup/resgate-worktrees/CriarObjetoUseCaseTest.php` — vale reintegrar à suíte.
- A longa lista apagada depois saiu por `git branch -d`, que **recusa branch não contida no master**.
  Segurança por construção: nada ali tinha trabalho único.

### Branches que sobreviveram e por quê

Duas são resíduo de hash e podem sair (`0 por conteúdo`): `cobranca-ajustes-pos-taxa`, `cobranca-anotacao-historico`.

Cinco carregam trabalho que nunca entrou no master:

| Branch | Fora do master (conteúdo) | Último commit |
|---|---|---|
| `fix/processo-vincular-desvincular` | 7 | 09/06 |
| `fix/expediente-botao-ultima-pagina` | 7 | 09/06 |
| `fix/kanban-cor-faixa-mural` | 5 | 08/06 |
| `import/acervo-pastas` | 3 | 29/05 |
| `integracao-sync-master` | 1 | 14/07 |

**Ressalva:** esses números são **teto, não valor exato**. Quanto mais velha a branch, mais o master divergiu e
mais o `git cherry` erra para o lado de "não integrado" — foi exatamente o que aconteceu com as duas branches de
08/07, que pareciam ter trabalho único e no fim só tinham um arquivo. Auditar uma a uma antes de decidir.

## 2. Correção ao diagnóstico anterior

A orientação de que as worktrees `cobranca-ajustes-pos-taxa` e `fix-folha-admissao` tinham "trabalho NÃO
publicado, decida antes de remover" **estava correta quando foi escrita e ficou desatualizada com o deploy de
23/07**. Medido depois do deploy: `cobranca-ajustes-pos-taxa` = 3 commits por hash mas **0 por conteúdo**;
`fix-folha-pre-admissao` = 0 e 0. As duas já podiam sair.

Também vale registrar um ponto cego do procedimento sugerido: `git status --porcelain` mostra **arquivo não
commitado**, mas **não mostra commit não publicado**. Como o passo de limpeza usava `git branch -D` (força),
essa checagem sozinha não protegia. A checagem que protege é por conteúdo contra `origin/master`.

## 3. Fatos verificados do ambiente (o que faltava na análise)

A worktree isola o **código**. Ela não isola o **Docker** nem o **banco**. Há um container e um Postgres para
todas as frentes. Consequências, todas confirmadas:

### 3.1 O comando padrão de teste roda no repositório errado

`docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'` — o `cd app` cai no **repositório principal**,
não na worktree. Testa-se a frente errada e recebe-se um verde que não vale nada (o mesmo modo de falha do
"falso sucesso" já registrado nas regras de git do projeto). É preciso apontar o caminho:

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/<frente>/app && php bin/phpunit'
```

### 3.2 O isolamento do banco de teste já existe e não estava sendo usado

`app/config/packages/doctrine.yaml`, sob `when@test:`:

```yaml
dbname_suffix: '_test%env(default::TEST_TOKEN)%'
```

Ou seja, `TEST_TOKEN=central` → banco `saas_testcentral`, isolado, sem nenhuma mudança de código.

**Evidência de que o problema é real:** o Postgres de dev já tem `saas_test`, `saas_testwt`, `saas_testint` e
`saas_testf2` — sessões anteriores contornaram isso na mão, sem registrar.

Preparar o banco de uma frente (o `tests/bootstrap.php` **não** cria schema; é manual):

```bash
docker exec -e TEST_TOKEN=<frente> jusprime_php_dev bash -c '
  cd /var/www/.claude/worktrees/<frente>/app &&
  php bin/console doctrine:database:create --env=test --if-not-exists &&
  php bin/console doctrine:migrations:migrate --env=test -n'
```

### 3.3 O banco de dev pode ser isolado por frente

`app/.env` **é versionado** (cada worktree ganha a sua cópia) e `app/.env.local` **é gitignored**
(`app/.gitignore:3`). Então cada frente pode sobrescrever `DATABASE_URL` no `.env.local` da sua worktree e usar
um banco de dev próprio, sem sujar o diff.

### 3.4 Smoke no navegador NÃO paraleliza hoje

`nginx.conf` fixa `root /var/www/app/public` e o compose publica só `8080:80`. Logo `localhost:8080` sempre serve
o repositório principal. Duas frentes não podem ser conferidas no navegador ao mesmo tempo.

Saídas: **serializar o smoke** (grátis, suficiente para 2 frentes) ou acrescentar um segundo `server` na 8081
apontando para uma worktree (custa um bloco no `nginx.conf` + uma porta no compose; o php-fpm é compartilhado e
não precisa de container novo).

## 4. Migrações: por que a ordem importa

O Doctrine executa migrações **em ordem crescente de versão**, não na ordem do merge. Daí nascem duas ordens:

- **Produção** executa na ordem em que foi mergeado (cada deploy roda o que estava pendente).
- **Ambiente novo** (banco recriado, máquina nova) executa na ordem do timestamp.

Enquanto as duas coincidem, nada acontece. Quando divergem e as migrações tocam a mesma tabela, o schema de
produção passa a diferir do que qualquer ambiente novo produz — e isso só aparece quando alguém reconstrói.

Duas migrações são **dois arquivos com nomes diferentes**: o git não acusa conflito e o merge passa liso. Não há
nada no sistema que force a coordenação; ela precisa ser ritual.

**Ritual — responsabilidade de quem integra por segundo:**

1. `git merge master` dentro da branch, trazendo a migração da outra frente;
2. se o timestamp da sua migração for **anterior** ao da que já está no master, renomear arquivo e classe para um
   posterior — assim ordem do arquivo = ordem do merge;
3. **recriar o banco de teste do zero** (`database:drop --force` → `create` → `migrations:migrate`) — este passo é
   o que prova o par, porque só ele executa as duas na ordem canônica. Rodar `migrate` num banco que já tem a sua
   migração apenas empilha a outra por cima e reproduz a ordem de produção, não a canônica;
4. rodar a suíte nesse banco recriado;
5. se as duas migrações **tocam a mesma tabela**, leitura humana das duas antes do merge — ordem correta não
   salva de alterações incompatíveis.

## 5. Proposta de fluxo (não implementada)

Não foi construído nada disto ainda. É a recomendação que saiu da investigação:

- **`scripts/frente-abrir.sh <nome>`** — worktree a partir do master atual + banco de teste isolado + migrações +
  registro. Se a frente mexer em schema, também escreve o `.env.local` com banco de dev próprio.
- **`scripts/frente-testar.sh <nome>`** — fixa caminho e `TEST_TOKEN`; existe para eliminar o verde falso de 3.1.
- **`scripts/frente-fechar.sh <nome>`** — ritual de §4 + suíte; **para antes do merge** e entrega o comando ao
  humano (merge/push/deploy seguem sendo dele).
- **`docs/frentes-ativas.md`** — registro com domínio, se tem migração, arquivos compartilhados tocados e estágio.
  É o que permite duas sessões saberem uma da outra; sem ele, não há coordenação possível.

**Regras a escrever na skill `workflow`:**

1. Um domínio por frente.
2. Arquivos compartilhados declarados na abertura. Neste projeto os que doem: `templates/base.html.twig`, CSS
   global, rotas, enums de `src/Shared/`, `docs/`. Quem toca um desses vai sozinho ou por último.
3. Frentes com migração, **uma de cada vez** — não por impossibilidade, mas porque o custo de fazer certo
   (renomear, recriar banco, ler as duas, rodar de novo) supera o de esperar. Frentes de tela, relatório ou
   lógica paralelizam à vontade.
4. Integração em série, um piloto de git por vez.
5. Smoke serializado enquanto não houver a porta 8081.

## 5b. Correções à §3.2 e à §4 (medidas ao construir o fluxo, 2026-07-23)

A §5 foi implementada. Ao executá-la, três afirmações das seções anteriores caíram. Ficam aqui em
vez de editadas acima, para preservar o registro do que se acreditava antes.

**1. `worktree.baseRef` não é o mecanismo que dá base às frentes.** O schema do Claude Code só admite
`fresh` ou `head` (não um ref arbitrário como `origin/master`), e — mais importante — o setting é
usado pelo **fan-out** (worktrees de subagente), que precisa de `head` para carregar os contratos
committados localmente. Mudá-lo para `fresh` quebraria o fan-out. As frentes não dependem dele: o
`scripts/frente-abrir.sh` passa `origin/master` como base **explicitamente** (`git worktree add …
origin/master`). Então o setting fica `head` e as frentes ainda saem do master. (A tentativa inicial
de trocar para `fresh` foi revertida.)

**2. A receita de banco isolado da §3.2 não funciona.** `migrations:migrate` não constrói o banco de
teste, e não é assim que o `saas_test` nasceu: ele tem **zero** linhas em
`doctrine_migration_versions`. Rodar migrate num banco vazio nem completa — falha em
`Version20260508170000` (vincula o usuário E2E ao **tenant 1 fixo** sem checar se existe) e, passada
essa, em `Version20260625200952` (`legenda_cor.tenant_id` NOT NULL com linhas nulas).

**3. `schema:create` também não serve — e falha de um jeito que engana.** Ele completa sem erro, mas
entrega um banco **incompleto**: sem a extensão `unaccent`, sem as 4 funções do schema `public` e com
318 índices contra 320. Nada disso vem do mapeamento das entidades; vem de SQL cru das migrations.
Medido: a suíte deu 12 erros + ~10 falhas, quase todas de busca livre e acento, com o repositório
principal verde em 2464/2464 no mesmo código.

**A receita que funciona é clonar:**

```sql
CREATE DATABASE "saas_test<frente>" TEMPLATE "saas_test";
```

`TEMPLATE` copia extensões, funções e índices junto. Resultado após a troca: **duas frentes rodando ao
mesmo tempo, 2464/2464 e 7842 asserções cada** — idêntico ao repositório principal, nenhuma derrubando
a outra. É o que o `scripts/frente-abrir.sh` faz. Exigência do Postgres: ninguém conectado ao
`saas_test` no momento do clone.

**Consequência para o ritual de migrations da §4.** O passo 3 — "recriar o banco do zero, porque só
ele executa as duas migrations na ordem canônica" — **não é executável neste projeto hoje**: o
histórico de migrations não replica em banco vazio. Enquanto isso não for resolvido, a ordem
canônica não tem como ser provada por execução; sobra a leitura humana das duas migrations (passo 5),
que passa de complemento a única defesa quando as duas tocam a mesma tabela.

Uma guarda foi acrescentada à `Version20260508170000` (só insere o vínculo se o tenant 1 existir).
É segura — a migration é no-op em produção e já foi aplicada em dev/teste —, mas **sozinha não
resolve**: a migration seguinte quebra por outro motivo. Consertar a cadeia inteira é trabalho à
parte, não decidido.

## 6. Decisões que sobraram para o humano

- Auditar as 5 branches de junho/maio da §1 e decidir o que aproveitar.
- Reintegrar `CriarObjetoUseCaseTest.php` resgatado (tem caso cross-tenant).
- Apagar `cobranca-ajustes-pos-taxa` e `cobranca-anotacao-historico` (0 conteúdo único).
- Decidir se vale a porta 8081 para smoke paralelo.
