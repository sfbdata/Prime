# JusPrime / BlueJus — Contexto Geral

SaaS jurídico multi-tenant. PHP 8.2+, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, Docker.

## Idioma

Código, comentários, explicações pensamentos e commits em **português brasileiro**.
`camelCase` métodos/variáveis · `PascalCase` classes · `snake_case` rotas/templates/colunas DB.

## Arquitetura em uma frase

Cada domínio em `app/src/<Dominio>/` com `Controller/ UseCase/ Entity/ Repository/ DTO/ Form/`.
Fluxo: `Request → Controller → Form/DTO → UseCase → Entity → Repository → flush()`.

Estrutura detalhada, regras transversais (multi-tenancy, permissões), padrões PHP/Symfony e refatoração de legado → ver `app/src/CLAUDE.md`.

Modelo de autorização (4 camadas, bypasses, falhas conhecidas) → ver `docs/AUTORIZACAO.md`. Leia antes de mexer em qualquer coisa de permissão.

## Orquestrador & ciclo de trabalho

O agente principal atua como orquestrador: analisa, decompõe, define contratos,
delega, integra e valida o trabalho final.

Subagentes seguem o princípio do menor privilégio:

- agentes de investigação e revisão são read-only;
- agentes de implementação podem escrever quando explicitamente delegados;
- todo subagente com escrita deve trabalhar em escopo exclusivo, sem sobreposição
  com outro agente escritor;
- trabalhos paralelos com escrita devem usar isolamento por worktree;
- revisores permanecem read-only e nunca corrigem diretamente o que apontam;
- nenhum subagente integra, faz merge, push ou decide sozinho alterações
  arquiteturais compartilhadas.

O orquestrador deve definir contratos e dependências antes de iniciar escrita
paralela. Só podem executar simultaneamente tarefas independentes, com limites
claros e verificáveis.

Comportamento completo, ciclo e regras de docs → ver `.claude/skills/workflow`.

**Risco** (define o rigor): ALTO = ponto eletrônico, identidade User/Tenant ·
MÉDIO = TenantRole/Permission/Profile · BAIXO = demais.

**Ciclo:** investigar (subagente read-only) → planejar (registra spec em
`docs/specs/` se ALTO/MÉDIO) → implementar (orquestrador na tarefa única, ou
subagentes implementadores delegados no fan-out) → revisar contra a spec/descrição
(`feature-review-agent`, read-only, só aponta furos) → corrigir (orquestrador) →
conferir; em ALTO, re-revisar antes de seguir. Disparo da revisão: comando
`/review`, não confie só na auto-delegação.

## Fluxo de desenvolvimento

Antes de implementar funcionalidade ou correção:
1. Analise ou crie o(s) UseCase(s) envolvidos
2. Escreva ou ajuste os testes (unit do UseCase + functional do controller)
3. Só então implemente o restante

Skills em `.claude/skills/` carregam conforme a camada: `criar-controller`,
`criar-entity`, `criar-repository`, `criar-usecase`, `criar-dto`, `criar-form`.
A skill `workflow` carrega no início de qualquer tarefa de implementação ou refatoração.

