# Checklist de code review para PHP 8.2+ / Symfony 7.4

Este documento é um **checklist completo e prático para code review** de projetos PHP 8.2+, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig e Docker. Foi construído a partir das fontes oficiais do Symfony (`symfony.com/doc/current`, *Best Practices*, *Coding Standards*, *Conventions*), da PHP-FIG, da documentação Doctrine, da Twig, de livros de Matthias Noback (*Object Design Style Guide*, *Advanced Web Application Architecture*), do livro *Symfony: The Fast Track* de Fabien Potencier, e de repositórios de referência como API Platform, Sylius e symfony/demo.

Cada item é classificado como **🔴 OBRIGATÓRIO** (bloqueia PR), **🟡 RECOMENDADO** (best practice consolidada) ou **🟢 OPCIONAL** (trade-off do projeto). Os itens estão organizados em **três áreas principais** conforme solicitado: **código**, **arquitetura** e **qualidade**. Ao final há um checklist rápido de skim, uma lista de ferramentas obrigatórias e a lista de fontes oficiais.

---

## Parte 1 — Código (PSR, naming, estrutura)

### 1.1 PSRs aplicáveis

- **🔴 PSR-1 (Basic Coding Standard)** — arquivos em UTF-8 sem BOM, apenas tags `<?php` ou `<?=`, classes em `StudlyCaps`, constantes em `UPPER_SNAKE_CASE`, métodos em `camelCase`. Arquivos devem ou declarar símbolos, ou ter side-effects — nunca ambos. Ref.: <https://www.php-fig.org/psr/psr-1/>.
- **🔴 PSR-4 (Autoloading)** — `App\` mapeado para `src/` no `composer.json`. FQCN case-sensitive; nome do arquivo = nome da classe (`App\Controller\UserController` → `src/Controller/UserController.php`). Ref.: <https://www.php-fig.org/psr/psr-4/>.
- **🔴 PSR-12 (Extended Coding Style)** — 4 espaços (nunca tabs), LF, soft limit de 120 colunas, `declare(strict_types=1);` imediatamente após `<?php` (se usado), linha em branco após `namespace` e após `use`, visibilidade explícita em todos os membros, abertura de `{` de classes/métodos em nova linha, abertura de `{` de estruturas de controle na mesma linha. Ref.: <https://www.php-fig.org/psr/psr-12/>.
- **🟢 PSR-7 / PSR-15 / PSR-18 (HTTP)** — Symfony usa **HttpFoundation** (sua própria `Request`/`Response`), não PSR-7. Use **`symfony/psr-http-message-bridge`** apenas na fronteira com bibliotecas externas que exigem PSR-7. Middleware PSR-15 **não** é idiomático em Symfony (use `EventSubscriber` no kernel). `symfony/http-client` implementa PSR-18; prefira `HttpClientInterface` internamente. Regra de review: **não misturar** os dois mundos no código de domínio.

### 1.2 Symfony Coding Standard (além do PSR-12) — 🔴 OBRIGATÓRIO

Ref. oficial: <https://symfony.com/doc/current/contributing/code/standards.html>. Automatizado pelo **PHP-CS-Fixer** com ruleset `@Symfony` + `@Symfony:risky`.

- Use **comparação identical** (`===`/`!==`) sempre que não precisar de type juggling.
- **Yoda conditions** em comparações contra constantes/literais: `if (true === $foo)`.
- **Linha em branco antes de `return`**, exceto quando sozinho em bloco.
- `return null;` quando retorna null; `return;` quando a função é `void`.
- **Chaves sempre** em estruturas de controle, mesmo com uma linha.
- **Uma classe por arquivo**; propriedades antes dos métodos; públicos → protected → private; `__construct`/`setUp`/`tearDown` sempre no topo.
- Parênteses sempre ao instanciar: `new Foo()` mesmo sem args.
- Vírgula final em arrays multilinha e em parâmetros promovidos.
- Mensagens de exceção/erro: `sprintf(...)`, maiúscula inicial, ponto final, aspas duplas em torno de termos técnicos (nunca backticks). **Use `get_debug_type($obj)`** em mensagens em vez de `$obj::class`.
- Nunca `else`/`elseif` depois de `if`/`case` que retornam ou lançam.
- Em PHPDoc `@param`/`@return`, **`null` vai no final** da lista de tipos.
- Prefira `?T` em vez de `T|null`.

### 1.3 Convenções oficiais de nomenclatura

| Elemento | Convenção | Exemplo |
|---|---|---|
| Classes, interfaces, traits, enums | `UpperCamelCase` | `BlogPostRepository` |
| Classes abstratas | prefixo `Abstract` (exceto `*TestCase`) | `AbstractController` |
| Interfaces | sufixo `Interface` | `UserRepositoryInterface` |
| Traits | sufixo `Trait` | `TimestampableTrait` |
| Exceções | sufixo `Exception` | `UserNotFoundException` |
| Métodos, argumentos, variáveis, propriedades | `camelCase` | `$acceptableContentTypes` |
| Constantes de classe | `SCREAMING_SNAKE_CASE` | `MAX_TITLE_LENGTH` |
| Cases de Enum | `UpperCamelCase` | `PostStatus::Draft` |
| Parâmetros de config, variáveis Twig, nomes de rotas | `snake_case` | `framework.csrf_protection`, `app_blog_show` |
| Parâmetros do container | `snake_case` com prefixo `app.` | `app.contents_dir` |
| Arquivos PHP | `UpperCamelCase.php` | `EnvVarProcessor.php` |
| Templates Twig e assets web | `snake_case.html.twig` | `section_layout.html.twig` |
| Partials Twig | prefixo `_` | `_user_metadata.html.twig` |
| PHPDoc/cast | `bool`, `int`, `float` | (não `boolean`/`integer`/`double`) |
| Atributos PHP de serviço | prefixo `As` | `#[AsCommand]`, `#[AsEventListener]` |
| Atributos PHP de arg de controller | prefixo `Map` | `#[MapRequestPayload]`, `#[MapEntity]` |

**Sufixos obrigatórios de classes Symfony**: `Controller`, `Repository`, `Command`, `Listener`, `Voter`, `Form`, `Type`, `EventSubscriber`, `Normalizer`, `Denormalizer`, `Extension`, `Compiler`. (`Subscriber` e `EventSubscriber` são a mesma coisa no contexto Symfony — use apenas `EventSubscriber`.)

**Naming de métodos** (Ref.: *Conventions*) — para a relação "principal" do objeto use `get/set/has/all/replace/remove/clear/isEmpty/add/count`. Para relações adicionais, sufixo com o nome (`getXXX()`, `setXXX()`, `removeXXX()`). **`setXXX()` pode adicionar** elementos; **`replaceXXX()` deve lançar** exceção para chave desconhecida.

**Nomes de rotas** — `snake_case`, padrão `app_<entidade>_<acao>` (ex.: `app_blog_show`). O auto-naming `app_<controller>_<método>` já existe desde versões anteriores; o que o Symfony 6.4 introduziu foi a criação de **route aliases baseadas no FQCN** (`App\Controller\BlogController::index`) que coexistem com o nome principal — não o substituem.

**Services** — nome = FQCN da classe. Múltiplos serviços da mesma classe: FQCN para o principal; outros em `lowercase_underscored` com agrupamento opcional por pontos.

### 1.4 Estrutura de diretórios do Symfony 7

```
your_project/
├── assets/
├── bin/console
├── config/
│   ├── packages/
│   ├── routes/
│   └── services.yaml
├── migrations/
├── public/index.php
├── src/
│   ├── Kernel.php
│   ├── Command/
│   ├── Controller/
│   ├── DataFixtures/
│   ├── Entity/
│   ├── EventSubscriber/
│   ├── Form/
│   ├── Repository/
│   ├── Security/
│   └── Twig/
├── templates/
├── tests/ (espelhando src/)
├── translations/
└── var/{cache,log}/
```

Regra Symfony Best Practices: **não crie bundles para lógica de aplicação**. Use namespaces sob `App\`. Bundles apenas para código reutilizável que seria publicado em outro projeto.

### 1.5 PHP 8.2+ — recursos que devem ser usados

- **🔴 Type hints** em 100% de argumentos e retornos; use `void`, `never`, `self`, `static` quando apropriados.
- **🟡 `declare(strict_types=1);`** — o core Symfony não força, mas é best practice em projetos novos. Defina uma política única para o repositório e enforce via PHP-CS-Fixer (`declare_strict_types => true`).
- **🟡 Constructor property promotion** — padrão recomendado para DI. Cada parâmetro em sua linha, com trailing comma.
- **🟡 `readonly` properties** (PHP 8.1) **e `readonly class`** (PHP 8.2, torna todas as propriedades implicitamente `readonly`) — obrigatório em Value Objects, DTOs, Events, Messages, Commands. **Incompatível com entities Doctrine mutáveis** — não use em entidades.
- **🟡 Backed enums** para valores persistidos: `enum PostStatus: string { case Draft = 'draft'; }` e `#[ORM\Column(enumType: PostStatus::class)]`.
- **🟡 Named arguments** em atributos: `#[Route(path: '/blog', name: 'app_blog_index')]`.
- **🟡 First-class callable syntax**: `$fn = $this->method(...);`.
- **🟡 Intersection/DNF types** quando combinando interfaces.
- **🟢 `#[\Override]` attribute** (PHP 8.3+) para marcar métodos sobrescritos — pega erros de refactor.

### 1.6 Atributos PHP (nunca mais anotações)

Symfony 7 **removeu totalmente** o suporte a anotações docblock. Use exclusivamente atributos PHP:

- **HTTP/Controller**: `#[Route]`, `#[AsController]`, `#[Cache]`, `#[MapEntity]`, `#[MapQueryString]`, `#[MapQueryParameter]`, `#[MapRequestPayload]`, `#[MapUploadedFile]`, `#[CurrentUser]`.
- **Container**: `#[Autowire]`, `#[AutowireIterator]`, `#[AutowireLocator]`, `#[AutowireDecorated]`, `#[Target]`, `#[When]`, `#[AsAlias]`, `#[AsDecorator]`, `#[Autoconfigure]`, `#[Lazy]`.
- **Console**: `#[AsCommand]`, `#[Option]`, `#[MapInput]`.
- **Eventos**: `#[AsEventListener]` (suporta union types em 7.4).
- **Messenger**: `#[AsMessageHandler]`, `#[AsMessage]`.
- **Security**: `#[IsGranted]` (com opção `methods` em 7.4), `#[IsCsrfTokenValid]`.
- **Doctrine**: `#[ORM\Entity]`, `#[ORM\Column]`, `#[ORM\Index]`, `#[ORM\Embeddable]`, etc.
- **Validator**: `#[Assert\NotBlank]`, `#[Assert\Email]`, `#[Assert\Valid]`.
- **Serializer**: `#[Groups]`, `#[SerializedName]`, `#[Context]`, `#[Ignore]`.

Ref.: <https://symfony.com/doc/current/reference/attributes.html>.

### 1.7 Exemplos de referência

**Controller fino (Symfony 7.4 / PHP 8.2+)**:

```php
<?php
declare(strict_types=1);
namespace App\Controller;

use App\Dto\CreateBlogPostDto;
use App\Entity\BlogPost;
use App\Repository\BlogPostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BlogController extends AbstractController
{
    public function __construct(
        private readonly BlogPostRepository $posts,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/blog', name: 'app_blog_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('blog/index.html.twig', [
            'posts' => $this->posts->findLatest(),
        ]);
    }

    #[Route('/blog/{slug}', name: 'app_blog_show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(BlogPost $post): Response
    {
        return $this->render('blog/show.html.twig', ['post' => $post]);
    }

    #[Route('/blog', name: 'app_blog_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateBlogPostDto $dto): JsonResponse
    {
        $this->commandBus->dispatch(new CreateBlogPostCommand($dto));
        return $this->json(['status' => 'accepted'], 202);
    }
}
```

**Entity com atributos e enum backed**:

```php
#[ORM\Entity(repositoryClass: BlogPostRepository::class)]
#[ORM\Table(name: 'blog_posts')]
class BlogPost  // não use `final` em entidades — Doctrine gera proxies via herança
{
    public const int MAX_TITLE_LENGTH = 255;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
    #[Assert\NotBlank, Assert\Length(max: self::MAX_TITLE_LENGTH)]
    private string $title;

    #[ORM\Column(enumType: PostStatus::class)]
    private PostStatus $status = PostStatus::Draft;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function publish(): void
    {
        if (PostStatus::Draft !== $this->status) {
            throw new \DomainException('Only drafts can be published.');
        }
        $this->status = PostStatus::Published;
    }
}
```

---

## Parte 2 — Arquitetura (DDD, camadas, patterns)

### 2.1 Separação de camadas

- **🔴 Controllers thin**. Regra oficial: "glue-code only". Heurística dos "5-10-20" (≤5 variáveis, ≤10 actions por classe, ≤20 linhas por action). **Proibido no controller**: acesso direto ao `EntityManager::persist()/flush()`, transformações complexas de dados, loops de regras de negócio. **Correto**: receber DTOs validados via `#[MapRequestPayload]`, delegar a service/command handler, retornar `Response`.
- **🔴 Services fat**. Application services orquestram use-cases, são imutáveis após construção, recebem dependências pelo construtor, e expõem um pequeno conjunto de métodos de alto nível. Marque-os `final` por padrão.
- **🔴 Repositories apenas para queries**. Nunca contêm lógica de negócio. Podem ter açúcar `save()`/`remove()`, mas não orquestração multi-entidade.
- **🟡 Entities ricas (DDD)** quando há invariantes reais; anêmicas aceitáveis em CRUDs simples (ver §2.7).
- **🟢 Hexagonal/Clean (Domain/Application/Infrastructure)** — só vale a pena em domínios complexos, times ≥ 5 devs, projetos long-lived ou com expectativa de trocar persistência. Para CRUDs simples, **a estrutura flat default do Symfony é oficialmente recomendada** pelo Best Practices.

### 2.2 Dependency injection — best practices

- **🔴 Constructor injection apenas.** Proibido: setter injection, injeção do `ContainerInterface`, `ContainerAwareTrait` (legado). Exceção documentada: service subscribers raros.
- **🔴 `_defaults` com `autowire: true`, `autoconfigure: true`, `public: false`** em `services.yaml`. Services privados forçam DI adequada e bloqueiam `$container->get()` em runtime.
- **🟡 Dependa de interfaces** quando cruzar camadas (Domain ↔ Infrastructure). Em app flat é aceitável depender da classe concreta do service.
- **🟡 Atributos de autowiring** em vez de binding YAML quando pontuais:

```php
public function __construct(
    #[Autowire(service: 'monolog.logger.audit')] private LoggerInterface $audit,
    #[Autowire(param: 'app.uploads_dir')] private string $uploadsDir,
    #[AutowireIterator('app.payment_gateway')] private iterable $gateways,
    #[Target('github.api')] private HttpClientInterface $github,
) {}
```

- **🟡 YAML `bind`** em `_defaults` para injeção global de params/iteradores tagueados.
- **🔴 Services `final`** por padrão (evita herança acidental que quebra DI).

### 2.3 Repository pattern com Doctrine ORM 3.x

- **🔴 Herdar `ServiceEntityRepository`** e associar via `#[ORM\Entity(repositoryClass: ...)]`.
- **🔴 Nunca `findAll()` em endpoints de listagem**. Paginar sempre com `Doctrine\ORM\Tools\Pagination\Paginator` (sabe lidar com joins), `Pagerfanta` ou `KnpPaginatorBundle`. Exceção: tabelas de lookup garantidamente pequenas (moedas, países).
- **🔴 SQL injection prevention**: sempre `:param` binding, nunca concatenação:

```php
// Errado:  ->where("u.email = '$email'")
// Certo:
$qb->where('u.email = :email')->setParameter('email', $email);
```

- **🟡 Nomear métodos pelo negócio**: `findActiveByCategory`, `findPublishedSince`, não `getData`/`query`.
- **🟡 DQL NEW para DTOs read-only** (ORM 3.3+ suporta nested DTOs e short named args):

```php
$em->createQuery('SELECT NEW App\Dto\ProductListItem(p.id, p.name, p.price) FROM App\Entity\Product p');
```

- **🟡 QueryBuilder para queries dinâmicas**; DQL para queries estáticas; raw SQL via DBAL **apenas** quando DQL/QB não suportam (recursive CTE, tsvector, features PG específicas).

### 2.4 Breaking changes críticos da Doctrine ORM 3.x (verificar em PR de upgrade)

- **YAML mapping removido** — migrar para attributes (ou XML).
- **`EntityManager::merge()` removido**.
- **Annotations** exigem pacote opcional; default é attributes.
- **DB-generated UUIDs deprecados** — use `doctrine.uuid_generator` com `#[ORM\CustomIdGenerator]`.
- **Entity namespace aliases removidos** — use FQCN `::class`.
- **Doctrine cache agora é PSR-6** (`CacheItemPoolInterface`), não mais `Doctrine\Common\Cache`.
- **`EntityRepository::count()` nativo** — remova `implements Countable`.
- **`ClassMetadataInfo` consolidado em `ClassMetadata`**.

Ref.: <https://github.com/doctrine/orm/blob/3.6.x/UPGRADE.md>.

### 2.5 Value Objects, DTOs, Form Types

| | Value Object | DTO |
|---|---|---|
| Imutável | sempre | sim (best practice) |
| Comportamento | sim (`Money::add()`, `Email::domain()`) | não — só dados |
| Identidade | por valor | sem identidade |
| Valida invariantes | no construtor (throw) | via Symfony Validator |
| Uso | dentro do domínio | fronteira HTTP/CLI/Messenger |

- **🔴 VOs `readonly`** (properties: PHP 8.1; `readonly class`: PHP 8.2) — imutabilidade garantida pelo runtime.
- **🟡 Embeddables** para VOs multi-campo persistidos: `#[ORM\Embedded(class: Money::class)]`.
- **🟡 Forms Symfony** para web tradicional (CSRF + validação + edição). **`#[MapRequestPayload]` com DTOs** para APIs JSON.
- **🔴 Validação no objeto subjacente** (entity ou DTO), **não no Form** — permite reuso em API/CLI.
- **🔴 Uma única action** renderiza e processa o form.
- **Botões no template**, não na classe Form (exceto multi-submit).

**Action controller idiomático com DTO**:

```php
#[Route('/orders', methods: ['POST'])]
final class CreateOrderAction extends AbstractController
{
    public function __construct(private readonly MessageBusInterface $bus) {}

    public function __invoke(#[MapRequestPayload] CreateOrderDto $dto): JsonResponse
    {
        $id = $this->bus->dispatch(new CreateOrderCommand($dto))
            ->last(HandledStamp::class)->getResult();
        return $this->json(['id' => (string) $id], 201);
    }
}
```

### 2.6 Event Dispatcher, Messenger, Workflow

- **🟡 Event Listener** (atributo `#[AsEventListener]`) para 1–2 eventos simples. **Event Subscriber** (implementando `EventSubscriberInterface` com `getSubscribedEvents()`) quando a classe agrupa múltiplos eventos relacionados.
- **🟡 Symfony Messenger com múltiplos buses** (CQRS leve). Padrão consolidado:

```yaml
framework:
  messenger:
    default_bus: command.bus
    buses:
      command.bus:
        middleware: [validation, doctrine_transaction]
      query.bus:
        middleware: [validation]
      event.bus:
        default_middleware: { enabled: true, allow_no_handlers: true }
        middleware: [validation]
```

Autowire por nome de variável: `MessageBusInterface $queryBus`. Restrinja handler a bus específico via tag `messenger.message_handler: { bus: command.bus }`.

- **🔴 Messages imutáveis** (`final readonly class`).
- **🟡 `DispatchAfterCurrentBusStamp`** para disparar eventos de domínio apenas após commit transacional.
- **🔴 Workers em produção** com `--time-limit` + `--memory-limit`, supervisionados por systemd/supervisord.
- **🟡 Workflow Component** — prefira **state machine** (`type: state_machine`) para a maioria dos agregados com lifecycle (pedido, fatura); **workflow** quando múltiplos places simultâneos.

### 2.7 Entities — anêmicas vs ricas

- **🟡 Entidade rica** quando há invariantes reais. Princípio (Noback, *Object Design Style Guide*): "Objects should be constructed in one go" — completos, válidos, consistentes ao fim do construtor.
- **🟡 Named constructor** para criação (`User::register()`, `Order::place()`); marque `__construct` privado para bloquear instanciação direta.
- **🟡 Setters privados + métodos de domínio** (`$order->markAsPaid($paidAt)` em vez de `setStatus('paid')`). O `make:entity` gera getters/setters públicos — **refaça à mão** para entities de domínio.
- **🔴 Nunca injetar services em entities**; nunca acessar `EntityManager`, `HttpClient`, `LoggerInterface`.
- **🔴 Lifecycle callbacks apenas para side-effects triviais** (`PrePersist` preenchendo `updatedAt`). Para lógica de domínio, colete eventos no aggregate e dispache após flush.

Exemplo de entity rica:

```php
#[ORM\Entity]
class User  // não use `final` em entidades — Doctrine gera proxies via herança
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    private Uuid $id;
    #[ORM\Column] private string $email;
    #[ORM\Column] private string $hashedPassword;
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $registeredAt;

    private function __construct() {}

    public static function register(Uuid $id, EmailAddress $email, HashedPassword $pwd, ClockInterface $clock): self
    {
        $u = new self();
        $u->id = $id;
        $u->email = $email->value;
        $u->hashedPassword = $pwd->value;
        $u->registeredAt = $clock->now();
        return $u;
    }

    public function changeEmail(EmailAddress $new): void { /* valida invariantes */ }
}
```

### 2.8 Doctrine Migrations 3.x — convenções

- **🔴 `up()` e `down()` sempre implementados**. `down()` deve reverter `up()`.
- **🔴 Nunca editar migration já aplicada em produção** — crie nova migration com a correção. Migrations são append-only.
- **🔴 Revisar o SQL gerado** por `make:migration` — o diff não é 100% confiável (especialmente tipos PG custom, enums, índices GIN).
- **🟡 Separe schema migrations (DDL) de data migrations (DML)**. Se volumosas, use command/job em background após deploy.
- **🔴 Nunca use Symfony services / EntityManager em migration** — SQL puro via `$this->addSql()`.
- **🟡 Para NOT NULL em tabela com dados**: 3 etapas (add nullable → populate → alter to not null).
- **🟡 `all_or_nothing: true`** na config (transação por execução).
- **🟡 Blue-green deploy** exige migrations compatíveis com versão anterior do código (evite DROP de colunas em uso).

### 2.9 PostgreSQL 15 — uso correto com Doctrine

| PostgreSQL | Doctrine | Regra |
|---|---|---|
| **JSONB** (nunca JSON) | `Types::JSON` + `options: ['jsonb' => true]` | 🔴 obrigatório em PG |
| **UUID nativo** | `UuidType::NAME` (symfony/uid) | 🟡 use para PK |
| **arrays** (`text[]`, `int[]`) | `martin-georgiev/postgresql-for-doctrine` ou JSONB | 🟢 prefira JSONB se pesquisável |
| **tsvector** (full-text) | generated column + índice GIN | 🟡 |
| **ENUM Postgres** | evitar — use `Types::STRING` + PHP enum | 🔴 PHP backed enum é superior |
| **timestamp com tz** | `timestamptz` (não `timestamp`) | 🔴 preserva timezone |

**UUID v7 (Symfony 7.4+)** — time-ordered, elimina fragmentação de índice B-Tree:

```yaml
framework:
  uid:
    default_uuid_version: 7
    time_based_uuid_version: 7
```

```php
#[ORM\Id, ORM\Column(type: UuidType::NAME, unique: true)]
#[ORM\GeneratedValue(strategy: 'CUSTOM')]
#[ORM\CustomIdGenerator('doctrine.uuid_generator')]
private Uuid $id;
```

**Índices GIN para JSONB** — obrigatórios quando há filtros sobre JSONB. Adicione em migration:

```sql
CREATE INDEX idx_product_metadata ON product USING GIN (metadata jsonb_path_ops);
```

**Soft delete** com `deleted_at TIMESTAMPTZ NULL` + **partial unique index** para unicidade entre ativos:

```sql
CREATE UNIQUE INDEX uniq_user_email_active ON "user"(email) WHERE deleted_at IS NULL;
```

### 2.10 Nomenclatura em PostgreSQL

- **🔴 `snake_case`** para tabelas e colunas. Ative `naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware`.
- **🔴 Foreign keys**: `user_id`, `category_id` (singular_id).
- **🔴 Timestamps**: `created_at`, `updated_at`, `deleted_at`.
- **🟡 Tabelas no singular** (default Doctrine) — a comunidade diverge; escolha uma convenção e documente no README.
- **🟡 Índices**: prefixos `idx_` (simples), `uniq_` (unique), `fk_`, `pk_`. Doctrine gera `IDX_xxxx`/`UNIQ_xxxx` automaticamente; nomes manuais obrigatórios em GIN/partial.
- **🟡 Evite reserved words** (`user`, `group`, `order`) — prefira `users`/`app_user`/`orders` ou quote-escape no `#[ORM\Table(name: '"user"')]`.

### 2.11 Action classes (__invoke) vs controllers tradicionais

- **🟡 Single Action Controllers** (classe com `__invoke`) — preferidos em APIs e arquiteturas ADR. Uma responsabilidade por arquivo, construtor enxuto, nome = intenção (`CreateProductAction`).
- **🟢 Controllers tradicionais multi-action** — aceitáveis em CRUDs admin onde 5 actions relacionadas compartilham helpers.

### 2.12 Separação Domain/Application/Infrastructure (quando adotar)

Estrutura típica em projetos DDD (ref.: CodelyTV/php-ddd-example):

```
src/<BoundedContext>/<Module>/
├── Domain/           (Entity, VOs, Events, Repository INTERFACE)
├── Application/      (Command, Query, Handler — use cases)
└── Infrastructure/   (Doctrine Repository, Controllers, HTTP, filas)
```

**Regras de dependência** (quando adotado — 🔴 obrigatórias):

- Domain não depende de nada (nem Symfony, nem Doctrine).
- Application depende apenas de Domain.
- Infrastructure depende de Domain e Application.
- **Use `qossmic/deptrac`** para enforcement automático no CI.

**Quando NÃO adotar**: CRUDs simples, MVPs, equipe pequena, apps descartáveis — o Best Practices oficial recomenda a estrutura flat default.

---

## Parte 3 — Qualidade (lint, testes, CI, segurança, performance)

### 3.1 Análise estática

**PHPStan** — 🔴 obrigatório. Níveis vão de 0 a 10 (`max`). **Target Symfony: iniciar em 6, convergir para 8**; níveis 9–10 exigem zero `mixed`. Config mínima:

```neon
# phpstan.neon
includes:
  - phar://phpstan.phar/conf/bleedingEdge.neon
  - vendor/phpstan/phpstan-strict-rules/rules.neon
  - vendor/phpstan/phpstan-symfony/extension.neon
  - vendor/phpstan/phpstan-doctrine/extension.neon
  - vendor/phpstan/phpstan-phpunit/extension.neon
parameters:
  level: 8
  paths: [src, tests]
  checkImplicitMixed: true
  checkUninitializedProperties: true
  symfony:
    containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
```

Use `phpstan/extension-installer` para auto-registrar extensões e **baseline** (`--generate-baseline`) em projetos legados para adoção incremental.

**Psalm** — 🟢 opcional. `errorLevel` vai de 1 (strictest) a 8 (lenient); **recomendado 3** em produção. A comunidade Symfony majoritária roda **apenas PHPStan** (melhor integração com Symfony/Doctrine). Psalm tem vantagem em taint analysis. Rodar ambos dobra o custo de CI — escolha um.

**PHP-CS-Fixer** — 🔴 obrigatório com ruleset Symfony:

```php
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP82Migration' => true,
        '@PHP82Migration:risky' => true,
        '@PHPUnit100Migration:risky' => true,
        'declare_strict_types' => true,
        'global_namespace_import' => ['import_classes' => true],
    ])
    ->setFinder(PhpCsFixer\Finder::create()->in(['src', 'tests']));
```

CI roda `vendor/bin/php-cs-fixer fix --dry-run --diff`.

**Rector** — 🟡 recomendado (rodar local + CI advisory, **nunca aplicar automático em PR sem review**):

```php
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withPhpSets(php82: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true)
    ->withComposerBased(symfony: true, doctrine: true, phpunit: true)
    ->withSymfonyContainerXml(__DIR__.'/var/cache/dev/App_KernelDevDebugContainer.xml');
```

Sets úteis: `SymfonySetList::SYMFONY_74`, `DoctrineSetList::DOCTRINE_CODE_QUALITY`, `DoctrineSetList::DOCTRINE_DBAL_40`, `PHPUnitSetList::PHPUNIT_100`.

**Symfony Insight** — 🟢 opcional (serviço externo SensioLabs). Útil em consultorias/due diligence, mas PHPStan + PHP-CS-Fixer + Rector cobrem a maior parte.

### 3.2 Testes com PHPUnit 10/11

| Tipo | Base class | Boot Kernel | DB | Uso |
|---|---|---|---|---|
| Unit | `TestCase` | ❌ | ❌ | VOs, services puros com mocks |
| Integration | `KernelTestCase` | ✅ | opcional | Services com DI, Doctrine, listeners |
| Functional | `WebTestCase` | ✅ | ✅ | Controllers via `KernelBrowser` |
| E2E | `PantherTestCase` | ✅ | ✅ | Browser real com JS |

- **🔴 Cobertura**: foque em **cenários críticos** (payments, auth, domain core em 100%; alvo pragmático 80% global). Symfony Best Practices orienta qualidade sobre quantidade.
- **🔴 Mocks**: PHPUnit native (`createMock`, `createStub`, `createPartialMock`). Prophecy saiu do core; evite em projetos novos. **Nunca mockar o que não é seu** — crie interfaces próprias e mocke essas; nunca mocke `EntityManager` diretamente.
- **🔴 PHPUnit attributes** (10+): `#[DataProvider]`, `#[CoversClass]`, `#[Group]`, `#[TestDox]` — substituem annotations.
- **🟡 Smoke tests** de todas as URLs públicas via `WebTestCase` + data provider. URLs **hardcoded** nos testes funcionais (não gerar via router).
- **🔴 `KernelTestCase` quando só precisa do container**; **`WebTestCase` quando precisa do `KernelBrowser`**. Em ambos, `static::getContainer()` dá acesso a serviços privados.
- **🔴 DAMA/DoctrineTestBundle** — cada teste em transação com rollback automático. Isolação + performance em uma linha:

```xml
<extensions>
  <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```

Atenção: DDL (ALTER/DROP TABLE) em PG causa commit implícito e quebra o rollback — isole DDL em grupo separado.

- **🟡 Password hasher acelerado em test env**:

```yaml
when@test:
  security:
    password_hashers:
      App\Entity\User: { algorithm: auto, cost: 4, time_cost: 3, memory_cost: 10 }
```

### 3.3 Fixtures

- **DoctrineFixturesBundle** — 🟢 básico, suficiente para seeds simples mas fica verboso.
- **Foundry (`zenstruck/foundry`)** — 🟡 **padrão de facto atual**. Em v2+ use `PersistentProxyObjectFactory` (proxy com auto-refresh) ou `PersistentObjectFactory` (entidade direta):

```php
final class PostFactory extends PersistentProxyObjectFactory
{
    public static function class(): string { return Post::class; }
    protected function defaults(): array
    {
        return ['title' => self::faker()->sentence(), 'slug' => self::faker()->slug()];
    }
    public function published(): static { return $this->with(['publishedAt' => new \DateTimeImmutable()]); }
}
// uso em teste
$post = PostFactory::createOne(['slug' => 'post-a']);
PostFactory::createMany(10);
```

Integre com DAMA via traits `Factories` + `ResetDatabase`.

- **Alice (`nelmio/alice`)** — 🟢 legado, YAML declarativo. Mantenha em projetos legados; prefira Foundry em projetos novos.

### 3.4 Docker

- **🔴 Multi-stage build** (stages `vendor`/`builder`/`runtime`) — reduz imagem final drasticamente.
- **🔴 `USER` não-root** em runtime de produção.
- **🔴 `.dockerignore`** excluindo `vendor/`, `var/`, `node_modules/`, `.git/`, `.env.local`, `.env.*.local`, `tests/`.
- **🔴 Layer caching**: copiar `composer.json composer.lock symfony.lock` **antes** do código.
- **🔴 Pinear versões** (`FROM php:8.2.15-fpm-alpine3.19`) — nunca `latest`.
- **🔴 `HEALTHCHECK`** obrigatório para orquestradores.
- **🟡 Base image**: `php:8.2-fpm-alpine` para imagens menores; `php:8.2-fpm-bookworm-slim` para compatibilidade máxima (ICU/intl).
- **🔴 Secrets nunca em layers** — use BuildKit secrets ou env vars do orquestrador.

Dockerfile de referência:

```dockerfile
# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.2

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM php:${PHP_VERSION}-fpm-alpine AS app
RUN apk add --no-cache icu-dev postgresql-dev libzip-dev \
 && docker-php-ext-install intl pdo_pgsql zip opcache
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions apcu

RUN addgroup -S app && adduser -S -G app app
WORKDIR /var/www/html
COPY --chown=app:app --from=vendor /app/vendor ./vendor
COPY --chown=app:app . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php bin/console cache:warmup --env=prod

USER app
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=3s CMD php-fpm-healthcheck || exit 1
CMD ["php-fpm"]
```

`docker-compose.yml` de dev com `postgres:15-alpine` + `healthcheck pg_isready`, `redis:7-alpine`, mailpit. Use `docker-compose.override.yml` (auto-carregado) para ferramentas dev-only como Xdebug.

### 3.5 CI/CD — checks obrigatórios em ordem

1. `composer validate --strict --no-check-publish`
2. `composer install --prefer-dist --no-progress`
3. `composer audit` (falha em CVE — 🔴 obrigatório)
4. `vendor/bin/php-cs-fixer fix --dry-run --diff`
5. `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
6. `php bin/console lint:yaml config --parse-tags`
7. `php bin/console lint:twig templates`
8. `php bin/console lint:container`
9. `php bin/console doctrine:schema:validate --skip-sync`
10. `vendor/bin/phpunit --coverage-clover=coverage.xml`
11. `vendor/bin/rector process --dry-run` (advisory)

GitHub Actions de referência com `shivammathur/setup-php@v2`, cache de `~/.composer/cache`, serviço `postgres:15-alpine` com healthcheck. **Branch protection**: require status checks, 1+ reviewer, up-to-date branch, dismiss stale reviews.

### 3.6 Segurança

- **🔴 CSRF** ativo por default em Symfony Forms. Em forms manuais: `{{ csrf_token('auth') }}` + `isCsrfTokenValid('auth', $token)`. Firewall de login: `enable_csrf: true` + `CsrfTokenBadge` no authenticator.
- **🔴 SQL injection** — sempre `:param` binding em DQL/QueryBuilder. Raw SQL via `$conn->executeQuery($sql, ['id' => $id], ['id' => ParameterType::INTEGER])`.
- **🔴 XSS no Twig** — autoescape HTML ativo por default. Use `|e('js')`, `|e('css')`, `|e('url')`, `|e('html_attr')` conforme contexto. **`|raw` apenas em dados comprovadamente seguros**. Para injetar dados em JS: `json_encode` em data-attributes, nunca `|raw`. Blocos JS: `{% autoescape 'js' %}...{% endautoescape %}`.
- **🟡 Headers de segurança via `nelmio/security-bundle`** — CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Force HTTPS.
- **🔴 Secrets management** via `symfony/secrets` vault. Commitar `*.encrypt.public.php` + `*.list.php`; **nunca** commitar `*.decrypt.private.php`. Deploy: env var `SYMFONY_DECRYPTION_SECRET` ou copiar `prod.decrypt.private.php` via CI secret. Alternativa: real env vars do orquestrador (K8s secrets, HashiCorp Vault). Hierarquia: env vars reais > `.env.{env}.local` > vault > `.env.{env}` > `.env.local` > `.env`.
- **🔴 Password hashers `algorithm: auto`** + `migrate_from: [bcrypt, argon2i, argon2id]` para auto-rehash em login.
- **🔴 `remember_me` com signed tokens** (default Symfony 5.4+) — não use legacy.
- **🔴 Autorização via Voters** + `#[IsGranted]`/`denyAccessUnlessGranted()`. **Proibido `in_array('ROLE_X', $user->getRoles())`** — não respeita hierarquia. ACL (legado) deve ser evitada em projetos novos.
- **🔴 Rate limiter** (`symfony/rate-limiter`) em login e APIs públicas. Firewall: `login_throttling: max_attempts: 5`.
- **🟡 CORS via `nelmio/cors-bundle`** se API.
- **🔴 `composer audit` em CI** — substitui `symfony/security-checker` (arquivado). Opcional: `roave/security-advisories` como dev dep (conflict com pacotes vulneráveis).
- **🔴 `#[\SensitiveParameter]`** em parâmetros PHP com secrets (evita em stack traces).
- **🟡 Firewall único** (Symfony Best Practices) — salvo caso legítimo (form + API).

### 3.7 Performance

- **🔴 OPcache em prod**: `opcache.enable=1`, `opcache.memory_consumption=256`, `opcache.max_accelerated_files=20000`, `opcache.validate_timestamps=0`, `opcache.preload=/var/www/html/config/preload.php`. `cache:warmup --env=prod` em toda deploy + reset do OPcache.
- **🔴 Build de produção**: `composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts --prefer-dist`.
- **🔴 Doctrine cache em prod** (PSR-6 no ORM 3.x): metadata + query em APCu/PhpFilesAdapter (local, rápido); result em Redis (compartilhado):

```yaml
doctrine:
  orm:
    metadata_cache_driver: { type: pool, pool: doctrine.system_cache_pool }
    query_cache_driver:    { type: pool, pool: doctrine.system_cache_pool }
    result_cache_driver:   { type: pool, pool: doctrine.result_cache_pool }
```

- **🔴 Detectar N+1** no Profiler (aba Doctrine mostra "Duplicate queries"). Resolver com fetch join no DQL (`SELECT u, p FROM User u LEFT JOIN u.posts p` + `addSelect('p')`) ou `EXTRA_LAZY` em coleções grandes onde `count()` não deve hidratar. `partial` hydration foi descontinuado no ORM 3.x — prefira DTOs com DQL `NEW`.
- **🔴 Symfony Cache** com `TagAwareAdapter` (Redis ou Filesystem) para invalidation seletiva por tags.
- **🔴 Profiler desabilitado em prod**: `framework.profiler.enabled: false`; `APP_DEBUG=0`.
- **🟡 Revisar queries lentas** com `pg_stat_statements`, `EXPLAIN (ANALYZE, BUFFERS)` — adicionar partial/GIN indexes onde necessário.

### 3.8 Logs (Monolog)

- **🔴 Channels** — default (`app`, `doctrine`, `security`, `request`, `event`); customs via `monolog.channels: ['audit', 'payment']`. Injete por nome de parâmetro: `Psr\Log\LoggerInterface $auditLogger`.
- **🔴 Níveis PSR-3**: DEBUG → INFO → NOTICE → WARNING → ERROR → CRITICAL → ALERT → EMERGENCY.
- **🟡 Em containers de prod**: `stream` para `php://stderr` (coletado pelo orquestrador), `formatter: monolog.formatter.json`.
- **🟡 `fingers_crossed` em prod** — buffera tudo até erro, libera contexto completo:

```yaml
when@prod:
  monolog:
    handlers:
      main:
        type: fingers_crossed
        action_level: error
        handler: nested
        excluded_http_codes: [404, 405]
        buffer_size: 50
      nested: { type: stream, path: php://stderr, level: debug, formatter: monolog.formatter.json }
      deprecation: { type: stream, path: php://stderr, channels: [deprecation] }
```

- **🟡 Processors úteis**: `PsrLogMessageProcessor`, `WebProcessor` (URL/IP/method), `UidProcessor` (request correlation).
- **🔴 Segurança**: nunca logar passwords, tokens, PII sem redaction, números de cartão. Use `#[\SensitiveParameter]`.

### 3.9 Configuração por ambiente

Hierarquia de `.env` (**última vence**):

1. `.env` — defaults, **commitado**
2. `.env.local` — overrides locais, **nunca commitado**
3. `.env.{env}` — defaults por env, commitado
4. `.env.{env}.local` — overrides por env, **nunca commitado**
5. Secrets vault (decrypted)
6. Real env vars do SO (sempre vencem)

- **🟡 Produção**: `composer dump-env prod` gera `.env.local.php` pré-compilado.
- **🔴 `APP_SECRET`**: 32+ chars aleatórios, no vault, rotacionável.
- **🟡 `#[When(env: 'prod')]`** em classes de serviço específicas de ambiente; `when@{env}:` em YAML.

---

## Checklist rápido (skim durante review)

| Área | Item | Nível |
|---|---|---|
| Estilo | PHP-CS-Fixer clean (`--dry-run`) | 🔴 |
| Estilo | `declare(strict_types=1);` conforme política | 🔴 |
| Estilo | PSR-12 + Symfony CS (Yoda, identical, linha em branco antes de `return`) | 🔴 |
| Naming | Sufixos corretos, `snake_case` em rotas/templates/params, `camelCase` em código PHP | 🔴 |
| Tipos | Type hints em 100% de args/returns; `readonly` em VOs/DTOs/messages | 🔴 |
| Atributos | Apenas atributos PHP — nenhuma anotação docblock funcional | 🔴 |
| Controller | Thin, estende `AbstractController`, delega a services/command bus | 🔴 |
| DI | Construtor apenas; sem `ContainerInterface`; services privados; classes `final` | 🔴 |
| Entity | Sem services injetados; sem `EntityManager`; invariantes no construtor/métodos de domínio | 🔴 |
| Repository | `ServiceEntityRepository`; sem lógica de negócio; sem `findAll()` em listagem | 🔴 |
| Doctrine | `:param` binding; `doctrine:schema:validate` clean; attributes mapping | 🔴 |
| Migrations | `up()`+`down()`; SQL revisado; sem edit de migration aplicada; sem services PHP | 🔴 |
| Postgres | JSONB (não JSON); `timestamptz`; UUID v7 (Symfony 7.4); índices GIN em JSONB filtrados; PHP enum em vez de ENUM PG | 🔴 |
| Static | PHPStan level ≥ 6 (target 8) sem baseline growth | 🔴 |
| Testes | Unit + Integration/Functional cobrindo cenário; DAMA rollback; PHPUnit attributes | 🔴 |
| Testes | Foundry v2 para fixtures; mocks apenas de abstrações próprias | 🟡 |
| CSRF/XSS | CSRF em forms; `|raw` só em dados seguros; autoescape ativo | 🔴 |
| Authz | Voters + `#[IsGranted]`; nunca `in_array` em roles | 🔴 |
| Secrets | `symfony/secrets` vault; `.env.*.local` no `.gitignore`; sem secrets em `.env` commitado | 🔴 |
| Auth | `password_hashers: auto` + `migrate_from`; signed `remember_me`; rate limiter em login | 🔴 |
| Perf | OPcache + preload em prod; `validate_timestamps=0`; `cache:warmup` no deploy | 🔴 |
| Perf | Doctrine cache configurado (PSR-6 em ORM 3.x); N+1 ausente no Profiler | 🔴 |
| Logs | `fingers_crossed` + JSON + stderr em prod; sem PII/secrets | 🔴 |
| Docker | USER não-root; multi-stage; `.dockerignore`; pinned tags; `HEALTHCHECK`; `COPY composer.*` antes do source | 🔴 |
| CI | `composer audit` fail-on-CVE; `lint:container`/`lint:twig`/`lint:yaml`; `doctrine:schema:validate` | 🔴 |
| CI | Branch protection + required checks | 🔴 |

## Ferramentas obrigatórias (stack completo)

- **Análise**: `phpstan/phpstan` (level 8 + bleeding edge + strict-rules + extensions symfony/doctrine/phpunit), `friendsofphp/php-cs-fixer` (`@Symfony`+`:risky`+`@PHP82Migration`), `rector/rector` (sets PHP 8.2 + Symfony + Doctrine + PHPUnit).
- **Testes**: `phpunit/phpunit` 10+/11, `dama/doctrine-test-bundle`, `zenstruck/foundry` v2, `symfony/panther` se E2E.
- **Segurança**: `composer audit` em CI, `nelmio/security-bundle`, `nelmio/cors-bundle` (se API), `symfony/rate-limiter`.
- **DI**: `deptrac/deptrac` (se arquitetura hexagonal).
- **Dev**: `symfony/web-profiler-bundle`, `symfony/debug-bundle`, `symfony/maker-bundle`.

## Fontes oficiais consultadas

Symfony: `symfony.com/doc/current/best_practices.html`, `contributing/code/standards.html`, `contributing/code/conventions.html`, `reference/attributes.html`, `controller.html`, `service_container.html`, `doctrine.html`, `testing.html`, `security.html`, `security/voters.html`, `security/passwords.html`, `rate_limiter.html`, `performance.html`, `cache.html`, `logging.html`, `configuration/secrets.html`, `routing.html`, `messenger.html`, `workflow.html`, `event_dispatcher.html`, `forms.html`, `components/uid.html`. PHP-FIG: PSR-1/4/7/12/15/18 em `php-fig.org/psr/`. Doctrine: `doctrine-project.org/projects/doctrine-orm/en/current/`, `doctrine-project.org/projects/doctrine-migrations/`, `github.com/doctrine/orm/blob/3.6.x/UPGRADE.md`. Twig: `twig.symfony.com/doc/3.x/filters/escape.html`. PHPStan: `phpstan.org/user-guide/rule-levels`. Psalm: `psalm.dev/docs`. PHP-CS-Fixer: `cs.symfony.com/doc/ruleSets/`. Docker: `docs.docker.com/develop/develop-images/dockerfile_best-practices/`. Noback: `matthiasnoback.nl` (*Object Design Style Guide*, *Advanced Web Application Architecture*). Potencier: `symfony.com/book` (*Symfony: The Fast Track*). Repos de referência: `github.com/symfony/demo`, `github.com/api-platform/core`, `github.com/Sylius/Sylius`, `github.com/CodelyTV/php-ddd-example`. Foundry: `github.com/zenstruck/foundry`. DAMA: `github.com/dmaicher/doctrine-test-bundle`.

---

### Observações finais para a equipe

1. **Defina uma política única no repositório** para itens ambíguos (singular vs plural em tabelas, uso ou não de `declare(strict_types=1);`, hexagonal vs flat). Documente em `CODING_STANDARDS.md`.
2. **Automatize o que pode ser automatizado** — PHP-CS-Fixer + PHPStan + lint:* devem rodar localmente via pre-commit hook e em CI. Code review humano foca em arquitetura e intenção.
3. **Baselines são temporárias** — configure pipelines para falhar se a baseline cresce em PR novo.
4. **Adote evolutivamente** — se vindo de projeto legado: primeiro PHP-CS-Fixer, depois PHPStan level 5, subindo gradualmente; Foundry e DAMA podem entrar em paralelo.
5. **Revise periodicamente** — Symfony evolui rapidamente (atributos novos a cada minor release). Atualize este checklist a cada upgrade de LTS.