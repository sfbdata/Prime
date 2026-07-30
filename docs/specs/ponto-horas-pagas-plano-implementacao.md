# Horas pagas — Plano de Implementação

> **Para agentes executores:** SUB-SKILL OBRIGATÓRIA — use `superpowers:subagent-driven-development`
> (recomendado) ou `superpowers:executing-plans` para executar tarefa a tarefa. Os passos usam checkbox
> (`- [ ]`) para rastreamento.

**Objetivo:** permitir que um administrador acerte o banco de horas de um colaborador por competência
(mês/ano), com valor positivo ou negativo, exibido na folha de ponto sob o rótulo "Horas pagas".

**Arquitetura:** uma tabela nova (`ponto_lancamento_horas_pagas`) guarda os lançamentos. O saldo do banco de
horas continua sendo recalculado do zero a cada leitura — o lançamento vira mais um ingrediente que
`FolhaPontoBuilder` lê via repositório injetado no construtor, sem alterar nenhuma assinatura pública. Escrita
passa por três UseCases (os primeiros do domínio `Ponto`), atrás da mesma guarda de permissão da ficha do
funcionário.

**Stack:** PHP 8.2, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, PHPUnit + Foundry v2 + DAMA.

**Spec:** `docs/specs/ponto-horas-pagas-banco-de-horas.md` (commit `322a1f0`). Em qualquer divergência entre
este plano e a spec, **a spec vence** — pare e avise.

**Branch/worktree:** `ponto-horas-pagas` em `.claude/worktrees/ponto-horas-pagas`, criada a partir de
`master` (`1a6bb81`).

---

## Restrições globais

Valem para **toda** tarefa; não se repetem em cada passo.

- `declare(strict_types=1);` em todo arquivo PHP novo.
- Type hints em 100% dos argumentos e retornos. `private readonly` com constructor property promotion.
- Classes `final`, **exceto** a entidade Doctrine (proxies do ORM proíbem).
- Só atributos PHP (`#[ORM\...]`, `#[Route]`), nunca annotation em docblock.
- `===`/`!==` sempre. Linha em branco antes do `return`. Nunca `else`/`elseif` depois de `if` que retorna ou lança.
- Código, comentários e mensagens de commit em **português brasileiro**. Commit no imperativo, máx. 72 chars, sem ponto final.
- Nomes: `camelCase` métodos/variáveis, `PascalCase` classes, `snake_case` rotas/templates/colunas.
- **Todo comando PHP roda dentro do container.** Nunca rodar `php`/`composer`/`bin/console` fora dele.
- ⚠️ **O comando padrão do projeto (`cd app && ...`) dá VERDE FALSO nesta frente.** O container monta a
  raiz do repositório, e `cd app` cai no **checkout principal** — que está noutra branch. Trabalhamos numa
  worktree, então todo comando tem de apontar para ela:

  ```bash
  # testes da frente (código da frente, banco da frente):
  scripts/frente-testar.sh ponto-horas-pagas                      # suíte completa
  scripts/frente-testar.sh ponto-horas-pagas --filter <Nome>       # um teste

  # console/composer da frente:
  docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/ponto-horas-pagas/app && <cmd>'
  ```

  Os scripts rodam a partir da raiz do repositório (`/home/prime/projetos/jusprime`). A frente tem banco de
  teste próprio (`saas_testponto-horas-pagas`, via `TEST_TOKEN`), já clonado — não mexa nos bancos das
  outras frentes.
- **A migration é aplicada no banco de DEV compartilhado.** Isso é esperado e é o padrão do projeto, mas
  significa que o dev fica incompatível com o `master` até a frente ser integrada. Aplicar em produção é do
  humano.
- **Toda query filtra por tenant explicitamente**, além do `TenantFilter` do Doctrine — é dado de ponto, risco ALTO.
- A suíte roda com `failOnDeprecation/Notice/Warning`: um deprecation derruba tudo.
- **`git push`, `merge`, `rebase` e `reset` são proibidos.** Commits locais são permitidos e esperados ao fim de cada tarefa.
- O worktree tem o marcador `.frente` — não apague.
- **Nunca abrir o Playwright/navegador.** O smoke é do dono; ao final, liste o que ele deve olhar.

### Rótulo e textos de interface (literais, copiar exatamente)

- Rótulo na folha do colaborador: `Horas pagas`
- Botão na ficha do admin: `Horas pagas`
- Opções de operação no formulário: `Descontar do banco` / `Acrescentar ao banco`
- O motivo **nunca** aparece em tela do colaborador, nem no PDF, nem no XLSX.

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/src/Ponto/Entity/LancamentoHorasPagas.php` | o dado: competência, minutos com sinal, motivo, autoria |
| `app/src/Ponto/Repository/LancamentoHorasPagasRepository.php` | soma por competência; listagem para a ficha |
| `app/migrations/VersionYYYYMMDDHHMMSS.php` | cria `ponto_lancamento_horas_pagas` |
| `app/src/Ponto/DTO/LancamentoHorasPagasInput.php` | horas/minutos/operação → minutos com sinal; validação |
| `app/src/Ponto/Form/LancamentoHorasPagasType.php` | formulário do modal |
| `app/src/Ponto/UseCase/LancarHorasPagasUseCase.php` | regras de criação |
| `app/src/Ponto/UseCase/EditarHorasPagasUseCase.php` | regras de edição |
| `app/src/Ponto/UseCase/ExcluirHorasPagasUseCase.php` | regras de exclusão |
| `app/src/Ponto/Exception/HorasPagasInvalidaException.php` | erro de domínio (mensagem vai para o flash) |
| `app/src/Ponto/Controller/HorasPagasController.php` | 3 rotas POST, guarda de permissão, CSRF |
| `app/src/Ponto/Service/FolhaPontoBuilder.php` | **modificado**: soma os lançamentos nos agregadores |
| `app/src/Ponto/Controller/PontoController.php` | **modificado**: `montarDadosFolha()` expõe `horasPagasMinutos` |
| `app/src/Ponto/Service/FolhaPontoXlsxExporter.php` | **modificado**: linha "Horas pagas" |
| `app/templates/ponto/_folha_table.html.twig` | **modificado**: rodapé com a linha |
| `app/templates/ponto/folha_pdf.html.twig` | **modificado**: idem |
| `app/templates/tenant/edit_user_role.html.twig` | **modificado**: botão + modal |
| `app/templates/tenant/_horas_pagas_tab.html.twig` | lista dos lançamentos (novo parcial) |
| `app/src/Controller/TenantController.php` | **modificado**: passa `lancamentosHorasPagas` para a ficha |

---

## Ordem das tarefas e o que cada uma entrega

1. Entidade + repositório + migration → a tabela existe e sabe somar
2. UseCases + DTO → as regras de negócio, testadas sem banco
3. Integração no cálculo → o saldo passa a refletir o lançamento
4. Controller + rotas → dá para gravar via HTTP, com permissão e CSRF
5. Tela do admin → dá para usar
6. Tela do colaborador (web + PDF + XLSX) → o funcionário enxerga
7. Revisão adversarial + suíte completa → fecha

---

### Tarefa 1: Entidade, repositório e migration

**Arquivos:**
- Criar: `app/src/Ponto/Entity/LancamentoHorasPagas.php`
- Criar: `app/src/Ponto/Repository/LancamentoHorasPagasRepository.php`
- Criar: `app/tests/Ponto/Functional/LancamentoHorasPagasRepositoryTest.php`
- Criar: `app/migrations/VersionYYYYMMDDHHMMSS.php` (gerada)

**Interfaces:**
- Produz: `LancamentoHorasPagas` com getters/setters de `id, tenant, user, ano, mes, minutos, motivo,
  criadoPor, criadoEm, atualizadoPor, atualizadoEm`.
- Produz: `LancamentoHorasPagasRepository::somarPorCompetencia(User $user, Tenant $tenant, int $ano, int $mes): int`
- Produz: `LancamentoHorasPagasRepository::listarPorUser(User $user, Tenant $tenant): array`
- Produz: `LancamentoHorasPagasRepository::buscarDoTenant(int $id, Tenant $tenant): ?LancamentoHorasPagas`

- [ ] **Passo 1: Escrever o teste que falha (repositório)**

Criar `app/tests/Ponto/Functional/LancamentoHorasPagasRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(LancamentoHorasPagasRepository::class)]
final class LancamentoHorasPagasRepositoryTest extends JusPrimeWebTestCase
{
    #[TestDox('somarPorCompetencia soma varios lancamentos do mesmo mes, com sinal')]
    public function testSomaVariosLancamentosDoMesmoMes(): void
    {
        static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $alvo   = $this->criarUsuario($tenant);

        $this->gravarLancamento($tenant, $alvo, $admin, 2026, 8, -6000);
        $this->gravarLancamento($tenant, $alvo, $admin, 2026, 8, 480);
        $this->gravarLancamento($tenant, $alvo, $admin, 2026, 9, 120); // outro mes, nao pode entrar

        $repo = static::getContainer()->get(LancamentoHorasPagasRepository::class);

        self::assertSame(-5520, $repo->somarPorCompetencia($alvo, $tenant, 2026, 8));
    }

