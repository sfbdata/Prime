# Controller/ — Regras (herda de src/Controller/CLAUDE.md)

Veja as regras completas em [src/Controller/CLAUDE.md](../../Controller/CLAUDE.md).

## Namespace deste domínio

```php
namespace App\Expediente\Controller;
```

## Permissões deste domínio

Módulo: `expediente` — usar:
```php
$checker->canAccessModule($user, 'expediente');
// ou
#[IsGranted('modules.expediente.view')]
```

## Rotas

Padrão: `app_expediente_<acao>` (ex.: `app_expediente_listar`, `app_expediente_criar`)
