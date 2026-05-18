# src/ — Estrutura de Domínios

## Layout obrigatório

Cada domínio em `src/<Dominio>/` com subpastas: `Controller/` `UseCase/` `Entity/` `Repository/` `DTO/` `Form/`

Pastas globais `src/Controller/`, `src/Entity/`, `src/Service/`, `src/Repository/` são **legado** — não criar arquivos novos lá.

## Domínios ativos

| Domínio | Pasta | Situação |
|---|---|---|
| Clientes | `src/Cliente/` | ativo |
| Expediente (processos/pastas) | `src/Expediente/` | ativo |
| Processos | `src/Processo/` | ativo |
| Pastas | `src/Pasta/` | ativo |
| Tarefas | `src/Tarefa/` | ativo |
| Perfil do usuário | `src/Profile/` | ativo |
| Compartilhado | `src/Shared/` | ativo |
| Ponto | `src/Entity/Ponto/` | legado — migrar para `src/Ponto/` |
| Agenda | `src/Entity/Agenda/` | legado — migração planejada (`src/Agenda/` ainda não criado) |
| ServiceDesk | `src/Entity/ServiceDesk/` | legado — migração planejada (`src/ServiceDesk/` ainda não criado) |
| Tenant | `src/Entity/Tenant/` | legado |
| Permission | `src/Entity/Permission/` | legado |

## Configuração por domínio

Dados específicos de cada domínio. Use ao criar/editar arquivos em
`src/<Dominio>/`. Padrões gerais de cada camada estão nas skills
(a serem criadas na próxima etapa).

| Domínio | Namespace base | Módulo permissão | Prefixo rota |
|---|---|---|---|
| Cliente | `App\Cliente` | `clientes` | `app_cliente_` |
| Expediente | `App\Expediente` | `expediente` | `app_expediente_` |
| Processo | `App\Processo` | `processos` | `app_processo_` |
| Pasta | `App\Pasta` | `pastas` | `app_pasta_` |
| Tarefa | `App\Tarefa` | `tarefas` | `app_tarefa_` |
| Profile | `App\Profile` | `profile` | `app_profile_` |
| Shared | `App\Shared` | _(transversal)_ | _(N/A)_ |

## Refatorando código legado

Antes de alterar qualquer arquivo em pasta legada (`src/Controller/`, `src/Entity/`, `src/Service/`, `src/Repository/`):

1. **Verifique se há testes cobrindo o comportamento atual** — rode `php bin/phpunit` com o filtro do arquivo/classe em questão
2. **Não confie cegamente nos testes existentes** — testes legados podem estar incompletos, mockar o que não deveriam, ou testar a implementação em vez do comportamento. Leia os testes antes de usá-los como referência de segurança
3. **Avalie e refatore os testes junto** — se os testes existentes não seguem o padrão de `app/tests/CLAUDE.md` (PHPUnit attributes, sem mock de EntityManager, DAMA rollback, Foundry v2), reescreva-os antes ou junto com a refatoração do código
4. **Se não houver testes**, escreva-os primeiro (comportamento atual, não o ideal) antes de refatorar
5. **Mova para o domínio correto** seguindo o fluxo obrigatório: crie o UseCase, depois o controller no domínio, depois remova o legado
6. **Nunca reescrever e mover ao mesmo tempo** — ou move (sem mudar comportamento) ou reescreve (no lugar certo), não os dois em um commit só

## Testes e UseCases — verificação obrigatória

Todo UseCase (novo ou refatorado) deve ter testes verificados ou criados **antes** de qualquer outra camada:

- **Unit test do UseCase** (`tests/<Dominio>/Unit/`) — cobre o comportamento de negócio com mocks das dependências
- **Functional test do controller** (`tests/<Dominio>/Functional/`) — cobre o happy path e os casos de erro via HTTP

Se o UseCase já existir mas o teste não seguir o padrão atual, refatore o teste primeiro. Um UseCase sem teste confiável não é considerado pronto.

## Regras transversais

**Multi-tenancy:** toda query filtra por `tenant`. Ao criar entidades: `$user->getTenant()`. Nunca buscar por ID sem validar posse do tenant.

**Permissões:** sempre `PermissionChecker`, nunca roles direto.
```php
$checker->canAccessModule($user, 'clientes');   // correto
in_array('ROLE_ADMIN', $user->getRoles());       // errado — não respeita hierarquia
```

**Fluxo de dados obrigatório:**
```
Request → Controller → Form/DTO → UseCase → Entity → Repository → flush()
                                                         ↑
                                         nunca pular etapas
```

## Padrões PHP/Symfony obrigatórios

- `declare(strict_types=1);` em todo arquivo PHP
- Type hints em 100% de argumentos e retornos
- Constructor property promotion com `private readonly` para dependências
- Apenas atributos PHP (`#[Route]`, `#[ORM\...]`) — nenhuma anotação docblock
- Classes `final` por padrão (serviços, use cases, controllers, DTOs, forms) — **exceto entidades Doctrine**, que não podem ser `final` por causa dos proxies gerados pelo ORM
- Comparação idêntica `===`/`!==` sempre
- Linha em branco antes de `return` (exceto bloco de uma linha)
- Nunca `else`/`elseif` após `if` que retorna ou lança