    #[TestDox('somarPorCompetencia retorna 0 quando nao ha lancamento')]
    public function testSemLancamentoRetornaZero(): void
    {
        static::createClient();
        $tenant = $this->criarTenant();
        $alvo   = $this->criarUsuario($tenant);

        $repo = static::getContainer()->get(LancamentoHorasPagasRepository::class);

        self::assertSame(0, $repo->somarPorCompetencia($alvo, $tenant, 2026, 8));
    }

    #[TestDox('somarPorCompetencia nao enxerga lancamento de outro tenant')]
    public function testIsolamentoEntreTenants(): void
    {
        static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $admin   = $this->criarAdmin($tenantA);
        $alvo    = $this->criarUsuario($tenantA);

        // mesmo colaborador, lancamento gravado sob o tenant B: nao pode vazar para o A
        $this->gravarLancamento($tenantB, $alvo, $admin, 2026, 8, 999);

        $repo = static::getContainer()->get(LancamentoHorasPagasRepository::class);

        self::assertSame(0, $repo->somarPorCompetencia($alvo, $tenantA, 2026, 8));
    }

    #[TestDox('buscarDoTenant devolve null para lancamento de outro tenant')]
    public function testBuscarDoTenantNaoVazaEntreEscritorios(): void
    {
        static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $admin   = $this->criarAdmin($tenantB);
        $alvo    = $this->criarUsuario($tenantB);

        $lancamento = $this->gravarLancamento($tenantB, $alvo, $admin, 2026, 8, 300);

        $repo = static::getContainer()->get(LancamentoHorasPagasRepository::class);

        self::assertNull($repo->buscarDoTenant((int) $lancamento->getId(), $tenantA));
        self::assertNotNull($repo->buscarDoTenant((int) $lancamento->getId(), $tenantB));
    }

    private function gravarLancamento(
        \App\Entity\Tenant\Tenant $tenant,
        \App\Entity\Auth\User $alvo,
        \App\Entity\Auth\User $autor,
        int $ano,
        int $mes,
        int $minutos,
    ): LancamentoHorasPagas {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($alvo);
        $lancamento->setAno($ano);
        $lancamento->setMes($mes);
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('teste');
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());

        $em->persist($lancamento);
        $em->flush();

        return $lancamento;
    }
}
```

- [ ] **Passo 2: Rodar e confirmar que falha**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter LancamentoHorasPagasRepositoryTest'
```

Esperado: FALHA com `Class "App\Ponto\Entity\LancamentoHorasPagas" not found`.

- [ ] **Passo 3: Criar a entidade**

`app/src/Ponto/Entity/LancamentoHorasPagas.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ajuste manual do banco de horas de um colaborador, preso a uma COMPETÊNCIA (mês/ano), não a um dia.
 *
 * `minutos` carrega o sinal: negativo desconta do banco (horas pagas em dinheiro na folha salarial),
 * positivo acrescenta (bonificação). Nunca zero. Vários lançamentos na mesma competência somam.
 *
 * Implementa Auditavel: criação, edição e exclusão ficam registradas no audit_log. É a única prova
 * que sobra, já que editar/excluir apaga o registro do produto — e isto altera verba trabalhista.
 */
#[ORM\Entity(repositoryClass: LancamentoHorasPagasRepository::class)]
#[ORM\Table(name: 'ponto_lancamento_horas_pagas')]
#[ORM\Index(fields: ['tenant', 'user', 'ano', 'mes'], name: 'IDX_HORAS_PAGAS_COMPETENCIA')]
class LancamentoHorasPagas implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'smallint')]
    private int $ano = 0;

    #[ORM\Column(type: 'smallint')]
    private int $mes = 0;

    #[ORM\Column]
    private int $minutos = 0;

    #[ORM\Column(type: 'text')]
    private string $motivo = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $criadoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $atualizadoPor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getAno(): int
    {
        return $this->ano;
    }

    public function setAno(int $ano): self
    {
        $this->ano = $ano;

        return $this;
    }

    public function getMes(): int
    {
        return $this->mes;
    }

    public function setMes(int $mes): self
    {
        $this->mes = $mes;

        return $this;
    }

    public function getMinutos(): int
    {
        return $this->minutos;
    }

    public function setMinutos(int $minutos): self
    {
        $this->minutos = $minutos;

        return $this;
    }

    public function getMotivo(): string
    {
        return $this->motivo;
    }

    public function setMotivo(string $motivo): self
    {
        $this->motivo = $motivo;

        return $this;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;

        return $this;
    }

    public function getCriadoEm(): ?\DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function setCriadoEm(?\DateTimeImmutable $criadoEm): self
    {
        $this->criadoEm = $criadoEm;

        return $this;
    }

    public function getAtualizadoPor(): ?User
    {
        return $this->atualizadoPor;
    }

    public function setAtualizadoPor(?User $atualizadoPor): self
    {
        $this->atualizadoPor = $atualizadoPor;

        return $this;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function setAtualizadoEm(?\DateTimeImmutable $atualizadoEm): self
    {
        $this->atualizadoEm = $atualizadoEm;

        return $this;
    }
}
```

- [ ] **Passo 4: Criar o repositório**

`app/src/Ponto/Repository/LancamentoHorasPagasRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\LancamentoHorasPagas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LancamentoHorasPagas>
 */
class LancamentoHorasPagasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LancamentoHorasPagas::class);
    }

    /**
     * Soma, com sinal, os lançamentos do colaborador naquela competência. 0 quando não há nenhum.
     *
     * Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
     */
    public function somarPorCompetencia(User $user, Tenant $tenant, int $ano, int $mes): int
    {
        $soma = $this->createQueryBuilder('l')
            ->select('SUM(l.minutos)')
            ->andWhere('l.user = :user')
            ->andWhere('l.tenant = :tenant')
            ->andWhere('l.ano = :ano')
            ->andWhere('l.mes = :mes')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('ano', $ano)
            ->setParameter('mes', $mes)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $soma;
    }

    /**
     * Lançamentos do colaborador no escritório, mais recentes primeiro. Alimenta a ficha do admin.
     *
     * @return LancamentoHorasPagas[]
     */
    public function listarPorUser(User $user, Tenant $tenant): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->orderBy('l.ano', 'DESC')
            ->addOrderBy('l.mes', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca por id EXIGINDO o tenant — nunca buscar lançamento só por id vindo da URL (IDOR).
     */
    public function buscarDoTenant(int $id, Tenant $tenant): ?LancamentoHorasPagas
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.id = :id')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
```

- [ ] **Passo 5: Fotografar a divergência de schema que JÁ existia**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:update --dump-sql' > /tmp/schema-antes.sql
cat /tmp/schema-antes.sql
```

Guarde esta saída. **Tudo que aparece aqui NÃO é seu** e tem de sair da migration gerada no passo seguinte.

- [ ] **Passo 6: Gerar a migration**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console make:migration'
```

Abrir o arquivo gerado em `app/migrations/` e **apagar toda linha que já aparecia em `/tmp/schema-antes.sql`**.
Atenção especial a qualquer `DROP INDEX`: o Doctrine não sabe representar índice funcional e propõe apagá-lo
sem que nada quebre visivelmente — derruba performance e, se for `unique`, deixa entrar duplicata.

O que deve sobrar: `CREATE TABLE ponto_lancamento_horas_pagas`, suas FKs, a sequence e o índice
`IDX_HORAS_PAGAS_COMPETENCIA`.

