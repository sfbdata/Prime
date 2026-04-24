# Entity/ — Regras de Entidades Doctrine

> Esta pasta é **legado**. Novas entidades vão em `src/<Dominio>/Entity/`.

## Responsabilidade

Entidade = representação persistida de um conceito de domínio com suas invariantes.

## Estrutura obrigatória

```php
<?php
declare(strict_types=1);
namespace App\<Dominio>\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: <Nome>Repository::class)]
#[ORM\Table(name: '<nome_tabela>')]
class <Nome>  // não use `final` — Doctrine gera proxies via herança
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    private ?Uuid $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        // demais propriedades obrigatórias para estado inicial válido
    ) {
    }
}
```

> O `$id` fica fora do construtor porque é gerado pelo Doctrine após persist — essa é a única exceção à regra de constructor property promotion.

## Regras críticas

**Proibido em entidades:**
- `final` na declaração da classe — Doctrine gera proxies por herança e quebraria com `final`
- Injetar services (`LoggerInterface`, `EntityManager`, `HttpClient`, etc.)
- Acessar `EntityManager` ou fazer queries
- Lógica de aplicação (enviar email, chamar API, etc.)
- `#[Assert\...]` nas propriedades da entity — validação vai no DTO

**Obrigatório:**
- Constructor property promotion (PHP 8.0+) — nunca declarar propriedades separadas do construtor
- Campo `tenant` em toda entidade multi-tenant
- Timestamps `createdAt` / `updatedAt` como `\DateTimeImmutable`
- `#[ORM\Column(type: 'datetime_immutable')]` para timestamps (projeto usa `datetime_immutable`; migração para `datetimetz_immutable` é backlog)
- Apenas atributos PHP — nunca annotations docblock

## Naming de banco

- Tabelas: `snake_case` singular (ex.: `cliente`, `processo`, `tenant`)
- Colunas: `snake_case` (ex.: `created_at`, `tenant_id`)
- Palavras reservadas PG (`user`, `group`, `order`): usar aspas — `#[ORM\Table(name: '"user"')]`
- FK: `<entidade>_id` (ex.: `tenant_id`, `cliente_id`)

## Backed Enums em vez de ENUM Postgres

```php
// Certo — PHP enum mapeado como string
enum StatusProcesso: string
{
    case Ativo = 'ativo';
    case Arquivado = 'arquivado';
}

#[ORM\Column(enumType: StatusProcesso::class)]
private StatusProcesso $status = StatusProcesso::Ativo;
```

## Entidade rica vs anêmica

- **Rica:** quando há invariantes reais — construtor valida, métodos de domínio mudam estado
  ```php
  public function arquivar(): void
  {
      if (StatusProcesso::Ativo !== $this->status) {
          throw new \DomainException('Apenas processos ativos podem ser arquivados.');
      }
      $this->status = StatusProcesso::Arquivado;
  }
  ```
- **Anêmica:** aceitável em CRUDs simples sem invariantes relevantes

## Lifecycle callbacks

Apenas para side-effects triviais:
```php
#[ORM\PrePersist]
public function aoInserir(): void
{
    $this->criadoEm = new \DateTimeImmutable();
}
```

Para lógica real de domínio: colete domain events no aggregate e dispare após flush.
