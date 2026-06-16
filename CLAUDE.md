# JusPrime / BlueJus — Contexto Geral

SaaS jurídico multi-tenant. PHP 8.2+, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, Docker.

## Idioma

Código, comentários e commits em **português brasileiro**.
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
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'
docker exec -it jusprime_db_dev psql -U symfony -d saas          # interativo (humano)
docker exec jusprime_db_dev psql -U symfony -d saas -c "<query>" # one-shot (automação)
```

Nunca rodar `php`, `composer` ou `bin/console` fora do container.

## Git — controle humano direto

Commits, push, merge, rebase, reset e demais comandos destrutivos são responsabilidade exclusiva do desenvolvedor humano. Comandos de leitura (`status`, `diff`, `log`, `show`, `branch`) podem ser executados livremente. `block-git-writes.py` permanece ativo como garantia.

Quando uma instrução implicar comando git de escrita, o orquestrador monta o(s) comando(s), explica o que cada um faz, e entrega em bloco markdown prefixado com `# Execute manualmente no terminal externo`. Mostra antes; você aprova e executa.

Convenção de commit: imperativo em português, máx. 72 chars, sem ponto final. Branches: `nome-da-feature` · `fix-<issue>` · `refactor-<desc>`.