- [ ] **Passo 7: Aplicar no dev e validar o mapeamento**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:migrations:migrate --no-interaction'
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:validate --skip-sync'
```

Esperado: mapeamento OK.

- [ ] **Passo 8: Rodar o teste e confirmar que passa**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter LancamentoHorasPagasRepositoryTest'
```

Esperado: 4 testes, 4 verdes.

- [ ] **Passo 9: Commit**

```bash
git add app/src/Ponto/Entity/LancamentoHorasPagas.php \
        app/src/Ponto/Repository/LancamentoHorasPagasRepository.php \
        app/tests/Ponto/Functional/LancamentoHorasPagasRepositoryTest.php \
        app/migrations/
git commit -m "cria a tabela de lancamento de horas pagas do banco de horas"
```

---

### Tarefa 2: DTO e UseCases

**Arquivos:**
- Criar: `app/src/Ponto/Exception/HorasPagasInvalidaException.php`
- Criar: `app/src/Ponto/DTO/LancamentoHorasPagasInput.php`
- Criar: `app/src/Ponto/UseCase/LancarHorasPagasUseCase.php`
- Criar: `app/src/Ponto/UseCase/EditarHorasPagasUseCase.php`
- Criar: `app/src/Ponto/UseCase/ExcluirHorasPagasUseCase.php`
- Criar: `app/tests/Ponto/Unit/LancarHorasPagasUseCaseTest.php`

**Interfaces:**
- Consome: `LancamentoHorasPagas` e `LancamentoHorasPagasRepository` (Tarefa 1).
- Produz: `LancamentoHorasPagasInput` com propriedades públicas `int $ano`, `int $mes`, `string $operacao`
  (`'descontar'`|`'acrescentar'`), `int $horas`, `int $minutos`, `string $motivo`, e o método
  `minutosComSinal(): int`.
- Produz: `LancarHorasPagasUseCase::__invoke(LancamentoHorasPagasInput $input, User $colaborador, User $autor, Tenant $tenant): LancamentoHorasPagas`
- Produz: `EditarHorasPagasUseCase::__invoke(LancamentoHorasPagas $lancamento, LancamentoHorasPagasInput $input, User $autor, Tenant $tenant): void`
- Produz: `ExcluirHorasPagasUseCase::__invoke(LancamentoHorasPagas $lancamento, User $autor, Tenant $tenant): void`
- Produz: `HorasPagasInvalidaException extends \DomainException` — a mensagem vai direto para o flash.

**Storytelling (obrigatório antes de escrever UseCase, conforme a skill `criar-usecase`):**
Quem: um administrador do escritório com `admin.users.manage`. O quê: registra que N horas foram pagas em
dinheiro (desconta do banco) ou presenteadas (acrescenta), numa competência. Por quê: sem isso o escritório
paga as horas duas vezes — em dinheiro e em folga. Fluxos de erro: quantidade zero, motivo vazio, competência
futura, lançar para si mesmo, mexer em lançamento de outro escritório.

- [ ] **Passo 1: Escrever os testes que falham**

Criar `app/tests/Ponto/Unit/LancarHorasPagasUseCaseTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use App\Ponto\UseCase\EditarHorasPagasUseCase;
use App\Ponto\UseCase\ExcluirHorasPagasUseCase;
use App\Ponto\UseCase\LancarHorasPagasUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LancarHorasPagasUseCase::class)]
#[CoversClass(EditarHorasPagasUseCase::class)]
#[CoversClass(ExcluirHorasPagasUseCase::class)]
#[CoversClass(LancamentoHorasPagasInput::class)]
final class LancarHorasPagasUseCaseTest extends TestCase
{
    #[TestDox('descontar 100h30 vira -6030 minutos')]
    public function testOperacaoDescontarProduzMinutosNegativos(): void
    {
        $input = $this->input(operacao: 'descontar', horas: 100, minutos: 30);

        self::assertSame(-6030, $input->minutosComSinal());
    }

    #[TestDox('acrescentar 8h vira +480 minutos')]
    public function testOperacaoAcrescentarProduzMinutosPositivos(): void
    {
        $input = $this->input(operacao: 'acrescentar', horas: 8, minutos: 0);

        self::assertSame(480, $input->minutosComSinal());
    }

    #[TestDox('quantidade zero e recusada')]
    public function testQuantidadeZeroRecusada(): void
    {
        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Informe uma quantidade de horas maior que zero.');

        ($this->useCase())($this->input(horas: 0, minutos: 0), $this->user(2), $this->user(1), $this->tenant(1));
    }

    #[TestDox('motivo so com espacos e recusado')]
    public function testMotivoVazioRecusado(): void
    {
        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Informe o motivo do lançamento.');

        ($this->useCase())($this->input(motivo: '   '), $this->user(2), $this->user(1), $this->tenant(1));
    }

    #[TestDox('competencia futura e recusada')]
    public function testCompetenciaFuturaRecusada(): void
    {
        $proximoMes = (new \DateTimeImmutable('first day of next month'));

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('A competência não pode ser futura.');

        ($this->useCase())(
            $this->input(ano: (int) $proximoMes->format('Y'), mes: (int) $proximoMes->format('n')),
            $this->user(2),
            $this->user(1),
            $this->tenant(1),
        );
    }

    #[TestDox('ninguem lanca horas pagas para si mesmo')]
    public function testAutoLancamentoRecusado(): void
    {
        $mesmo = $this->user(7);

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Você não pode lançar horas pagas para si mesmo.');

        ($this->useCase())($this->input(), $mesmo, $mesmo, $this->tenant(1));
    }

    #[TestDox('super-admin tambem nao lanca para si mesmo')]
    public function testAutoLancamentoRecusadoTambemParaSuperAdmin(): void
    {
        $mesmo = $this->user(7, ['ROLE_SUPER_ADMIN']);

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Você não pode lançar horas pagas para si mesmo.');

        ($this->useCase())($this->input(), $mesmo, $mesmo, $this->tenant(1));
    }

    #[TestDox('lancamento valido persiste com autoria e minutos com sinal')]
    public function testLancamentoValidoPersiste(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $autor       = $this->user(1);
        $colaborador = $this->user(2);
        $tenant      = $this->tenant(1);

        $lancamento = (new LancarHorasPagasUseCase($em))(
            $this->input(operacao: 'descontar', horas: 100, minutos: 0, motivo: 'Pago na folha de agosto'),
            $colaborador,
            $autor,
            $tenant,
        );

        self::assertSame(-6000, $lancamento->getMinutos());
        self::assertSame('Pago na folha de agosto', $lancamento->getMotivo());
        self::assertSame($colaborador, $lancamento->getUser());
        self::assertSame($autor, $lancamento->getCriadoPor());
        self::assertSame($tenant, $lancamento->getTenant());
        self::assertNotNull($lancamento->getCriadoEm());
    }

    #[TestDox('editar lancamento de outro tenant e recusado')]
    public function testEditarLancamentoDeOutroTenantRecusado(): void
    {
        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->tenant(9));
        $lancamento->setUser($this->user(2));

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Lançamento não pertence a este escritório.');

        (new EditarHorasPagasUseCase($this->createStub(EntityManagerInterface::class)))(
            $lancamento,
            $this->input(),
            $this->user(1),
            $this->tenant(1),
        );
    }

    #[TestDox('editar o proprio lancamento recebido e recusado')]
    public function testEditarLancamentoDoProprioAutorRecusado(): void
    {
        $mesmo = $this->user(7);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->tenant(1));
        $lancamento->setUser($mesmo);

        $this->expectException(HorasPagasInvalidaException::class);
        $this->expectExceptionMessage('Você não pode lançar horas pagas para si mesmo.');

        (new EditarHorasPagasUseCase($this->createStub(EntityManagerInterface::class)))(
            $lancamento,
            $this->input(),
            $mesmo,
            $this->tenant(1),
        );
    }

    #[TestDox('excluir lancamento de outro tenant e recusado e nao remove nada')]
    public function testExcluirLancamentoDeOutroTenantRecusado(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove');

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->tenant(9));
        $lancamento->setUser($this->user(2));

        $this->expectException(HorasPagasInvalidaException::class);

        (new ExcluirHorasPagasUseCase($em))($lancamento, $this->user(1), $this->tenant(1));
    }

    private function useCase(): LancarHorasPagasUseCase
    {
        return new LancarHorasPagasUseCase($this->createStub(EntityManagerInterface::class));
    }

    private function input(
        int $ano = 2026,
        int $mes = 1,
        string $operacao = 'descontar',
        int $horas = 8,
        int $minutos = 0,
        string $motivo = 'motivo de teste',
    ): LancamentoHorasPagasInput {
        $input = new LancamentoHorasPagasInput();
        $input->ano      = $ano;
        $input->mes      = $mes;
        $input->operacao = $operacao;
        $input->horas    = $horas;
        $input->minutos  = $minutos;
        $input->motivo   = $motivo;

        return $input;
    }

    /**
     * O id do User é privado e sem setter; a identidade que os UseCases comparam vem de getId().
     */
    private function user(int $id, array $roles = []): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }
}
```

