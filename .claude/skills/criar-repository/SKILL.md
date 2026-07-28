---
name: criar-repository
description: "Padrões para criar ou refatorar Repositories Doctrine do jusprime: filtro tenant obrigatório, paginação, DTOs via DQL NEW, QueryBuilder vs DQL. Carregue ao criar, editar ou revisar arquivos *Repository.php em app/src/<Dominio>/Repository/."
---

# Repository/ — Regras da Camada de Persistência

## Responsabilidade

Repository = único ponto de acesso ao banco para uma entidade. Queries + persistência simples. Sem lógica de negócio.

## Estrutura obrigatória

```php
<?php
declare(strict_types=1);
namespace App\<Dominio>\Repository;

use App\<Dominio>\Entity\<Nome>;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class <Nome>Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, <Nome>::class);
    }

    public function salvar(<Nome> $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(<Nome> $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
```

## Regras críticas

**Proibido:**
- Lógica de negócio (cálculos, validações, decisões de fluxo)
- `findAll()` em listagens de usuário — sempre paginar
- Concatenação de string em DQL/SQL — SQL injection:
  ```php
  // ERRADO:
  ->where("u.email = '$email'")

  // CERTO:
  ->where('u.email = :email')->setParameter('email', $email)
  ```
- Orquestração multi-entidade (isso é responsabilidade do UseCase)

**Obrigatório:**
- Filtrar por `tenant` em toda query:
  ```php
  $qb->andWhere('e.tenant = :tenant')
     ->setParameter('tenant', $tenant);
  ```
- Nomes de métodos pelo negócio: `buscarAtivosDoTenant()`, `listarPorCliente()` — nunca `getData()`

## Paginação obrigatória em listagens

```php
use Doctrine\ORM\Tools\Pagination\Paginator;

public function listarPaginado(Tenant $tenant, int $pagina, int $porPagina): Paginator
{
    $qb = $this->createQueryBuilder('e')
        ->andWhere('e.tenant = :tenant')
        ->setParameter('tenant', $tenant)
        ->orderBy('e.criadoEm', 'DESC')
        ->setFirstResult(($pagina - 1) * $porPagina)
        ->setMaxResults($porPagina);

    return new Paginator($qb);
}
```

## DTOs read-only via DQL NEW (evitar N+1)

```php
public function listarResumido(Tenant $tenant): array
{
    return $this->getEntityManager()
        ->createQuery('SELECT NEW App\<Dominio>\DTO\<Nome>ListaItem(e.id, e.nome) FROM App\<Dominio>\Entity\<Nome> e WHERE e.tenant = :tenant')
        ->setParameter('tenant', $tenant)
        ->getResult(); // retorna array de DTOs — não entidades Doctrine
}
```

## Quando usar cada abordagem

| Situação | Abordagem |
|---|---|
| Query dinâmica (filtros opcionais) | `QueryBuilder` |
| Query estática simples | DQL |
| Features PG específicas (CTE, tsvector, GIN) | DBAL raw SQL |
| Listagem para view | DQL `NEW` com DTO |

## Geradores — o repositório nasce junto com a entidade, e nós não usamos o gerador

Não existe `make:repository`: quem cria o repositório é o `make:entity`, que **não usamos** (ver skill
`criar-entity`). Escreva o repositório à mão a partir do esqueleto acima — em especial porque o gerador
nunca colocaria o filtro de tenant, que aqui é obrigatório.

Cuidado redobrado com qualquer código gerado que chame `findAll()` / `findBy()` sem tenant: é a violação
de isolamento mais comum e o `make:crud` a produz por padrão.
