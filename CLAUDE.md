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

A sessão principal é o orquestrador: entende, discute, planeja e **implementa**
— mas só depois de investigar/planejar, nunca pulando direto pro código.
Subagentes são **read-only**: investigam e revisam, nunca escrevem.
Comportamento completo, ciclo e regras de docs → ver `.claude/skills/workflow`.

**Risco** (define o rigor): ALTO = ponto eletrônico, identidade User/Tenant ·
MÉDIO = TenantRole/Permission/Profile · BAIXO = demais.

**Ciclo:** investigar (subagente) → planejar (registra spec em `docs/specs/` se
ALTO/MÉDIO) → implementar (orquestrador) → revisar contra a spec/descrição
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

Nunca rodar `php`, `composer` ou `bin/console` fora do container.

**Uploads em DEV (permissão):** o container roda como `${UID:-1000}` (bind-mount do repo). Os diretórios
`app/public/uploads/*` (clientes, justificativas, chamados, pastas, perfil, tarefas) precisam ser graváveis
por esse uid. Se um upload falhar com *Permission denied* (dirs como `uid 33`/`www-data` da imagem prod),
alinhe o dono: `docker exec -u 0 jusprime_php_dev chown -R 1000:1000 /var/www/app/public/uploads`. Em
ambiente de **teste** isso não acontece (config aponta para `var/uploads-test/*`, gravável).

## Git — controle humano direto

Commits, push, merge, rebase, reset e demais comandos destrutivos são responsabilidade exclusiva do desenvolvedor humano. Comandos de leitura (`status`, `diff`, `log`, `show`, `branch`) podem ser executados livremente. `block-git-writes.py` permanece ativo como garantia.

Quando uma instrução implicar comando git de escrita, o orquestrador monta o(s) comando(s), explica o que cada um faz, e entrega em bloco markdown prefixado com `# Execute manualmente no terminal externo`. Mostra antes; você aprova e executa.

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
