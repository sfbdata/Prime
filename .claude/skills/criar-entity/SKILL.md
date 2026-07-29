---
name: criar-entity
description: "Padrões para criar ou refatorar Entidades Doctrine do jusprime: PK integer, multi-tenant, enums, lifecycle, entidade rica vs anêmica. Carregue ao criar, editar ou revisar arquivos *.php em app/src/<Dominio>/Entity/."
---

# Entity/ — Regras de Entidades Doctrine

## Responsabilidade

Entidade = representação persistida de um conceito de domínio com suas invariantes.

## Estrutura obrigatória

```php
<?php
declare(strict_types=1);
namespace App\<Dominio>\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: <Nome>Repository::class)]
#[ORM\Table(name: '<nome_tabela>')]
class <Nome>  // não use `final` — Doctrine gera proxies via herança
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

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

### PK é `integer` auto-increment, não UUID

Esta skill mandava UUID (`UuidType` + `CustomIdGenerator`) até 29/07/2026, e **nenhuma** das ~40
entidades do projeto jamais fez isso — `symfony/uid` sequer está instalado. Uma entidade nova escrita
pela orientação antiga não compilaria, ou entraria como a única de formato diferente no repositório
inteiro. Corrigido para descrever o que a casa realmente faz.

O que o UUID compraria é id **não-enumerável** em URL. Não é dele que vem a proteção aqui: quem
impede o acesso cruzado é o **guarda de posse** — recurso de outro escritório responde **404, nunca
403**, para não revelar sequer que existe. Um id sequencial atrás desse guarda não vaza nada; um UUID
sem esse guarda vaza tudo. Confundir os dois é o erro comum.

Migrar o projeto para UUID continua sendo uma discussão legítima — mas é **decisão do projeto
inteiro**, tomada de uma vez, não escolha de quem está criando a próxima entidade. Meio a meio é o
pior dos dois mundos. *(Contexto da decisão: `docs/specs/cobranca-simular-acordo-atualizacao-monetaria.md`, nota de PK em §7.1.)*

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

## Ao remover ou renomear propriedade de entidade

Antes de remover propriedade de entidade Doctrine, rodar OS TRÊS greps:

```bash
# 1. Pega chamadas a getters/setters
grep -rn "->get<Prop>\|->set<Prop>" app/src app/tests

# 2. Pega criteria dinâmicos (escapam do mapping estático)
grep -rn "findBy.*'<prop>'\|findOneBy.*'<prop>'" app/src

# 3. Pega DQL com alias
grep -rn "<alias>\.<prop>" app/src
```

Após remover, rodar `doctrine:schema:validate` no container para pegar
relações inversas órfãs (inversedBy, mappedBy) que grep não encontra:

```bash
# só o mapeamento (é o que interessa aqui)
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:validate --skip-sync'

# mapeamento + sincronia com o banco
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:validate'
```

⚠️ O comando faz **duas** checagens independentes e sai com erro se qualquer uma falhar. No dev o bloco
*Database* costuma acusar dessincronia por motivo alheio (migration de outra frente ainda não aplicada) —
isso **não** quer dizer que o mapeamento está errado. Leia qual bloco falhou; use `--skip-sync` para
isolar o mapeamento.

## `make:entity` — não use

Escreva a entidade à mão a partir do esqueleto acima.

O gerador grava em `src/Entity/` — não em `app/src/<Dominio>/Entity/` — e produz uma classe anêmica
sem `tenant`, sem `#[ORM\Table(name:)]` em `snake_case` e sem as invariantes de domínio. Sobra mais
trabalho de correção do que de escrita.

Comandos que **valem** nesta camada: `doctrine:mapping:info` (o que está mapeado de fato),
`doctrine:schema:validate --skip-sync` (acima) e `make:migration` depois de alterar o mapeamento —
este último nunca aceito puro: compare com o ruído de base antes (regra no `CLAUDE.md` da raiz).