## Docker — todos os comandos dentro do container

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console <cmd>'
docker exec jusprime_php_dev bash -c 'cd app && composer <cmd>'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'              # suíte completa
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter <Nome>'  # um teste/classe
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/<Dominio>/Unit'  # uma pasta
docker exec -it jusprime_db_dev psql -U symfony -d saas          # interativo (humano)
docker exec jusprime_db_dev psql -U symfony -d saas -c "<query>" # one-shot (automação)
```

PHPUnit roda em `APP_ENV=test` com `failOnDeprecation/Notice/Warning` ativos: um
deprecation derruba a suíte. DAMA faz rollback transacional por teste, Foundry v2
para factories — detalhes em `app/tests/CLAUDE.md`.

Testes E2E (Playwright, fora do container, na raiz `e2e/`): `cd e2e && npm test`
(`npm run test:headed` / `npm run test:ui` para depurar).

**O smoke no navegador é do dono.** Não abra o Playwright por conta própria — nem para "conferir se ficou
bom". Só quando ele pedir, com essas palavras. Entregue a mudança com a suíte verde e **diga o que precisa
ser olhado na tela**; quem olha é ele. Vale para as ferramentas `mcp__playwright__*` e para `npx playwright`.

Nunca rodar `php`, `composer` ou `bin/console` fora do container.

**Uploads em DEV (permissão):** o container roda como `${UID:-1000}` (bind-mount do repo). Os diretórios
`app/public/uploads/*` (clientes, justificativas, chamados, pastas, perfil, tarefas) precisam ser graváveis
por esse uid. Se um upload falhar com *Permission denied* (dirs como `uid 33`/`www-data` da imagem prod),
alinhe o dono: `docker exec -u 0 jusprime_php_dev chown -R 1000:1000 /var/www/app/public/uploads`. Em
ambiente de **teste** isso não acontece (config aponta para `var/uploads-test/*`, gravável).

## Console do Symfony — pergunte ao framework antes de grepar

O `debug:*` lê o **container real** (o que o Symfony efetivamente montou). O `grep` lê o que o código
*parece* dizer. Antes de caçar rota, serviço, config, filtro Twig ou mapeamento no código-fonte, rode o
comando: é mais rápido e é autoritativo. Todos rodam via `docker exec` (ver seção acima).

| Quero saber | Comando |
|---|---|
| que rotas existem · URL/controller de uma rota | `debug:router` · `debug:router <nome_da_rota>` |
| se um serviço está registrado e qual a classe | `debug:container <trecho>` |
| o que dá para injetar por type-hint no construtor | `debug:autowiring <trecho>` |
| a config **efetiva** de um bundle (após o merge) | `debug:config <bundle>` |
| filtros/funções/globais Twig disponíveis | `debug:twig` |
| quais entidades estão mapeadas | `doctrine:mapping:info` |
| se o **mapeamento** Doctrine está são | `doctrine:schema:validate --skip-sync` |
| se o **banco** está em dia com o mapeamento | `doctrine:schema:validate` |
| quem escuta um evento | `debug:event-dispatcher <evento>` |
| se Twig / YAML / container quebrou | `lint:twig templates` · `lint:yaml config` · `lint:container` |

### Geradores (`make:*`) — não servem aqui, com uma exceção

O MakerBundle gera no layout **padrão** do Symfony (`src/Entity/`, `src/Controller/`, `src/Form/`), não em
`app/src/<Dominio>/`, e sem tenant, DTO ou UseCase. O único ajuste de namespace (`maker.root_namespace`) é
global — não há como apontá-lo para o layout de domínio. Escreva à mão seguindo a skill da camada
(`criar-*`); cada uma registra o que o gerador correspondente faria de errado. Ressalvas dos geradores de
teste → `app/tests/CLAUDE.md`.

**A exceção é `make:migration`**, que é o caminho normal — mas o arquivo gerado nunca vale puro:

```bash
# 1. ANTES de gerar: fotografe a divergência que já existia (essa não é sua)
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:update --dump-sql'
# 2. gere a migration
# 3. compare: tudo que já aparecia no passo 1 sai do arquivo gerado
```

Duas fontes de lixo no diff, ambas silenciosas: alteração de **outra frente** ainda não aplicada no dev, e
`DROP INDEX` em **índice funcional** — o Doctrine não sabe representá-lo no mapeamento, então propõe apagar
índices criados por SQL cru (a `Version20260710130000` já avisa disso num comentário). Aceitar um desses
`DROP` não quebra nada visível: derruba performance e, se o índice for `unique`, deixa entrar duplicata.

## Documentação oficial — conferir quando não há precedente aqui

O conhecimento do modelo tem data de corte e não distingue "eu sei" de "eu acho". O gatilho é objetivo, não
depende de eu me sentir inseguro: **vou usar uma API do Symfony/Doctrine que não tem precedente neste
repositório?** (atributo, opção de config, método ou serviço que um `grep` em `app/` não encontra). Se não
tem, confira na doc antes de escrever — é justamente o caso em que estou tirando da memória.

- Symfony 7.4 → `https://symfony.com/doc/7.4/<tema>.html` (`/doc/current/` aponta para a versão mais nova, que não é necessariamente a nossa)
- Doctrine ORM 3.x → `https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/`
- Comportamento de um comando → `php bin/console <cmd> --help` (autoritativo para a versão instalada aqui)

Isso vale para o **framework**. As regras **da casa** (multi-tenancy, UseCase, nomes de pasta) não estão em
documentação nenhuma — moram nos `CLAUDE.md` por camada e nas skills `criar-*`.

## Git — commit local permitido, publicação é humana

Claude Code **pode** preparar staging (`git add`) e criar **commits locais** (`git commit`) — revisando antes `git status`, o diff e os arquivos staged, sem `git add .` cego que arraste alteração fora do escopo. Comandos de leitura (`status`, `diff`, `log`, `show`, `branch`) seguem livres.

**Continuam responsabilidade exclusiva do humano** (proibidos para o Claude): `push`, `merge`, `rebase`, `reset` e demais operações que publiquem ou reescrevam histórico. **Claude cria commits locais, mas nunca publica alterações remotamente.** `block-git-writes.py` permanece ativo como garantia técnica: libera `git add`/`git commit` (e `git cherry-pick` **individual** — um commit por vez —, usado só pela integração do orquestrador no fan-out; ver skill `workflow`), bloqueia push/merge/rebase/reset e demais escritas sensíveis, e não aceita `--no-verify`. O subagente `feature-implementer` tem trava própria que bloqueia até o cherry-pick (ele nunca integra).

Quando uma instrução implicar `push`/`merge`/`rebase`/`reset` (ou outra operação sensível), o orquestrador monta o(s) comando(s), explica o que cada um faz, e entrega em bloco markdown prefixado com `# Execute manualmente no terminal externo`. Mostra antes; você aprova e executa. Para commits locais isso não é mais necessário — o Claude commita direto.

Convenção de commit: imperativo em português, máx. 72 chars, sem ponto final. Branches: `nome-da-feature` · `fix-<issue>` · `refactor-<desc>`.

## Mapa de documentação (CLAUDE.md por camada)

O contexto detalhado mora nos arquivos da camada que você está tocando — leia o relevante antes de editar:

| Onde | O que cobre |
|---|---|
| `app/src/CLAUDE.md` | layout de domínios, legado, regras transversais, padrões PHP/Symfony |
| `app/src/Controller/CLAUDE.md` | padrões de controller, rotas, permissões |
| `app/src/Entity/CLAUDE.md` | entidades Doctrine, UUID, multi-tenant, enums |
| `app/src/Repository/CLAUDE.md` | filtro de tenant obrigatório, paginação, DTOs via DQL |
| `app/src/Shared/CLAUDE.md` | código transversal |
| `app/templates/CLAUDE.md` | convenções Twig |
| `app/tests/CLAUDE.md` | tipos de teste, DAMA, Foundry, attributes PHPUnit |
| `docs/AUTORIZACAO.md` | modelo de autorização (4 camadas, bypasses, falhas) |
| `docs/specs/` | specs de features ALTO/MÉDIO risco |
