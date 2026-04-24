# Controller/ — Legado

> Esta pasta é **legado**. Novos controllers vão em `src/<Dominio>/Controller/`.
> Regras completas em [src/_referencia/Controller/CLAUDE.md](../_referencia/Controller/CLAUDE.md).

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
