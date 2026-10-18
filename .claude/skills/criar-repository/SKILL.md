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

class <Nome>Repository extends ServiceEntityRepository  // sem `final`: é mockado nos testes de UseCase
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
- **Sem `final`** (exceção à regra geral, como as entidades): o teste unitário do UseCase mocka o repository do
  domínio, e mock de classe final quebra. Os 6 que estendem `ServiceEntityRepository` e já são `final` ficam como estão: não
  padronize o legado. Mock de repository não prova a query: DQL nova se prova em teste de integração
  (`KernelTestCase` + DAMA).

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

## Tamanho e organização de método

- **Meta: ~20 linhas por método.** O CI reprova acima de 40 (`tests/Arquitetura/MetodoLongoArquiteturaTest`,
  contado do `function` ao `}`; docblock e atributos não contam). 40 é o limite, não a meta.
- **Um método, um nível de abstração.** O método público conta a história chamando métodos privados com
  nome do negócio; o detalhe mora no privado.
- **Bloco que precisa de comentário para ser entendido vira método**, e o comentário vira o nome dele.
- **Método legado longo** (está em `tests/Arquitetura/metodos-longos-tolerados.json`): pode crescer no
  máximo 5 linhas sobre o número da lista, no total. O código novo vai para um método privado novo; não
  reescreva o legado sem liberação. Mover para o domínio é trocar a chave no JSON com o mesmo número.
- Um método público por consulta. Filtro reaproveitado vira privado que recebe e devolve o `QueryBuilder` (`aplicarFiltroDeStatus($qb, $status)`), em vez de crescer um `findByFilters` com um `if` por chave.

## Geradores — o repositório nasce junto com a entidade, e nós não usamos o gerador

Não existe `make:repository`: quem cria o repositório é o `make:entity`, que **não usamos** (ver skill
`criar-entity`). Escreva o repositório à mão a partir do esqueleto acima — em especial porque o gerador
nunca colocaria o filtro de tenant, que aqui é obrigatório.

Cuidado redobrado com qualquer código gerado que chame `findAll()` / `findBy()` sem tenant: é a violação
de isolamento mais comum e o `make:crud` a produz por padrão.
