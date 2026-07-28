---
name: criar-controller
description: "Padrões para criar ou refatorar Controllers Symfony do jusprime: estrutura, convenções, rotas, permissões, heurística 5-10-20. Carregue sempre que a tarefa envolver criar, editar ou revisar um arquivo *Controller.php em app/src/<Dominio>/Controller/."
---

# Controller/ — Regras da Camada HTTP

## Responsabilidade única

Controller = "glue code" HTTP. Nada além disso.

**Permitido:**
- Receber `Request`, ler parâmetros, mapear DTO
- Chamar um UseCase ou service
- Retornar `Response` / `JsonResponse` / `RedirectResponse`
- Verificar permissão com `PermissionChecker` ou `#[IsGranted]`

**Proibido:**
- `$entityManager->persist()` / `flush()` direto
- Lógica de negócio (cálculos, validações de domínio, loops)
- Queries ao banco (sem passar por Repository)
- Transformações complexas de dados

## Estrutura obrigatória

```php
<?php
declare(strict_types=1);
namespace App\<Dominio>\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class <Nome>Controller extends AbstractController
{
    public function __construct(
        private readonly <NomeUseCase> $useCase,
    ) {
    }

    #[Route('/rota', name: 'app_<dominio>_<acao>', methods: ['GET'])]
    public function <acao>(): Response
    {
        // 1. validar permissão
        // 2. montar input (DTO ou parâmetros simples)
        // 3. chamar useCase->executar(...)
        // 4. retornar response
    }
}
```

## Convenções

- Nome: `<Entidade>Controller` — sufixo obrigatório
- Rotas: `snake_case`, padrão `app_<dominio>_<acao>` (ex.: `app_cliente_listar`)
- Sempre `final` — não estender controllers
- Injeção apenas no construtor com `private readonly`
- CSRF em todo POST/DELETE sensível: `$this->isCsrfTokenValid('acao', $token)`
- Para form Symfony: uma única action renderiza E processa (`GET` exibe, `POST` processa, redireciona)

## Permissões

```php
// Via atributo (preferido):
#[IsGranted('modules.clientes.view')]

// Via checker no método:
if (!$this->checker->canAccessModule($user, 'clientes')) {
    throw $this->createAccessDeniedException();
}
```

## Heurística dos 5-10-20

Cada action deve ter no máximo ~20 linhas; cada controller no máximo ~10 actions; cada action no máximo ~5 variáveis locais. Se ultrapassar, extrair para UseCase.

## `make:controller` / `make:crud` — não use

Escreva o controller à mão seguindo este arquivo.

- **`make:controller`** gera em `src/Controller/` (não em `app/src/<Dominio>/Controller/`), sem permissão, sem DTO e sem UseCase. Sobra só o `<?php` — não economiza nada e ancora no lugar errado.
- **`make:crud`** é ativamente perigoso: o template gera `findAll()` **sem filtro de tenant**, `persist()`/`flush()` direto na action e a entidade ligada direto no form. Isso rompe o isolamento multi-tenant e pula a camada DTO/UseCase.

Para **descobrir** rotas já existentes, use `debug:router` em vez de grepar — ver a tabela no `CLAUDE.md` da raiz.