> **Se `User` ou `Tenant` forem `final`** (o que impediria `createStub`), pare e avise: será preciso extrair a
> comparação de identidade para um método que receba o id inteiro. Não contorne com reflection.

- [ ] **Passo 2: Rodar e confirmar que falha**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter LancarHorasPagasUseCaseTest'
```

Esperado: FALHA com classe não encontrada.

- [ ] **Passo 3: Criar a exceção de domínio**

`app/src/Ponto/Exception/HorasPagasInvalidaException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * Erro de regra de negócio do lançamento de horas pagas. A mensagem é escrita para o usuário final:
 * o controller a repassa direto para o flash.
 */
final class HorasPagasInvalidaException extends \DomainException
{
}
```

- [ ] **Passo 4: Criar o DTO**

`app/src/Ponto/DTO/LancamentoHorasPagasInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * O admin informa quantidade e sentido separados — nunca um número com sinal digitado à mão.
 * Errar o sinal num campo que mexe em banco de horas é invisível até alguém reclamar do salário.
 *
 * Propriedades públicas (não readonly) porque o Symfony Form precisa escrever nelas.
 */
final class LancamentoHorasPagasInput
{
    public const OPERACAO_DESCONTAR   = 'descontar';
    public const OPERACAO_ACRESCENTAR = 'acrescentar';

    #[Assert\NotBlank]
    #[Assert\Range(min: 2000, max: 2999)]
    public int $ano = 0;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 12)]
    public int $mes = 0;

    #[Assert\Choice(choices: [self::OPERACAO_DESCONTAR, self::OPERACAO_ACRESCENTAR])]
    public string $operacao = self::OPERACAO_DESCONTAR;

    #[Assert\GreaterThanOrEqual(0)]
    public int $horas = 0;

    #[Assert\Range(min: 0, max: 59)]
    public int $minutos = 0;

    #[Assert\NotBlank(message: 'Informe o motivo do lançamento.')]
    public string $motivo = '';

    /**
     * Quantidade total em minutos, já com o sinal da operação.
     */
    public function minutosComSinal(): int
    {
        $total = ($this->horas * 60) + $this->minutos;

        return $this->operacao === self::OPERACAO_DESCONTAR ? -$total : $total;
    }
}
```

- [ ] **Passo 5: Criar o UseCase de lançamento**

`app/src/Ponto/UseCase/LancarHorasPagasUseCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use Doctrine\ORM\EntityManagerInterface;

final class LancarHorasPagasUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws HorasPagasInvalidaException
     */
    public function __invoke(
        LancamentoHorasPagasInput $input,
        User $colaborador,
        User $autor,
        Tenant $tenant,
    ): LancamentoHorasPagas {
        GuardaHorasPagas::recusarAutoLancamento($colaborador, $autor);
        GuardaHorasPagas::validarInput($input);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($colaborador);
        $lancamento->setAno($input->ano);
        $lancamento->setMes($input->mes);
        $lancamento->setMinutos($input->minutosComSinal());
        $lancamento->setMotivo(trim($input->motivo));
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());

        $this->em->persist($lancamento);
        $this->em->flush();

        return $lancamento;
    }
}
```

- [ ] **Passo 6: Criar a guarda compartilhada**

`app/src/Ponto/UseCase/GuardaHorasPagas.php` — as três regras que os três UseCases repetem. Sem isso, a
trava de auto-lançamento existiria em três cópias e uma delas ficaria para trás numa alteração futura.

```php
<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;

final class GuardaHorasPagas
{
    /**
     * Ninguém acerta o próprio banco de horas — nem super-admin. A trava é sobre a identidade,
     * não sobre o papel: quem tem o papel já poderia se autoconceder e depois apagar o rastro.
     */
    public static function recusarAutoLancamento(User $colaborador, User $autor): void
    {
        if ($colaborador->getId() === $autor->getId()) {
            throw new HorasPagasInvalidaException('Você não pode lançar horas pagas para si mesmo.');
        }
    }

    public static function recusarOutroTenant(LancamentoHorasPagas $lancamento, Tenant $tenant): void
    {
        if ($lancamento->getTenant()?->getId() !== $tenant->getId()) {
            throw new HorasPagasInvalidaException('Lançamento não pertence a este escritório.');
        }
    }

    public static function validarInput(LancamentoHorasPagasInput $input): void
    {
        if (($input->horas * 60) + $input->minutos <= 0) {
            throw new HorasPagasInvalidaException('Informe uma quantidade de horas maior que zero.');
        }

        if (trim($input->motivo) === '') {
            throw new HorasPagasInvalidaException('Informe o motivo do lançamento.');
        }

        $competencia = new \DateTimeImmutable(sprintf('%04d-%02d-01', $input->ano, $input->mes));
        $mesAtual    = new \DateTimeImmutable('first day of this month 00:00:00');

        if ($competencia > $mesAtual) {
            throw new HorasPagasInvalidaException('A competência não pode ser futura.');
        }
    }
}
```

- [ ] **Passo 7: Criar os UseCases de edição e exclusão**

`app/src/Ponto/UseCase/EditarHorasPagasUseCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use Doctrine\ORM\EntityManagerInterface;

final class EditarHorasPagasUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws HorasPagasInvalidaException
     */
    public function __invoke(
        LancamentoHorasPagas $lancamento,
        LancamentoHorasPagasInput $input,
        User $autor,
        Tenant $tenant,
    ): void {
        GuardaHorasPagas::recusarOutroTenant($lancamento, $tenant);
        GuardaHorasPagas::recusarAutoLancamento($lancamento->getUser(), $autor);
        GuardaHorasPagas::validarInput($input);

        $lancamento->setAno($input->ano);
        $lancamento->setMes($input->mes);
        $lancamento->setMinutos($input->minutosComSinal());
        $lancamento->setMotivo(trim($input->motivo));
        $lancamento->setAtualizadoPor($autor);
        $lancamento->setAtualizadoEm(new \DateTimeImmutable());

        $this->em->flush();
    }
}
```

`app/src/Ponto/UseCase/ExcluirHorasPagasUseCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use Doctrine\ORM\EntityManagerInterface;

final class ExcluirHorasPagasUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws HorasPagasInvalidaException
     */
    public function __invoke(LancamentoHorasPagas $lancamento, User $autor, Tenant $tenant): void
    {
        GuardaHorasPagas::recusarOutroTenant($lancamento, $tenant);
        GuardaHorasPagas::recusarAutoLancamento($lancamento->getUser(), $autor);

        $this->em->remove($lancamento);
        $this->em->flush();
    }
}
```

- [ ] **Passo 8: Rodar e confirmar que passa**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter LancarHorasPagasUseCaseTest'
```

Esperado: 11 testes, 11 verdes.

- [ ] **Passo 9: Commit**

```bash
git add app/src/Ponto/DTO app/src/Ponto/UseCase app/src/Ponto/Exception \
        app/tests/Ponto/Unit/LancarHorasPagasUseCaseTest.php
git commit -m "cria os usecases de lancar, editar e excluir horas pagas"
```

---

### Tarefa 3: integração no cálculo do banco de horas

**Esta é a tarefa mais arriscada da frente.** É onde o número que o funcionário vê muda.

**Arquivos:**
- Modificar: `app/src/Ponto/Service/FolhaPontoBuilder.php` (construtor; `calcularSaldoAteMes` linha 244;
  `calcularSaldoAnual` linha 329)
- Criar: `app/tests/Ponto/Unit/FolhaPontoBuilderHorasPagasTest.php`
- Modificar: `app/tests/Ponto/Unit/FolhaPontoBuilderTest.php` (setUp: terceiro stub no construtor)

