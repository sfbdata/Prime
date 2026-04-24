# JusPrime — Contexto Geral

SaaS jurídico multi-tenant. PHP 8.2+, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, Docker.

## Idioma

Código, comentários e commits em **português brasileiro**.
`camelCase` métodos/variáveis · `PascalCase` classes · `snake_case` rotas/templates/colunas DB.

## Arquitetura

Cada domínio em `app/src/<Dominio>/` com: `Controller/` `UseCase/` `Entity/` `Repository/` `DTO/` `Form/`

Fluxo obrigatório: `Request → Controller → Form/DTO → UseCase → Entity → Repository → flush()`

Pastas `src/Controller/`, `src/Entity/`, `src/Service/`, `src/Repository/` são **legado** — não criar arquivos novos lá.

## Fluxo de desenvolvimento obrigatório

Antes de implementar qualquer funcionalidade ou correção:
1. Analise ou crie o(s) UseCase(s) envolvidos
2. Escreva ou ajuste os testes (unit do UseCase + functional do controller)
3. Só então implemente o restante (controller, template, form, etc.)

## Regras que se aplicam em todo arquivo novo

- `declare(strict_types=1);` · type hints em 100% de args/retornos · `private readonly` no construtor
- Classes `final` por padrão — **exceto entidades Doctrine** (proxies via herança)
- Atributos PHP (`#[Route]`, `#[ORM\...]`) — nunca anotações docblock
- `===`/`!==` sempre · nunca `else`/`elseif` após `if` que retorna ou lança

## Multi-tenancy (crítico)

Toda query filtra por `tenant`. Nunca buscar por ID sem validar posse do tenant.

## Permissões (crítico)

```php
$checker->canAccessModule($user, 'clientes');  // correto
in_array('ROLE_ADMIN', $user->getRoles());     // errado — não respeita hierarquia
```

Padrão: `modules.<modulo>.view` · `admin.<area>.<acao>` · `resources.<tipo>.<acao>`

## Docker — todos os comandos dentro do container

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console <cmd>'
docker exec jusprime_php_dev bash -c 'cd app && composer <cmd>'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'
```

Nunca rodar `php`, `composer` ou `bin/console` fora do container.

## Git

Commits imperativos em português: "Adicionar X", "Corrigir Y" (máx. 72 chars, sem ponto final).
Branch: `nome-da-feature` · `fix-<issue>` · `refactor-<desc>`

---

## Guia de leitura dos CLAUDE.md por tarefa

Leia apenas o(s) arquivo(s) relevantes para o que está fazendo. Não leia todos de uma vez.

| Tarefa | Ler |
|---|---|
| Criar/editar controller | `app/src/_referencia/Controller/CLAUDE.md` |
| Criar/editar entidade | `app/src/_referencia/Entity/CLAUDE.md` |
| Criar/editar repository | `app/src/_referencia/Repository/CLAUDE.md` |
| Criar/editar use case | `app/src/_referencia/UseCase/CLAUDE.md` |
| Criar/editar DTO | `app/src/_referencia/DTO/CLAUDE.md` |
| Criar/editar form type | `app/src/_referencia/Form/CLAUDE.md` |
| Criar/editar template Twig | `app/templates/CLAUDE.md` |
| Escrever testes | `app/tests/CLAUDE.md` |
| Usar serviços compartilhados (storage, etc.) | `app/src/Shared/CLAUDE.md` |
| Entender domínios ativos e estrutura geral de `src/` | `app/src/CLAUDE.md` |
| Trabalhar num domínio específico | Ler também `app/src/<Dominio>/<Camada>/CLAUDE.md` se existir |
