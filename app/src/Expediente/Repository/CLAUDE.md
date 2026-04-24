# Repository/ — Regras (herda de src/Repository/CLAUDE.md)

Veja as regras completas em [src/Repository/CLAUDE.md](../../Repository/CLAUDE.md).

## Namespace deste domínio

```php
namespace App\Expediente\Repository;
```

## Filtro de tenant obrigatório

Toda query deve incluir:
```php
->andWhere('e.tenant = :tenant')
->setParameter('tenant', $tenant)
```