**Interfaces:**
- Consome: `LancamentoHorasPagasRepository::somarPorCompetencia()` (Tarefa 1).
- Produz: `FolhaPontoBuilder::somarHorasPagasDaCompetencia(User $user, Tenant $tenant, int $ano, int $mes): int`
- Produz: assinaturas de `calcularSaldoAnual` e `calcularSaldoAteMes` ganham **um parâmetro final
  `?Tenant $tenant = null`**. Ver passo 4 para por que ele é opcional e o que acontece quando é `null`.

- [ ] **Passo 1: Escrever os testes que falham**

Criar `app/tests/Ponto/Unit/FolhaPontoBuilderHorasPagasTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O banco de horas não é persistido: é recalculado a cada leitura. O lançamento de horas pagas
 * entra aqui, nos agregadores — nunca em buildRows, que é por dia.
 */
#[CoversClass(FolhaPontoBuilder::class)]
final class FolhaPontoBuilderHorasPagasTest extends TestCase
{
    #[TestDox('lancamento negativo reduz o saldo anual')]
    public function testLancamentoNegativoReduzSaldoAnual(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => -600]]);

        $saldo = $builder->calcularSaldoAnual(
            $this->userComJornada(),
            2026,
            [],
            null,
            new \DateTimeImmutable('2026-01-01'),
            $this->tenant(),
        );

        self::assertSame(-600, $saldo, 'as horas pagas do mês 1 deveriam ter descontado o saldo');
    }

    #[TestDox('lancamento positivo aumenta o saldo anual')]
    public function testLancamentoPositivoAumentaSaldoAnual(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => 480]]);

        $saldo = $builder->calcularSaldoAnual(
            $this->userComJornada(),
            2026,
            [],
            null,
            new \DateTimeImmutable('2026-01-01'),
            $this->tenant(),
        );

        self::assertSame(480, $saldo);
    }

    #[TestDox('lancamento de competencia ANTERIOR ao inicio da contagem ainda conta')]
    public function testLancamentoAnteriorAoInicioDaContagemAindaConta(): void
    {
        // início da contagem em dezembro, lançamento em janeiro do mesmo ano: a varredura mensal
        // nunca passaria por janeiro. O lançamento não pode sumir por isso.
        $builder = $this->builderComLancamentos([2026 => [1 => -300]]);

        $saldo = $builder->calcularSaldoAnual(
            $this->userComJornada(),
            2026,
            [],
            null,
            new \DateTimeImmutable('2026-12-01'),
            $this->tenant(),
        );

        self::assertSame(-300, $saldo, 'horas pagas fora da janela de contagem não podem evaporar');
    }

    #[TestDox('colaborador SEM jornada configurada ainda recebe o lancamento')]
    public function testColaboradorSemJornadaAindaRecebeLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [3 => -120]]);

        $userSemJornada = $this->createStub(User::class);
        $userSemJornada->method('getJornadaColaborador')->willReturn(null);

        $saldo = $builder->calcularSaldoAnual($userSemJornada, 2026, [], null, new \DateTimeImmutable('2026-01-01'), $this->tenant());

        self::assertSame(-120, $saldo, 'sem jornada o saldo é 0, mas o lançamento manual continua valendo');
    }

    #[TestDox('colaborador SEM nenhuma batida ainda recebe o lancamento')]
    public function testColaboradorSemBatidaAindaRecebeLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [3 => 240]]);

        // inicioContagem null = colaborador sem nenhum registro de ponto
        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, null, $this->tenant());

        self::assertSame(240, $saldo);
    }

    #[TestDox('dois lancamentos na mesma competencia somam')]
    public function testDoisLancamentosNaMesmaCompetenciaSomam(): void
    {
        // o repositório já devolve a soma; aqui o contrato é: o builder usa o valor como veio
        $builder = $this->builderComLancamentos([2026 => [1 => -6000 + 480]]);

        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, new \DateTimeImmutable('2026-01-01'), $this->tenant());

        self::assertSame(-5520, $saldo);
    }

    #[TestDox('calcularSaldoAteMes inclui os meses ate o pedido e exclui os posteriores')]
    public function testSaldoAteMesRespeitaOCorte(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => -100, 2 => -200, 5 => -400]]);

        $saldo = $builder->calcularSaldoAteMes(
            $this->userComJornada(),
            2026,
            2,
            [],
            null,
            new \DateTimeImmutable('2026-01-01'),
            $this->tenant(),
        );

        self::assertSame(-300, $saldo, 'maio não pode entrar num saldo até fevereiro');
    }

    #[TestDox('buildRows NAO e afetado pelo lancamento')]
    public function testBuildRowsNaoEAfetado(): void
    {
        $builder = $this->builderComLancamentos([2026 => [4 => -6000]]);
        $jornada = $this->jornadaSimples();

        $rows = $builder->buildRows(
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-30'),
            [],
            true,
            false,
            $jornada,
            [],
            [],
            null,
            new \DateTimeImmutable('2020-01-01'),
        );

        foreach ($rows as $row) {
            self::assertNotSame(-6000, $row['saldoDia'], 'horas pagas nunca entram numa linha de dia');
        }
    }

    #[TestDox('sem lancamento nenhum o saldo fica identico ao comportamento antigo')]
    public function testSemLancamentoSaldoNaoMuda(): void
    {
        $builder = $this->builderComLancamentos([]);

        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, null, $this->tenant());

        self::assertSame(0, $saldo);
    }

    #[TestDox('sem tenant informado o lancamento e ignorado, sem quebrar')]
    public function testSemTenantIgnoraLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => -600]]);

        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, null, null);

        self::assertSame(0, $saldo, 'sem tenant não há como filtrar com segurança: não soma');
    }

    /**
     * @param array<int, array<int, int>> $porAnoMes minutos indexados por [ano][mês]
     */
    private function builderComLancamentos(array $porAnoMes): FolhaPontoBuilder
    {
        $repo = $this->createStub(LancamentoHorasPagasRepository::class);
        $repo->method('somarPorCompetencia')->willReturnCallback(
            static fn (User $u, Tenant $t, int $ano, int $mes): int => $porAnoMes[$ano][$mes] ?? 0
        );

        return new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $repo,
        );
    }

    private function userComJornada(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getJornadaColaborador')->willReturn($this->jornadaSimples());

        return $user;
    }

    private function tenant(): Tenant
    {
        return $this->createStub(Tenant::class);
    }

    private function jornadaSimples(): JornadaColaborador
    {
        // Copiar o helper homônimo de FolhaPontoBuilderTest, para os dois arquivos ficarem
        // independentes. Se ele já estiver em um trait compartilhado, use o trait.
        $jornada = new JornadaColaborador();
        $jornada->setCargaHorariaSemanal(2400);

        return $jornada;
    }
}
```

> `jornadaSimples()` deve ser copiado **verbatim** do método privado de mesmo nome em
> `app/tests/Ponto/Unit/FolhaPontoBuilderTest.php` (final do arquivo). Leia-o antes de escrever;
> a versão acima é ilustrativa e provavelmente incompleta.

- [ ] **Passo 2: Rodar e confirmar que falha**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter FolhaPontoBuilderHorasPagasTest'
```

Esperado: FALHA — o construtor de `FolhaPontoBuilder` ainda tem 3 argumentos.

- [ ] **Passo 3: Injetar o repositório no construtor**

Em `app/src/Ponto/Service/FolhaPontoBuilder.php`, linhas 16–20:

```php
    public function __construct(
        private readonly CalculadoraJornada $calculadora,
        private readonly RegistroPontoRepository $registroPontoRepository,
        private readonly JustificativaPontoRepository $justificativaPontoRepository,
        private readonly LancamentoHorasPagasRepository $lancamentoHorasPagasRepository,
    ) {}
```

Adicionar o `use App\Ponto\Repository\LancamentoHorasPagasRepository;` no topo, e
`use App\Entity\Tenant\Tenant;`.

Corrigir o `setUp()` de `app/tests/Ponto/Unit/FolhaPontoBuilderTest.php` (linhas 27–31) para passar um quarto
stub — senão a suíte existente quebra:

```php
        $this->builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $this->createStub(LancamentoHorasPagasRepository::class),
        );
