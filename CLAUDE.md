# JusPrime — Diretrizes IA

SaaS jurídico multi-tenant. PHP 8.2+, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, Docker.

## Idioma
Código, comentários e commits em **português brasileiro**. `camelCase` métodos/variáveis, `PascalCase` classes, `snake_case` rotas/templates. Aspas simples, `===`/`!==`.

## Arquitetura — Obrigatório para código novo
Cada domínio em `src/<Dominio>/` com: `Controller/` `UseCase/` `Domain/` `Entity/` `Repository/` `DTO/` `Form/`

- **Controller:** só HTTP — chama UseCase, retorna response. Nada de lógica.
- **UseCase:** orquestra o fluxo (um por ação de negócio)
- **Domain:** regras puras — sem `Symfony\`, sem Doctrine
- **DTO:** entrada/saída de dados — nunca passar entidade Doctrine para a view ou form
  - Fluxo: Form → DTO → UseCase → Entity → Repository persiste
- **Repository:** estende `ServiceEntityRepository`

Domínios: Cliente, Processo, Ponto, Tarefa, Agenda, ServiceDesk, Tenant, Permission.

Pastas globais `src/Controller/`, `src/Entity/`, `src/Service/` etc. são **legado** — não criar arquivos novos lá.

## Regras Críticas

**Multi-tenancy:** toda query filtra por `tenant`. Ao criar entidades: `$user->getTenant()`. Nunca buscar por ID sem validar posse do tenant.

**Permissões:** sempre `PermissionChecker`, nunca roles direto:
```php
$checker->canAccessModule($user, 'clientes');   // correto
in_array('ROLE_ADMIN', $user->getRoles());       // errado
```
Padrão: `modules.<modulo>.view` · `admin.<area>.<acao>` · `resources.<tipo>.<acao>`
Acesso granular a recursos: usar `ResourceAccessTrait` no controller.

**Docker:** todos os comandos dentro do container, a partir de `/var/www/app`:
```bash
docker exec -it jusprime_php_dev bash   # entrar no container
# dentro: cd app && php bin/console <cmd>

# ou direto:
docker exec jusprime_php_dev bash -c 'cd app && php bin/console <cmd>'
docker exec jusprime_php_dev bash -c 'cd app && composer <cmd>'
```

**Comandos — nunca criar/editar manualmente o que um comando já faz:**
- Arquivos de código → `make:controller` `make:entity` `make:form` etc.
- Dependências → `composer require`
- Schema do banco → `doctrine:migrations:diff` + `doctrine:migrations:migrate`
- Inspecionar container/rotas → `debug:container` · `debug:router` · `debug:autowiring`

## Symfony
- **Entidades:** usar constructor property promotion do PHP 8.x — nunca declarar propriedades separadas do construtor.
- `#[Route(...)]` para rotas, `#[ORM\...]` para mapeamento
- Controllers estendem `AbstractController`. Injeção via construtor `private readonly`. Nunca `$this->get()`.
- Autowire + autoconfigure habilitados. Services explícitos só quando necessário.
- Migration a cada alteração de schema: `doctrine:migrations:diff` → revisar SQL gerado → commitar junto com a entidade. Coluna `group`: `name: '"group"'`.

## Twig
- Templates em `templates/<modulo>/`, parciais em `_partials/`. Todo texto via `|trans`.
- Permissões: `{{ can_access_module('clientes') }}` via `PermissionExtension`.

## Testes
- Unit → `tests/<Dominio>/Unit/` — PHPUnit (Services, Entities, UseCases isolados)
- Functional → `tests/<Dominio>/Functional/` — `WebTestCase` (Controllers/endpoints)
- Todo novo Service e endpoint precisa de teste
- Rodar: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'`

## Segurança
- CSRF: `$this->isCsrfTokenValid()` em todo POST/DELETE sensível
- Senhas: sempre `UserPasswordHasherInterface` — nunca plain-text
- Validação: Symfony Validator no DTO — nunca na entidade ou controller
- Queries: sempre parâmetros nomeados em DQL/SQL — nunca concatenação de string
- Uploads: validar mimetype + extensão + tamanho antes de persistir

## Git
Commits imperativos: "Adicionar X", "Corrigir Y" (máx. 72 chars, sem ponto). Branch: `nome-da-feature` · `fix-<issue>` · `refactor-<desc>`