```

- [ ] **Passo 4: Somar os lançamentos nos dois agregadores**

Regra que os dois seguem, e o motivo de cada detalhe:

1. O parâmetro `?Tenant $tenant = null` entra **por último**, com default, para não quebrar os chamadores
   existentes na hora da compilação.
2. **Sem tenant não soma nada.** Filtrar lançamento sem tenant seria vazamento entre escritórios — pior do
   que não somar. A Tarefa 4 obriga todos os chamadores a passarem o tenant.
3. A soma acontece **antes de qualquer `return 0` antecipado** (sem jornada, sem início de contagem,
   `$inicio > $fim`). Perder horas em silêncio é o pior desfecho possível aqui.
4. A varredura de lançamentos é **independente** da varredura de meses do saldo: vai de janeiro ao mês-limite,
   não do início da contagem.

Em `calcularSaldoAnual` (linha 329), a assinatura passa a ser:

```php
    public function calcularSaldoAnual(
        User $user,
        int $ano,
        array $feriados,
        ?JornadaTenant $jornadaTenant = null,
        \DateTimeInterface|null|false $inicioContagem = false,
        ?Tenant $tenant = null,
    ): int {
        $this->exigirInicioContagem($inicioContagem, __FUNCTION__);

        // Horas pagas somam SEMPRE, antes de qualquer saída antecipada: um lançamento numa competência
        // anterior à primeira batida, ou de colaborador sem jornada, continua valendo.
        $horasPagas = $this->somarHorasPagasDoPeriodo($user, $tenant, $ano, 1, 12);

        $jornada = $user->getJornadaColaborador();
        if ($jornada === null) {
            return $horasPagas;
        }
        // ... (resto do método inalterado, EXCETO os returns abaixo)
```

Trocar, dentro de `calcularSaldoAnual`:
- linha 342 `return 0;` → `return $horasPagas;`
- linha 354 `return 0;` → `return $horasPagas;`
- linha 402 `return $saldoTotal;` → `return $saldoTotal + $horasPagas;`

Em `calcularSaldoAteMes` (linha 244), o mesmo, com o limite no mês pedido:

```php
    public function calcularSaldoAteMes(
        User $user,
        int $ano,
        int $mes,
        array $feriados,
        ?JornadaTenant $jornadaTenant = null,
        \DateTimeInterface|null|false $inicioContagem = false,
        ?Tenant $tenant = null,
    ): int {
        $this->exigirInicioContagem($inicioContagem, __FUNCTION__);

        $horasPagas = $this->somarHorasPagasDoPeriodo($user, $tenant, $ano, 1, $mes);
        // ...
```

Trocar, dentro de `calcularSaldoAteMes`:
- linha 250 `return 0;` → `return $horasPagas;`
- linha 257 `return 0;` → `return $horasPagas;`
- linha 269 `return 0;` → `return $horasPagas;`
- linha 317 `return $saldoTotal;` → `return $saldoTotal + $horasPagas;`

E acrescentar os dois métodos ao final da classe:

```php
    /**
     * Soma, com sinal, os lançamentos de horas pagas do colaborador numa competência.
     * Público porque as telas precisam exibir a linha "Horas pagas" do mês exibido.
     */
    public function somarHorasPagasDaCompetencia(User $user, ?Tenant $tenant, int $ano, int $mes): int
    {
        if ($tenant === null) {
            return 0;
        }

        return $this->lancamentoHorasPagasRepository->somarPorCompetencia($user, $tenant, $ano, $mes);
    }

    /**
     * Soma os lançamentos de um intervalo de meses do mesmo ano (inclusive nas duas pontas).
     */
    private function somarHorasPagasDoPeriodo(User $user, ?Tenant $tenant, int $ano, int $mesInicial, int $mesFinal): int
    {
        if ($tenant === null) {
            return 0;
        }

        $total = 0;
        for ($mes = $mesInicial; $mes <= $mesFinal; $mes++) {
            $total += $this->lancamentoHorasPagasRepository->somarPorCompetencia($user, $tenant, $ano, $mes);
        }

        return $total;
    }
```

- [ ] **Passo 5: Rodar os dois arquivos de teste do builder**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Ponto/Unit'
```

Esperado: `FolhaPontoBuilderHorasPagasTest` verde **e** `FolhaPontoBuilderTest` (21 testes) continuando verde.

- [ ] **Passo 6: PROVA DOS TESTES por injeção de defeito**

Obrigatório nesta tarefa. Para **cada** um dos 5 defeitos abaixo: aplique, rode, confirme que o teste indicado
**falha**, e desfaça antes do próximo.

| Defeito a introduzir | Teste que TEM de falhar |
|---|---|
| `return $horasPagas;` da linha 342 volta a `return 0;` | `testColaboradorSemBatidaAindaRecebeLancamento` |
| `return $horasPagas;` do "sem jornada" volta a `return 0;` | `testColaboradorSemJornadaAindaRecebeLancamento` |
| `$saldoTotal + $horasPagas` volta a `$saldoTotal` em `calcularSaldoAnual` | `testLancamentoNegativoReduzSaldoAnual` |
| `somarHorasPagasDoPeriodo` de `calcularSaldoAteMes` recebe `12` em vez de `$mes` | `testSaldoAteMesRespeitaOCorte` |
| `somarHorasPagasDaCompetencia` retorna a soma mesmo com `$tenant === null` | `testSemTenantIgnoraLancamento` |

Se algum defeito **não** derrubar seu teste, o teste está mentindo — conserte o teste antes de seguir.
Registre no commit quais defeitos foram provados.

- [ ] **Passo 7: Commit**

```bash
git add app/src/Ponto/Service/FolhaPontoBuilder.php \
        app/tests/Ponto/Unit/FolhaPontoBuilderHorasPagasTest.php \
        app/tests/Ponto/Unit/FolhaPontoBuilderTest.php
git commit -m "soma as horas pagas no saldo do banco de horas"
```

---

### Tarefa 4: controller, formulário e rotas

**Arquivos:**
- Criar: `app/src/Ponto/Form/LancamentoHorasPagasType.php`
- Criar: `app/src/Ponto/Controller/HorasPagasController.php`
- Criar: `app/tests/Ponto/Functional/HorasPagasControllerTest.php`
- Modificar: `app/src/Ponto/Controller/PontoController.php:140` e `:998` (passar o tenant)
- Modificar: `app/src/Controller/TenantController.php:566` (passar o tenant, se chamar agregador)

**Interfaces:**
- Consome: os três UseCases e `LancamentoHorasPagasInput` (Tarefa 2); `buscarDoTenant` (Tarefa 1).
- Produz: rotas `ponto_horas_pagas_lancar`, `ponto_horas_pagas_editar`, `ponto_horas_pagas_excluir`.
- Produz: intenções CSRF `horas_pagas_lancar_<userId>`, `horas_pagas_editar_<lancamentoId>`,
  `horas_pagas_excluir_<lancamentoId>`.

- [ ] **Passo 1: Escrever os testes funcionais que falham**

Criar `app/tests/Ponto/Functional/HorasPagasControllerTest.php`. Espelhe **exatamente** o padrão de
`app/tests/Ponto/Functional/PontoManualCsrfControllerTest.php` — mesma classe base
`App\Tests\Functional\JusPrimeWebTestCase`, mesmos helpers (`criarTenant`, `criarAdmin`, `criarUsuario`,
`logarComTenant`, `instalarCsrfStorage`) e a mesma convenção de token `TOKEN_<intencao>`.

> **Leia `PontoManualCsrfControllerTest.php` inteiro antes de escrever.** `instalarCsrfStorage()` existe
> porque o helper antigo de token não salvava a sessão — sem ele, **todo teste de recusa passa por engano**,
> inclusive os de CSRF. Se seu teste "sem token" passar de primeira sem `instalarCsrfStorage`, desconfie.

Casos obrigatórios:

```php
    #[TestDox('colaborador sem admin.users.manage recebe 403 e nada e gravado')]
    public function testSemPermissaoRetorna403(): void { /* ... */ }

    #[TestDox('POST sem token CSRF retorna 403, com a mensagem, e nada e gravado')]
    public function testSemCsrfRetorna403(): void { /* asserir a MENSAGEM, não só o status */ }

    #[TestDox('admin com permissao lanca e o registro nasce com autoria correta')]
    public function testLancamentoValidoGrava(): void { /* checar criadoPor, criadoEm, minutos com sinal */ }

    #[TestDox('admin do tenant A lancando para colaborador do tenant B recebe 403 e nada e gravado')]
    public function testCrossTenantBloqueado(): void { /* ... */ }

    #[TestDox('admin nao lanca horas pagas para si mesmo')]
    public function testAutoLancamentoBloqueado(): void { /* flash de erro, nada gravado */ }

    #[TestDox('excluir remove o lancamento')]
    public function testExclusaoRemove(): void { /* ... */ }
```

Toda asserção de recusa precisa de **duas** partes: o status/mensagem **e** a contagem de registros
inalterada. Status 403 com o dado gravado é o defeito clássico aqui.

- [ ] **Passo 2: Rodar e confirmar que falha**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter HorasPagasControllerTest'
```

Esperado: FALHA com 404 nas rotas (ainda não existem).

- [ ] **Passo 3: Criar o Form**

`app/src/Ponto/Form/LancamentoHorasPagasType.php`. `csrf_protection => false` — o token é validado
explicitamente no controller, por intenção, como em `RegistroPontoManualType`.

```php
<?php

declare(strict_types=1);

namespace App\Ponto\Form;

use App\Ponto\DTO\LancamentoHorasPagasInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LancamentoHorasPagasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mes', ChoiceType::class, [
                'label'   => 'Competência (mês)',
                'choices' => [
                    'Janeiro' => 1, 'Fevereiro' => 2, 'Março' => 3, 'Abril' => 4,
                    'Maio' => 5, 'Junho' => 6, 'Julho' => 7, 'Agosto' => 8,
                    'Setembro' => 9, 'Outubro' => 10, 'Novembro' => 11, 'Dezembro' => 12,
                ],
            ])
            ->add('ano', IntegerType::class, ['label' => 'Ano'])
            ->add('operacao', ChoiceType::class, [
                'label'    => 'Operação',
                'expanded' => true,
                'choices'  => [
                    'Descontar do banco'  => LancamentoHorasPagasInput::OPERACAO_DESCONTAR,
                    'Acrescentar ao banco' => LancamentoHorasPagasInput::OPERACAO_ACRESCENTAR,
                ],
            ])
            ->add('horas', IntegerType::class, ['label' => 'Horas'])
            ->add('minutos', IntegerType::class, ['label' => 'Minutos'])
            ->add('motivo', TextareaType::class, ['label' => 'Motivo']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => LancamentoHorasPagasInput::class,
            'csrf_protection' => false,
        ]);
    }
}
```

- [ ] **Passo 4: Criar o Controller**

`app/src/Ponto/Controller/HorasPagasController.php`. A guarda de permissão é **cópia literal** do bloco de
`app/src/Controller/TenantController.php:332-336` — não invente variação. O `escoparFiltroNoTenant()` de
`TenantController.php:448` precisa do equivalente aqui: leia aquele método e replique o comportamento
(reapontar o filtro Doctrine para o tenant da URL) antes de qualquer query.

Esqueleto das três ações (a de lançar; editar e excluir seguem o mesmo formato):

```php
    #[Route('/{tenantId}/users/{id}/horas-pagas', name: 'ponto_horas_pagas_lancar', methods: ['POST'])]
    public function lancar(int $tenantId, int $id, Request $request): Response
    {
        // 1. resolver tenant e colaborador; 404 se não existirem
        // 2. guarda de permissão (cópia de TenantController.php:332-336)
        // 3. escoparFiltroNoTenant($tenant)
        // 4. colaborador PRECISA ter vínculo ativo com ESTE tenant — senão AccessDenied (IDOR)
        // 5. isCsrfTokenValid('horas_pagas_lancar_' . $id, $request->request->get('_token')) — senão 403
        // 6. montar o form com LancamentoHorasPagasInput, handleRequest
        // 7. try { ($this->lancar)($input, $colaborador, $this->getUser(), $tenant); addFlash('success', ...) }
        //    catch (HorasPagasInvalidaException $e) { addFlash('error', $e->getMessage()); }
        // 8. redirect para app_tenant_user_edit_role com ?tab=horas-pagas
    }
```

Ponto de atenção do passo 4 do checklist acima: **a validação de vínculo do colaborador com o tenant é a
única coisa que separa esta rota de um IDOR.** Sem ela, `/tenant/1/users/999/horas-pagas` alcança o
funcionário do escritório vizinho. Use `$userTenantRepository->existeVinculoAtivo($colaborador, $tenant)`.

- [ ] **Passo 5: Passar o tenant nos chamadores dos agregadores**

Os três lugares que hoje calculam saldo precisam informar o tenant, senão a Tarefa 3 fica inerte
(`$tenant === null` → não soma):

```bash
docker exec jusprime_php_dev bash -c 'cd app && grep -rn "calcularSaldoAnual\|calcularSaldoAteMes" src/ | grep -v "Service/FolhaPontoBuilder.php"'
```

Esperado: 3 ocorrências (`PontoController.php:140`, `PontoController.php:998`, e o chamador em
`TenantController.php` se houver). Acrescentar o tenant como último argumento em **todas**. Se o grep
devolver mais de 3, trate todas — nenhuma pode ficar para trás.

- [ ] **Passo 6: Conferir as rotas e rodar os testes**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console debug:router | grep horas_pagas'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter HorasPagasControllerTest'
```

Esperado: 3 rotas listadas; testes verdes.

- [ ] **Passo 7: Commit**

```bash
git add app/src/Ponto/Controller/HorasPagasController.php \
        app/src/Ponto/Form/LancamentoHorasPagasType.php \
        app/src/Ponto/Controller/PontoController.php \
        app/src/Controller/TenantController.php \
        app/tests/Ponto/Functional/HorasPagasControllerTest.php
git commit -m "expoe as rotas de horas pagas com permissao, CSRF e guarda de tenant"
```

---

### Tarefa 5: tela do administrador

**Arquivos:**
- Criar: `app/templates/tenant/_horas_pagas_tab.html.twig`
- Modificar: `app/templates/tenant/edit_user_role.html.twig`
- Modificar: `app/src/Controller/TenantController.php` (por volta de `:589-607`, onde a ficha já monta
  `folhaRowsPonto`, `justificativas`, `tiposJustificativa`, `competenciasPonto`)

**Interfaces:**
- Consome: `LancamentoHorasPagasRepository::listarPorUser()` (Tarefa 1); as 3 rotas (Tarefa 4).
- Produz: variável Twig `lancamentosHorasPagas` (array de `LancamentoHorasPagas`) e a aba `?tab=horas-pagas`.

- [ ] **Passo 1: Passar os lançamentos para a ficha**

Em `TenantController::editUserRole()`, junto das variáveis já montadas para o template, acrescentar:

```php
            'lancamentosHorasPagas' => $lancamentoHorasPagasRepository->listarPorUser($targetUser, $tenant),
```

- [ ] **Passo 2: Criar o parcial da aba**

`app/templates/tenant/_horas_pagas_tab.html.twig`: tabela com Competência (`MM/YYYY`), Valor (com sinal e
cor: `text-danger` quando negativo, `text-success` quando positivo), Motivo, Lançado por, Quando, e as ações
editar/excluir. Cada ação é um `<form method="post">` com o `_token` da intenção correspondente.

Formatação do valor, no padrão já usado em `_folha_table.html.twig:208-215`:

```twig
{% set abs = lancamento.minutos < 0 ? -lancamento.minutos : lancamento.minutos %}
<span class="{{ lancamento.minutos < 0 ? 'text-danger' : 'text-success' }}">
    {{ lancamento.minutos < 0 ? '-' : '+' }}{{ (abs // 60) }}h{{ '%02d'|format(abs % 60) }}m
</span>
```

Estado vazio: `Nenhum lançamento de horas pagas para este colaborador.`

- [ ] **Passo 3: Adicionar o botão e o modal na ficha**

Em `edit_user_role.html.twig`, ao lado dos botões "Adicionar Batida" e "Nova Justificativa": botão
`Horas pagas` abrindo um modal com o formulário. Copie a estrutura do modal de "Adicionar Batida" que já
está no arquivo, inclusive como ele injeta o `_token`.

- [ ] **Passo 4: Lint e conferência**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console lint:twig templates'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Ponto tests/Tenant'
```

Esperado: lint limpo, testes verdes.

- [ ] **Passo 5: Commit**

```bash
git add app/templates/tenant/ app/src/Controller/TenantController.php
git commit -m "poe a aba de horas pagas na ficha do funcionario"
```

---

### Tarefa 6: folha do colaborador (web, PDF e XLSX)

**Arquivos:**
- Modificar: `app/src/Ponto/Controller/PontoController.php` (`montarDadosFolha()`, linha 907)
- Modificar: `app/templates/ponto/_folha_table.html.twig` (rodapé, logo após o `</table>`)
- Modificar: `app/templates/ponto/folha_pdf.html.twig`
- Modificar: `app/src/Ponto/Service/FolhaPontoXlsxExporter.php`

**Interfaces:**
- Consome: `FolhaPontoBuilder::somarHorasPagasDaCompetencia()` (Tarefa 3).
- Produz: chave `horasPagasMinutos` (int) no array de `montarDadosFolha()` e a variável Twig homônima.

- [ ] **Passo 1: Expor o valor da competência**

Em `montarDadosFolha()`, junto de `$saldoBancoAtualMinutos` (linha 945/986-988), acrescentar:

```php
        $horasPagasMinutos = $builder->somarHorasPagasDaCompetencia($targetUser, $tenant, $ano, $mes);
```

e incluir `'horasPagasMinutos' => $horasPagasMinutos,` no array de retorno.

> `saldoBancoAnteriorMinutos` (linha 997) **já inclui** os lançamentos de meses anteriores, porque vem de
> `calcularSaldoAteMes`, alterado na Tarefa 3. Não somar de novo — seria contar duas vezes.

O `saldoBancoAtualMinutos` (linha 987) vem do `saldoAcumulado` da última linha e **não** inclui as horas
pagas do mês exibido. Somar explicitamente ao montar o total final, ou exibir as duas linhas separadas
conforme a spec §7. Escolha a segunda: é o que a spec desenha.

- [ ] **Passo 2: Rodapé na tabela da web**

Em `_folha_table.html.twig`, logo depois do `</table>` que fecha a folha, um bloco condicional:

```twig
{% if horasPagasMinutos is defined and horasPagasMinutos != 0 %}
    {% set absHp = horasPagasMinutos < 0 ? -horasPagasMinutos : horasPagasMinutos %}
    <div class="d-flex justify-content-end gap-3 px-3 py-2 border-top small">
        <span class="text-muted">Horas pagas</span>
        <span class="fw-semibold {{ horasPagasMinutos < 0 ? 'text-danger' : 'text-success' }}">
            {{ horasPagasMinutos < 0 ? '-' : '+' }}{{ (absHp // 60) }}h{{ '%02d'|format(absHp % 60) }}m
        </span>
    </div>
{% endif %}
```

Regra literal: **a linha só aparece quando há lançamento na competência.** Mês sem lançamento fica idêntico
ao de hoje. Sem motivo, sem autor.

- [ ] **Passo 3: Mesma linha no PDF e no XLSX**

`folha_pdf.html.twig`: mesma condicional, no bloco de totais existente.
`FolhaPontoXlsxExporter.php`: mesma linha na área de totais. Localize onde `saldoBancoAtualMinutos` é escrito
e insira "Horas pagas" imediatamente acima do saldo final.

- [ ] **Passo 4: Conferir os três caminhos**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console lint:twig templates'
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Ponto'
```

- [ ] **Passo 5: Commit**

```bash
git add app/src/Ponto/Controller/PontoController.php \
        app/src/Ponto/Service/FolhaPontoXlsxExporter.php \
        app/templates/ponto/
git commit -m "mostra a linha de horas pagas na folha, no PDF e no XLSX"
```

---

### Tarefa 7: fechamento — suíte completa e revisão adversarial

- [ ] **Passo 1: Suíte completa**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'
```

Esperado: **zero falhas e zero deprecations**. A suíte roda com `failOnDeprecation/Notice/Warning`: um
deprecation derruba tudo. Anote o total (`XXXX/XXXX`).

- [ ] **Passo 2: Sanidade de schema e container**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:validate'
docker exec jusprime_php_dev bash -c 'cd app && php bin/console lint:container'
```

- [ ] **Passo 3: Revisão adversarial (obrigatória — risco ALTO)**

Rode `/review` apontando para a spec `docs/specs/ponto-horas-pagas-banco-de-horas.md` e o diff completo da
branch. **Não confie na auto-delegação** — dispare o comando.

A revisão é read-only e só aponta furos; quem corrige é o orquestrador. Pontos que o revisor deve atacar,
por serem os que a suíte verde não pega:

1. Algum chamador de `calcularSaldoAnual`/`calcularSaldoAteMes` ficou sem passar o tenant? (lançamento inerte)
2. As horas pagas do mês exibido são contadas **duas vezes** em algum total do PDF/XLSX?
3. Existe caminho em que um lançamento não aparece em nenhum saldo? (hora sumindo em silêncio)
4. Alguma rota nova alcança colaborador de outro escritório?
5. O motivo vaza para alguma tela, PDF ou planilha do colaborador?
6. Os testes de recusa asseguram também que **nada foi gravado**, ou só o status?

- [ ] **Passo 4: Corrigir os achados e re-revisar**

Risco ALTO exige **re-revisão** após as correções. Corrija tudo, rode a suíte de novo, dispare `/review`
outra vez.

- [ ] **Passo 5: Commit final e entrega**

```bash
git add -A
git commit -m "fecha a frente de horas pagas apos revisao"
```

Depois, escreva para o dono:

- o total da suíte (`XXXX/XXXX`);
- que existe **uma migration** a aplicar (`ponto_lancamento_horas_pagas`) e que aplicá-la em produção é dele;
- **o que ele precisa olhar na tela** (o smoke é dele, não abra o navegador):
  1. ficha do funcionário → aba Ponto → botão "Horas pagas" → lançar `-100h` em agosto com motivo;
  2. a lista abaixo mostra o lançamento em vermelho, com motivo e autoria;
  3. entrar como o funcionário → `/ponto` → o card "Banco de horas" caiu 100h;
  4. a folha de agosto mostra a linha `Horas pagas -100h00`, **sem** o motivo;
  5. exportar PDF e XLSX de agosto e conferir a mesma linha;
  6. tentar lançar para si mesmo → tem de recusar com mensagem;
  7. um mês sem lançamento não pode exibir a linha.
- o comando de push, para ele executar:

```bash
# Execute manualmente no terminal externo
git push -u origin ponto-horas-pagas
```

---

## Autorrevisão do plano

**Cobertura da spec:** §3 modelo de dados → Tarefa 1. §4 cálculo → Tarefa 3. §5 autorização → Tarefa 4.
§6 camadas → Tarefas 1, 2 e 4. §7 interface admin → Tarefa 5; interface colaborador → Tarefa 6. §8 testes →
distribuídos (Unit 1–8 na Tarefa 3, Unit 9–14 na Tarefa 2, Functional 15–19 na Tarefa 4). §9 migration →
Tarefa 1, passos 5–7. §2 mitigação de audit_log → Tarefa 1, via `implements Auditavel`.

**Divergência conhecida e deliberada:** a spec §4.2 afirma que nenhuma assinatura pública muda. O plano
acrescenta `?Tenant $tenant = null` aos dois agregadores — sem o tenant não há como filtrar o lançamento sem
vazar entre escritórios. O parâmetro é opcional e, quando ausente, o lançamento é ignorado (nunca vaza),
com teste próprio (`testSemTenantIgnoraLancamento`). Em troca, a Tarefa 4 passo 5 obriga a atualização de
todos os chamadores, e a revisão da Tarefa 7 checa isso explicitamente. **Atualizar a spec §4.2 ao fim da
Tarefa 3**, para o documento não mentir.

**Placeholders:** as Tarefas 4 (controller), 5 e 6 descrevem trechos de template e controller por âncora e
regra, não por código completo — porque dependem de estruturas Twig existentes que precisam ser lidas no
arquivo. Cada uma diz qual arquivo copiar como modelo e qual invariante precisa valer.

**Consistência de tipos:** `somarPorCompetencia(User, Tenant, int, int): int` é usado com essa mesma ordem na
Tarefa 3. `somarHorasPagasDaCompetencia(User, ?Tenant, int, int): int` é o mesmo nome na Tarefa 3 (definição)
e na Tarefa 6 (uso). `minutosComSinal(): int` idem nas Tarefas 2 e 3. `buscarDoTenant(int, Tenant)` definido
na Tarefa 1, usado na Tarefa 4.
