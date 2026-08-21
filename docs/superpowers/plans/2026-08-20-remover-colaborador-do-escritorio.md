# Remover Colaborador do Escritório — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir a demissão por uma ação única — "Remover do escritório" — que **apaga** a linha de `user_tenant`, transfere ou desatribui as responsabilidades da pessoa e revoga os acessos dela naquele escritório, preservando ponto, justificativas e auditoria.

**Architecture:** Um UseCase único (`RemoverColaboradorDoEscritorioUseCase`) atende as **duas** portas — o painel do admin e a saída por conta própria — distinguidas por um enum `OrigemRemocao`. Ele valida as travas, passa o bastão das responsabilidades, apaga os acessos, grava o registro de auditoria **com o tenant vindo da rota** e só então remove o vínculo. Tudo em uma transação. As queries de limpeza usam SQL/DQL, que **não herdam o TenantFilter** — por isso todas carregam `WHERE tenant` explícito.

**Tech Stack:** PHP 8.2, Symfony 7.4, Doctrine ORM 3.x, PostgreSQL 15, Twig, PHPUnit 11.

**Spec:** `docs/specs/remover-colaborador-do-escritorio.md` — leia antes de começar. O plano argumenta a partir dela.

## Global Constraints

- **Idioma:** código, comentários e commits em **português brasileiro**. `camelCase` métodos/variáveis · `PascalCase` classes · `snake_case` rotas/colunas.
- **Frente isolada:** worktree `.claude/worktrees/desvincular-colaborador`, branch `desvincular-colaborador`, base `origin/master` @ `19cfd9a9`, banco de teste `saas_testdesvincular-colaborador`.
- **Rodar os testes** a partir da **raiz do repositório principal**: `bash scripts/frente-testar.sh desvincular-colaborador [args]`. ⚠️ O script repassa os args **sem aspas** para o `bash -c` do container: um `--filter` com `|` quebra com *command not found*. Use um filtro por vez.
- **PHPUnit roda com `failOnDeprecation/Notice/Warning`** — um deprecation derruba a suíte.
- **Nenhuma query em massa herda o `TenantFilter`.** Todo `DELETE`/`UPDATE`/subselect desta frente carrega `tenant_id` explícito. Sem isso, remover alguém de um escritório apaga dado de outro.
- **Padrão B-route:** `tenantId` vem da rota, o lookup é por repositório, **nunca** `getCurrentTenant()`.
- **Git:** commits locais são permitidos. `push`/`merge`/`rebase`/`reset` são do humano — nunca execute.
- **Risco ALTO** (identidade `User`/`Tenant`): ao terminar, a frente exige `/review` e re-revisão após as correções.

---

## Estrutura de arquivos

**Nascem**

| Arquivo | Responsabilidade |
|---|---|
| `app/src/Tenant/DTO/OrigemRemocao.php` | enum: `Painel` \| `Saida` — distingue as duas portas |
| `app/src/Tenant/DTO/RemoverColaboradorInput.php` | entrada do UseCase |
| `app/src/Tenant/UseCase/RemoverColaboradorDoEscritorioUseCase.php` | a ação inteira |
| `app/migrations/Version2026XXXXXXXXXX.php` | apaga vínculos inativos + dropa `demitido_em` |
| `app/tests/Tenant/Unit/RemoverColaboradorDoEscritorioUseCaseTest.php` | unit |
| `app/tests/Tenant/Functional/RemoverColaboradorControllerTest.php` | funcional |
| `app/tests/Tenant/Functional/RemoverColaboradorIsolamentoTest.php` | isolamento entre escritórios |
| `app/tests/Tenant/Functional/ReconvitePosRemocaoTest.php` | o teste que fecha o pedido do dono |

**Mudam**

| Arquivo | O quê |
|---|---|
| `app/src/Repository/UserTenantRepository.php` | ganha `findAdminAtivoMaisAntigo()` |
| `app/src/Repository/UserRepository.php` | `findAuditFilterOptions()` passa a incluir quem só existe no `audit_log` |
| `app/src/Controller/TenantController.php` | rota nova `app_tenant_user_remover`; sai `demitirFuncionario`; `listUsers` filtra ativos |
| `app/src/Tenant/UseCase/SairDoEscritorioUseCase.php` | delega ao UseCase novo |
| `app/src/Entity/Auth/UserTenant.php` | saem `demitir()`, `sair()` e a propriedade `demitidoEm` |
| `app/templates/tenant/edit_user_role.html.twig` | o bloco de demissão vira "Remover do escritório" |
| `app/templates/tenant/users.html.twig` | sai a seção "Colaboradores desligados" |

**Morrem**

`app/src/Tenant/UseCase/DemitirFuncionarioUseCase.php` · `app/src/Tenant/DTO/DemitirFuncionarioInput.php` · `app/tests/Tenant/Unit/DemitirFuncionarioUseCaseTest.php` · `app/tests/Tenant/Functional/DemitirFuncionarioControllerTest.php` · `app/tests/Tenant/Functional/DemitirFuncionarioIsolamentoTest.php`

---

### Task 1: O UseCase — travas, auditoria e a deleção do vínculo

Entrega a espinha: valida, grava o rastro, apaga a linha. Responsabilidades e acessos entram nas Tasks 2 e 3.

**Files:**
- Create: `app/src/Tenant/DTO/OrigemRemocao.php`, `app/src/Tenant/DTO/RemoverColaboradorInput.php`, `app/src/Tenant/UseCase/RemoverColaboradorDoEscritorioUseCase.php`
- Modify: `app/src/Repository/UserTenantRepository.php`
- Test: `app/tests/Tenant/Unit/RemoverColaboradorDoEscritorioUseCaseTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `RemoverColaboradorDoEscritorioUseCase::executar(RemoverColaboradorInput $input): void` · `RemoverColaboradorInput(User $executor, User $colaborador, Tenant $tenant, ?User $substituto = null, OrigemRemocao $origem = OrigemRemocao::Painel)` · `UserTenantRepository::findAdminAtivoMaisAntigo(Tenant $tenant, User $exceto): ?UserTenant`

**Decisão do dono (20/08), quando perguntado se valia promover alguém em vez de travar:** fica a **trava**. Promover não substituiria a trava — quando a pessoa é a única do escritório não há ninguém para promover, então a trava teria que existir de todo jeito e a promoção seria código a mais. Além disso, promover é o sistema conceder Administrador Master sozinho, por antiguidade: elevação silenciosa de privilégio num sistema onde permissão é auditada. O admin que quer sair promove alguém antes — dois cliques, e a escolha continua sendo de uma pessoa.

**Decisão de plano** (a spec §4 não distingue as portas): a trava "não remover o criador do escritório" vale **só na porta do painel**. Quem fundou o escritório pode sair por conta própria — quem o impede de esvaziar a casa é a trava do último admin. É o comportamento de hoje do `sair`, preservado.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoverColaboradorDoEscritorioUseCase::class)]
final class RemoverColaboradorDoEscritorioUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject $conn;
    private UserTenantRepository&MockObject $userTenantRepository;
    private RemoverColaboradorDoEscritorioUseCase $useCase;

    protected function setUp(): void
    {
        $this->em                   = $this->createMock(EntityManagerInterface::class);
        $this->conn                 = $this->createMock(Connection::class);
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->em->method('getConnection')->willReturn($this->conn);
        $this->useCase = new RemoverColaboradorDoEscritorioUseCase($this->em, $this->userTenantRepository);
    }

    private function criarUsuario(string $nome = 'Teste'): User
    {
        $user = new User();
        $user->setEmail(uniqid() . '@test.com');
        $user->setFullName($nome);

        return $user;
    }

    private function criarVinculoAdmin(User $user, Tenant $tenant): UserTenant
    {
        $role = new TenantRole();
        $role->setName('Administrador Master');
        $role->setIsSystem(true);

        return (new UserTenant($user, $tenant))->setTenantRole($role);
    }

    #[TestDox('apaga o vinculo do colaborador removido')]
    public function testApagaOVinculo(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Colaborador');
        $vinculo     = new UserTenant($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->em->expects($this->once())->method('remove')->with($vinculo);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));
    }

    #[TestDox('recusa remover o ultimo administrador ativo')]
    public function testRecusaRemoverOUltimoAdmin(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Unico Admin');
        $vinculo     = $this->criarVinculoAdmin($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(1);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/último administrador/i');

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));
    }

    #[TestDox('recusa o admin remover a si mesmo pelo painel')]
    public function testRecusaRemoverASiMesmoPeloPainel(): void
    {
        $tenant  = new Tenant();
        $pessoa  = $this->criarUsuario('Eu');
        $vinculo = new UserTenant($pessoa, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new RemoverColaboradorInput($pessoa, $pessoa, $tenant));
    }

    #[TestDox('permite a propria pessoa sair quando a origem e a saida voluntaria')]
    public function testPermiteSairPelaPortaDaSaida(): void
    {
        $tenant  = new Tenant();
        $pessoa  = $this->criarUsuario('Eu');
        $vinculo = new UserTenant($pessoa, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->once())->method('remove')->with($vinculo);

        $this->useCase->executar(
            new RemoverColaboradorInput($pessoa, $pessoa, $tenant, null, OrigemRemocao::Saida)
        );
    }

    #[TestDox('recusa substituto que nao e colaborador ativo do escritorio')]
    public function testRecusaSubstitutoDeFora(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Colaborador');
        $estranho    = $this->criarUsuario('De fora');
        $vinculo     = new UserTenant($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('existeVinculoAtivo')->willReturn(false);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant, $estranho));
    }

    #[TestDox('grava o registro de auditoria com o tenant da rota, nao o da sessao')]
    public function testGravaAuditoriaComOTenantDaRota(): void
    {
        $tenant = new Tenant();
        $ref    = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, 77);

        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Colaborador');
        $vinculo     = new UserTenant($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $capturado = null;
        $this->em->method('persist')->willReturnCallback(function ($entidade) use (&$capturado) {
            if ($entidade instanceof AuditLog) {
                $capturado = $entidade;
            }
        });

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));

        self::assertNotNull($capturado, 'nenhum AuditLog foi persistido');
        self::assertSame(77, $capturado->getTenantId());
        self::assertSame('delete', $capturado->getAction());
        self::assertSame(UserTenant::class, $capturado->getEntityClass());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter RemoverColaboradorDoEscritorioUseCaseTest`

Expected: FAIL — `Class "App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase" not found`.

- [ ] **Step 3: Criar o enum e o DTO**

```php
<?php
declare(strict_types=1);

namespace App\Tenant\DTO;

/** Distingue as duas portas da remoção: o painel do admin e a saída por conta própria. */
enum OrigemRemocao: string
{
    case Painel = 'painel';
    case Saida  = 'saida';
}
```

```php
<?php
declare(strict_types=1);

namespace App\Tenant\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

final readonly class RemoverColaboradorInput
{
    public function __construct(
        public User $executor,
        public User $colaborador,
        public Tenant $tenant,
        public ?User $substituto = null,
        public OrigemRemocao $origem = OrigemRemocao::Painel,
    ) {
    }
}
```

- [ ] **Step 4: Escrever o UseCase (só o que os testes cobram)**

```php
<?php
declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remove uma pessoa do escritório: apaga o vínculo de verdade.
 *
 * Substitui a demissão. Atende as duas portas — o painel do admin e a saída por
 * conta própria — distinguidas por OrigemRemocao. A conta da pessoa não é tocada:
 * ela pode ser convidada de volta, e volta zerada.
 */
final class RemoverColaboradorDoEscritorioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    public function executar(RemoverColaboradorInput $input): void
    {
        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($input->colaborador, $input->tenant);
        if ($vinculo === null) {
            throw new \InvalidArgumentException('Vínculo ativo não encontrado.');
        }

        $this->validar($input, $vinculo);

        // Tasks 2 e 3 encaixam aqui, antes da auditoria.

        $this->registrarAuditoria($input, $vinculo);

        $this->em->remove($vinculo);
        $this->em->flush();
    }

    private function validar(RemoverColaboradorInput $input, UserTenant $vinculo): void
    {
        if ($input->origem === OrigemRemocao::Painel) {
            if ($input->executor === $input->colaborador) {
                throw new \InvalidArgumentException(
                    'Para deixar o escritório, use a opção "Sair do escritório".'
                );
            }

            // Cinto secundário: sabidamente inerte onde tenant.criado_por é nulo (spec §4).
            if ($input->tenant->getCriadoPor() === $input->colaborador) {
                throw new \InvalidArgumentException('Não é permitido remover o criador do escritório.');
            }
        }

        if ($this->ehUltimoAdmin($vinculo, $input->tenant)) {
            throw new \InvalidArgumentException(
                'Este é o último administrador do escritório. '
                . 'Promova outro administrador antes de removê-lo.'
            );
        }

        if ($input->substituto !== null
            && !$this->userTenantRepository->existeVinculoAtivo($input->substituto, $input->tenant)) {
            throw new \InvalidArgumentException('O substituto precisa ser colaborador ativo deste escritório.');
        }
    }

    private function ehUltimoAdmin(UserTenant $vinculo, Tenant $tenant): bool
    {
        $role = $vinculo->getTenantRole();
        if ($role === null || !$role->isSystem()) {
            return false;
        }

        return $this->userTenantRepository->contarAdminsAtivos($tenant) <= 1;
    }

    /**
     * O AuditLogSubscriber grava o tenant da SESSÃO, não o da rota — um super-admin sem
     * escritório selecionado produz log com tenant nulo, invisível na trilha. Por isso o
     * rastro desta ação é gravado aqui, explicitamente, com o tenant que veio da rota.
     */
    private function registrarAuditoria(RemoverColaboradorInput $input, UserTenant $vinculo): void
    {
        $log = new AuditLog();
        $log->setAction('delete')
            ->setEntityClass(UserTenant::class)
            ->setEntityId((string) $vinculo->getId())
            ->setTenantId($input->tenant->getId())
            ->setActorUserId($input->executor->getId())
            ->setActorEmail($input->executor->getEmail())
            ->setChanges([
                'colaborador_id'     => $input->colaborador->getId(),
                'colaborador_email'  => $input->colaborador->getEmail(),
                'colaborador_nome'   => $input->colaborador->getFullName(),
                'codigo_funcionario' => $vinculo->getCodigoFuncionario(),
                'data_admissao'      => $vinculo->getDataAdmissao()?->format('Y-m-d'),
                'perfil'             => $vinculo->getTenantRole()?->getName(),
                'origem'             => $input->origem->value,
                'substituto_id'      => $input->substituto?->getId(),
            ]);

        $this->em->persist($log);
    }
}
```

- [ ] **Step 5: Acrescentar `findAdminAtivoMaisAntigo` ao repositório** (a Task 2 consome)

Em `app/src/Repository/UserTenantRepository.php`:

```php
    /**
     * Administrador ativo de vínculo mais antigo, ignorando quem está saindo.
     * Herda os quadros de Kanban quando não há substituto e a remoção veio da
     * saída por conta própria (não existe executor admin nesse caminho).
     */
    public function findAdminAtivoMaisAntigo(Tenant $tenant, User $exceto): ?UserTenant
    {
        return $this->createQueryBuilder('ut')
            ->join('ut.tenantRole', 'r')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->andWhere('r.isSystem = true')
            ->andWhere('ut.user != :exceto')
            ->setParameter('tenant', $tenant)
            ->setParameter('exceto', $exceto)
            ->orderBy('ut.createdAt', 'ASC')
            ->addOrderBy('ut.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
```

- [ ] **Step 6: Rodar e ver passar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter RemoverColaboradorDoEscritorioUseCaseTest`

Expected: PASS (6 testes).

- [ ] **Step 7: Commit**

Arquivos: os 3 criados + `UserTenantRepository.php` + o teste.
Mensagem: `cria o usecase de remover colaborador com as travas e a auditoria`

---

### Task 2: Passar o bastão das responsabilidades

**Files:**
- Modify: `app/src/Tenant/UseCase/RemoverColaboradorDoEscritorioUseCase.php`
- Test: `app/tests/Tenant/Functional/RemoverColaboradorIsolamentoTest.php`

**Interfaces:**
- Consumes: `RemoverColaboradorDoEscritorioUseCase::executar()` da Task 1; `UserTenantRepository::findAdminAtivoMaisAntigo()`.
- Produces: nada novo para fora.

**O que o `DemitirFuncionarioUseCase` já cobria e precisa ser preservado:** `Pasta.responsavel`, `Chamado.responsavel`, `tarefa_responsaveis`, `evento_participante`. **O que ele esquecia e entra agora:** `kanban_card_responsavel`, `kanban_board_participante` e `kanban_board.criado_por`.

- [ ] **Step 1: Escrever o teste de isolamento que falha**

O teste prova a regra que mais dói se quebrar: remover no escritório A não pode tocar em nada do B.

```php
<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanBoard;
use App\Tenant\DTO\RemoverColaboradorInput;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(RemoverColaboradorDoEscritorioUseCase::class)]
final class RemoverColaboradorIsolamentoTest extends JusPrimeWebTestCase
{
    #[TestDox('remover no escritorio A nao toca no quadro de Kanban do escritorio B')]
    public function testNaoTocaNoOutroEscritorio(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenantA = new Tenant(); $tenantA->setName('A ' . uniqid());
        $tenantB = new Tenant(); $tenantB->setName('B ' . uniqid());
        $em->persist($tenantA); $em->persist($tenantB);

        $pessoa = new User();
        $pessoa->setEmail('multi_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa em dois escritórios');
        $em->persist($pessoa);

        $admin = new User();
        $admin->setEmail('admin_' . uniqid() . '@test.com');
        $admin->setFullName('Admin A');
        $em->persist($admin);

        $em->persist(new UserTenant($pessoa, $tenantA));
        $em->persist(new UserTenant($pessoa, $tenantB));
        $em->persist(new UserTenant($admin, $tenantA));

        // Um quadro em CADA escritório, ambos criados pela mesma pessoa.
        $boardA = new KanbanBoard();
        $boardA->setNome('Quadro do A');
        $boardA->setTenant($tenantA);
        $boardA->setCriadoPor($pessoa);
        $em->persist($boardA);

        $boardB = new KanbanBoard();
        $boardB->setNome('Quadro do B');
        $boardB->setTenant($tenantB);
        $boardB->setCriadoPor($pessoa);
        $boardB->adicionarParticipante($pessoa);
        $em->persist($boardB);
        $em->flush();

        $useCase = static::getContainer()->get(RemoverColaboradorDoEscritorioUseCase::class);
        $useCase->executar(new RemoverColaboradorInput($admin, $pessoa, $tenantA));

        $em->clear();

        // O quadro do A trocou de dono...
        $recarregadoA = $em->find(KanbanBoard::class, $boardA->getId());
        self::assertSame(
            $admin->getId(),
            $recarregadoA->getCriadoPor()?->getId(),
            'o quadro do escritório A devia ter sido herdado pelo executor'
        );

        // ...e o do B não foi tocado.
        $recarregadoB = $em->find(KanbanBoard::class, $boardB->getId());
        self::assertSame(
            $pessoa->getId(),
            $recarregadoB->getCriadoPor()?->getId(),
            'o criador do quadro do escritório B não podia ter mudado'
        );
        self::assertCount(1, $recarregadoB->getParticipantes(), 'o participante do B foi apagado');
    }
}
```

> ⚠️ **Confira os nomes reais** de `KanbanBoard` (`setNome`/`setTenant`/`setCriadoPor`/`adicionarParticipante`/`getParticipantes`) com `grep -n "public function" app/src/Kanban/Entity/KanbanBoard.php` e ajuste antes de rodar. Se o UseCase não estiver disponível no container de teste, confirme com `debug:container RemoverColaborador`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter RemoverColaboradorIsolamentoTest`

Expected: FAIL na primeira asserção — o quadro do A **não** trocou de dono, porque nada de Kanban é tocado ainda.

- [ ] **Step 3: Implementar a passagem do bastão**

Acrescentar ao UseCase e chamar em `executar()`, logo depois de `validar()`:

```php
    private function passarOBastao(RemoverColaboradorInput $input): void
    {
        $uid = $input->colaborador->getId();
        $tid = $input->tenant->getId();
        $sub = $input->substituto?->getId();

        if ($input->substituto !== null) {
            $this->em->createQuery(
                'UPDATE App\Pasta\Entity\Pasta p SET p.responsavel = :sub
                 WHERE p.responsavel = :user AND p.tenant = :tenant'
            )->setParameter('sub', $input->substituto)
             ->setParameter('user', $input->colaborador)
             ->setParameter('tenant', $input->tenant)->execute();

            $this->em->createQuery(
                'UPDATE App\Entity\ServiceDesk\Chamado c SET c.responsavel = :sub
                 WHERE c.responsavel = :user AND c.tenant = :tenant'
            )->setParameter('sub', $input->substituto)
             ->setParameter('user', $input->colaborador)
             ->setParameter('tenant', $input->tenant)->execute();
        } else {
            $this->em->createQuery(
                'UPDATE App\Pasta\Entity\Pasta p SET p.responsavel = NULL
                 WHERE p.responsavel = :user AND p.tenant = :tenant'
            )->setParameter('user', $input->colaborador)
             ->setParameter('tenant', $input->tenant)->execute();

            $this->em->createQuery(
                'UPDATE App\Entity\ServiceDesk\Chamado c SET c.responsavel = NULL
                 WHERE c.responsavel = :user AND c.tenant = :tenant'
            )->setParameter('user', $input->colaborador)
             ->setParameter('tenant', $input->tenant)->execute();
        }

        $conn = $this->em->getConnection();

        // Cada trio: tabela de vínculo, coluna do dono, tabela dona que carrega o tenant_id.
        $vinculos = [
            ['tarefa_responsaveis',       'tarefa_id',       'tarefa'],
            ['evento_participante',       'evento_id',       'evento'],
            ['kanban_card_responsavel',   'kanban_card_id',  'kanban_card'],
            ['kanban_board_participante', 'kanban_board_id', 'kanban_board'],
        ];

        foreach ($vinculos as [$tabela, $coluna, $dona]) {
            if ($sub !== null) {
                // NOT IN global de propósito: evita colisão de PK quando o substituto já participa.
                $conn->executeStatement(
                    "INSERT INTO {$tabela} ({$coluna}, user_id)
                     SELECT {$coluna}, :sub FROM {$tabela}
                     WHERE user_id = :uid
                       AND {$coluna} IN (SELECT id FROM {$dona} WHERE tenant_id = :tid)
                       AND {$coluna} NOT IN (SELECT {$coluna} FROM {$tabela} WHERE user_id = :sub2)",
                    ['sub' => $sub, 'uid' => $uid, 'tid' => $tid, 'sub2' => $sub]
                );
            }

            $conn->executeStatement(
                "DELETE FROM {$tabela}
                 WHERE user_id = :uid
                   AND {$coluna} IN (SELECT id FROM {$dona} WHERE tenant_id = :tid)",
                ['uid' => $uid, 'tid' => $tid]
            );
        }

        // Quadro sem dono some para o escritório inteiro: KanbanBoardRepository só lista
        // para criador ou participante, e não existe visão de admin (spec §8).
        $herdeiro = $this->resolverHerdeiroDosQuadros($input);
        if ($herdeiro !== null) {
            $conn->executeStatement(
                'UPDATE kanban_board SET criado_por_id = :herdeiro
                 WHERE criado_por_id = :uid AND tenant_id = :tid',
                ['herdeiro' => $herdeiro->getId(), 'uid' => $uid, 'tid' => $tid]
            );
        }
    }

    private function resolverHerdeiroDosQuadros(RemoverColaboradorInput $input): ?User
    {
        if ($input->substituto !== null) {
            return $input->substituto;
        }

        if ($input->origem === OrigemRemocao::Painel) {
            return $input->executor;
        }

        return $this->userTenantRepository
            ->findAdminAtivoMaisAntigo($input->tenant, $input->colaborador)?->getUser();
    }
```

Acrescente `use App\Entity\Auth\User;` ao topo do arquivo.

- [ ] **Step 4: Rodar e ver passar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter RemoverColaboradorIsolamentoTest`

Expected: PASS.

- [ ] **Step 5: Commit**

Arquivos: o UseCase + o teste de isolamento.
Mensagem: `passa o bastao das responsabilidades, incluindo o kanban`

---

### Task 3: Revogar os acessos

**Files:**
- Modify: `app/src/Tenant/UseCase/RemoverColaboradorDoEscritorioUseCase.php`
- Test: `app/tests/Tenant/Functional/ReconvitePosRemocaoTest.php`

**Interfaces:**
- Consumes: o UseCase das Tasks 1–2.
- Produces: nada novo.

Quatro tabelas, todas com `WHERE tenant` explícito: `resource_access`, `access_request`, `home_office_config` (bypass de geocerca do ponto — decisão D5) e `notificacao` (a coluna do usuário chama-se `usuario_id`, não `user_id`).

- [ ] **Step 1: Escrever o teste que fecha o pedido do dono**

Monte, em `ReconvitePosRemocaoTest` (mesmo padrão de montagem do teste de isolamento):

1. tenant + admin + colaborador com vínculo ativo;
2. conceda ao colaborador um `ResourceAccess` de pasta e um `HomeOfficeConfig` naquele tenant;
3. remova pelo UseCase;
4. recrie o vínculo — `$em->persist(new UserTenant($colaborador, $tenant))` — simulando o aceite do convite;
5. afirme que os acessos **não voltaram**:

```php
        $conn = $em->getConnection();

        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM resource_access WHERE user_id = ? AND tenant_id = ?',
            [$colaborador->getId(), $tenant->getId()]
        ), 'a permissão item a item sobreviveu ao re-convite');

        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM home_office_config WHERE user_id = ? AND tenant_id = ?',
            [$colaborador->getId(), $tenant->getId()]
        ), 'a liberação de geocerca sobreviveu ao re-convite');
```

> Confira os construtores reais de `ResourceAccess` e `HomeOfficeConfig` antes de escrever — não invente setters.

- [ ] **Step 2: Rodar e ver falhar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter ReconvitePosRemocaoTest`

Expected: FAIL — as duas contagens vêm 1, esperado 0.

- [ ] **Step 3: Implementar a revogação**

```php
    /**
     * SQL nativo NÃO herda o TenantFilter: cada DELETE carrega tenant_id explícito, senão
     * remover alguém de um escritório apagaria o acesso dela em outro.
     */
    private function revogarAcessos(RemoverColaboradorInput $input): void
    {
        $uid = $input->colaborador->getId();
        $tid = $input->tenant->getId();

        $conn = $this->em->getConnection();

        foreach (['resource_access', 'access_request', 'home_office_config'] as $tabela) {
            $conn->executeStatement(
                "DELETE FROM {$tabela} WHERE user_id = :uid AND tenant_id = :tid",
                ['uid' => $uid, 'tid' => $tid]
            );
        }

        // A notificação usa usuario_id, não user_id.
        $conn->executeStatement(
            'DELETE FROM notificacao WHERE usuario_id = :uid AND tenant_id = :tid',
            ['uid' => $uid, 'tid' => $tid]
        );
    }
```

Chamar em `executar()`, entre `passarOBastao()` e `registrarAuditoria()`.

- [ ] **Step 4: Rodar e ver passar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter ReconvitePosRemocaoTest`

Expected: PASS.

- [ ] **Step 5: Commit**

Arquivos: o UseCase + o teste de re-convite.
Mensagem: `revoga permissoes, pedidos, geocerca e notificacoes do escritorio`

---

### Task 4: A rota e o botão

**Files:**
- Modify: `app/src/Controller/TenantController.php`, `app/templates/tenant/edit_user_role.html.twig`
- Test: `app/tests/Tenant/Functional/RemoverColaboradorControllerTest.php`

**Interfaces:**
- Consumes: `RemoverColaboradorDoEscritorioUseCase::executar()`.
- Produces: rota `app_tenant_user_remover` → `POST /tenant/{tenantId}/user/{userId}/remover`, token CSRF `remover_<userId>`, campo opcional `substituto_id`.

- [ ] **Step 1: Escrever o teste funcional que falha**

Copie os helpers `criarTenant` e `criarAdmin` de `DemitirFuncionarioControllerTest` e cubra quatro cenários: sucesso (302 e o vínculo sumiu do banco), CSRF inválido (403), executor sem `admin.users.manage` (403), alvo de outro escritório (404).

**E o quinto, que é o coração da decisão D7** — o super-admin **não** passa por cima da trava do último administrador. É o furo que a revisão encontrou: hoje `tenant.criado_por` é nulo em produção, e sem esta prova nada impede o suporte de esvaziar um escritório:

```php
    #[TestDox('nem super-admin remove o ultimo administrador do escritorio')]
    public function testSuperAdminNaoRemoveOUltimoAdmin(): void
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->criarTenant();

        // Um único administrador no escritório.
        $unicoAdmin = $this->criarAdmin($tenant);

        // O executor é super-admin e NÃO tem vínculo com este escritório.
        $suporte = new User();
        $suporte->setEmail('suporte_' . uniqid() . '@test.com');
        $suporte->setFullName('Suporte');
        $suporte->setRoles(['ROLE_SUPER_ADMIN']);
        $em->persist($suporte);
        $em->flush();

        $client = static::createClient();
        $client->loginUser($suporte);

        $client->request('POST', sprintf('/tenant/%d/user/%d/remover', $tenant->getId(), $unicoAdmin->getId()), [
            '_token' => static::getContainer()->get('security.csrf.token_manager')
                ->getToken('remover_' . $unicoAdmin->getId())->getValue(),
        ]);

        self::assertResponseRedirects();

        $em->clear();
        $vinculo = static::getContainer()->get(UserTenantRepository::class)
            ->findPorUserETenant(
                $em->find(User::class, $unicoAdmin->getId()),
                $em->find(Tenant::class, $tenant->getId())
            );

        self::assertNotNull($vinculo, 'o super-admin conseguiu remover o último administrador');
    }
```

> Ajuste a obtenção do cliente e do token CSRF ao padrão de `JusPrimeWebTestCase` do projeto — `DemitirFuncionarioControllerTest` já faz isso; copie de lá em vez de inventar.

- [ ] **Step 2: Rodar e ver falhar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter RemoverColaboradorControllerTest`

Expected: FAIL — a rota não existe.

- [ ] **Step 3: Trocar o método do controller**

Substituir `demitirFuncionario` (hoje em `TenantController.php:735-793`) por:

```php
    #[Route('/{tenantId}/user/{userId}/remover', name: 'app_tenant_user_remover', methods: ['POST'])]
    public function removerColaborador(
        int $tenantId,
        int $userId,
        Request $request,
        RemoverColaboradorDoEscritorioUseCase $useCase,
        PermissionChecker $permissionChecker,
        EntityManagerInterface $em,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
    ): Response {
        $executor = $this->getUser();
        if (!$executor) {
            throw $this->createAccessDeniedException();
        }

        // B-route: o tenant vem da URL e é resolvido pelo repositório, nunca da sessão.
        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('remover_' . $userId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $colaborador = $em->find(User::class, $userId);
        if (!$colaborador || !$userTenantRepository->existeVinculoAtivo($colaborador, $tenant)) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $executor->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($executor, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($executor, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException();
        }

        $substitutoId = $request->request->getInt('substituto_id') ?: null;
        $substituto   = $substitutoId ? $em->find(User::class, $substitutoId) : null;

        if ($substituto !== null && !$userTenantRepository->existeVinculoAtivo($substituto, $tenant)) {
            throw $this->createNotFoundException('Substituto não encontrado.');
        }

        try {
            $useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant, $substituto));
            $this->addFlash('success', sprintf('%s foi removido do escritório.', $colaborador->getFullName()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_tenant_users', ['tenantId' => $tenantId]);
    }
```

⚠️ **A trava do último admin vale também para super-admin** (decisão D7). Ela mora no UseCase, que roda depois do guard de permissão — **não** acrescente atalho de super-admin dentro do UseCase.

- [ ] **Step 4: Trocar o bloco do template**

Em `edit_user_role.html.twig:402`: a rota vira `app_tenant_user_remover` com `{tenantId: tenantId, userId: user.id}`, o token vira `csrf_token('remover_' ~ user.id)`, o título vira "Remover do escritório", o botão vira "Remover do escritório", e o texto passa a ser:

> Ao remover **{{ user.fullName }}**, ela perde o acesso a este escritório e sai da sua lista de colaboradores. A conta dela continua existindo, e ela pode ser convidada de volta — voltando sem os acessos de antes.

O campo de substituto continua como está, apenas com o rótulo ajustado para "Quem assume o que era dela? (opcional)".

- [ ] **Step 5: Rodar e ver passar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter RemoverColaboradorControllerTest`

Expected: PASS.

- [ ] **Step 6: Commit**

Arquivos: controller, template e teste funcional.
Mensagem: `troca a rota de demitir pela de remover do escritorio`

---

### Task 5: A saída por conta própria usa o mesmo caminho

**Files:**
- Modify: `app/src/Tenant/UseCase/SairDoEscritorioUseCase.php`
- Test: `app/tests/Tenant/Unit/SairDoEscritorioUseCaseTest.php`

**Interfaces:**
- Consumes: `RemoverColaboradorDoEscritorioUseCase::executar()` com `OrigemRemocao::Saida`.
- Produces: `SairDoEscritorioUseCase::executar(User $usuario, Tenant $tenant): void` — assinatura **preservada**, para o `EscritorioController` não mudar.

- [ ] **Step 1: Ajustar o teste existente**

`SairDoEscritorioUseCaseTest` hoje afirma que o vínculo fica inativo. Passa a afirmar que **o vínculo é apagado** e que a trava do último admin continua recusando a saída.

- [ ] **Step 2: Rodar e ver falhar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter SairDoEscritorioUseCaseTest`

Expected: FAIL.

- [ ] **Step 3: Delegar**

```php
<?php
declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;

/**
 * Saída voluntária: a própria pessoa deixa o escritório. Mesma regra da remoção
 * pelo painel (o vínculo é apagado), só que sem substituto — o que era dela fica
 * desatribuído.
 */
final class SairDoEscritorioUseCase
{
    public function __construct(
        private readonly RemoverColaboradorDoEscritorioUseCase $remover,
    ) {}

    public function executar(User $usuario, Tenant $tenant): void
    {
        $this->remover->executar(
            new RemoverColaboradorInput($usuario, $usuario, $tenant, null, OrigemRemocao::Saida)
        );
    }
}
```

A mensagem da trava do último admin passa a vir do UseCase novo. Decida **uma** redação e use a mesma nas duas portas — a de hoje no `sair` é "Transfira a titularidade ou exclua o escritório antes de sair."

- [ ] **Step 4: Rodar e ver passar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter SairDoEscritorioUseCaseTest`

Expected: PASS.

- [ ] **Step 5: Commit**

Arquivos: o UseCase de sair + seu teste.
Mensagem: `sair do escritorio passa a apagar o vinculo`

---

### Task 6: A lista mostra só quem é colaborador

**Files:**
- Modify: `app/src/Controller/TenantController.php:346`, `app/templates/tenant/users.html.twig`
- Test: `app/tests/Tenant/Functional/RemoverColaboradorControllerTest.php`

**Interfaces:**
- Consumes: a rota da Task 4.
- Produces: nada.

- [ ] **Step 1: Escrever o teste que falha**

Acrescente ao teste funcional: depois de remover, um `GET` em `app_tenant_users` **não** traz o e-mail da pessoa removida no HTML.

- [ ] **Step 2: Rodar e ver falhar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter RemoverColaboradorControllerTest`

Expected: FAIL — com o vínculo apagado a pessoa já some, então **este teste pode passar sozinho**. Para ele valer alguma coisa, prove-o com um vínculo **inativo legado**: crie um `UserTenant` e marque `isActive = false` direto no banco, e afirme que ele não aparece. É esse caso que a mudança conserta.

- [ ] **Step 3: Implementar**

Em `TenantController.php:346`, trocar `findBy(['tenant' => $tenant])` por `findBy(['tenant' => $tenant, 'isActive' => true])`.

Em `users.html.twig`: apagar as linhas 43-44 (`usersAtivos` / `usersDesligados`), a seção `usersDesligados` inteira (128-186) e a função JS `iniciarIconeDesligados` com suas chamadas. Onde o template usava `usersAtivos`, passar a usar `users`.

- [ ] **Step 4: Rodar e ver passar**

Run: `bash scripts/frente-testar.sh desvincular-colaborador --filter RemoverColaboradorControllerTest`

Expected: PASS.

- [ ] **Step 5: Commit**

Mensagem: `a lista de colaboradores mostra so vinculos ativos`

---

### Task 7: A trilha de auditoria não perde quem saiu

**Files:**
- Modify: `app/src/Repository/UserRepository.php:58-84` (`findAuditFilterOptions`)
- Test: junto dos testes de auditoria já existentes — localize com `grep -rl "findAuditFilterOptions" app/tests`

**Interfaces:**
- Consumes: nada.
- Produces: `findAuditFilterOptions` mantém a assinatura; muda o conjunto devolvido.

Sem isto, o hard delete tira a pessoa do dropdown "usuário" da trilha, e o histórico dela fica no banco sem porta de filtro na tela — o oposto do que a spec §6.3 promete.

- [ ] **Step 1: Escrever o teste que falha**

Remover alguém que gerou registros de auditoria e afirmar que o id dela **continua** entre as opções de filtro daquele escritório.

- [ ] **Step 2: Rodar e ver falhar**

Expected: FAIL — a pessoa sumiu das opções.

- [ ] **Step 3: Implementar**

Unir os usuários com vínculo ativo aos `actor_user_id` distintos presentes em `audit_log` **daquele tenant**. A consulta continua escopada por `tenant_id` — sem isso, vaza nome de gente de outro escritório para dentro de um filtro.

- [ ] **Step 4: Rodar e ver passar** · **Step 5: Commit**

Mensagem: `o filtro da trilha mantem quem foi removido`

---

### Task 8: Enterrar a demissão

Última tarefa: o conceito sai do código e do banco. Só depois que tudo acima está verde.

**Files:**
- Delete: `DemitirFuncionarioUseCase.php`, `DemitirFuncionarioInput.php`, `DemitirFuncionarioUseCaseTest.php`, `DemitirFuncionarioControllerTest.php`, `DemitirFuncionarioIsolamentoTest.php`
- Modify: `app/src/Entity/Auth/UserTenant.php`
- Create: `app/migrations/Version2026XXXXXXXXXX.php`

**Interfaces:**
- Consumes: tudo das Tasks 1–7.
- Produces: nada.

- [ ] **Step 1: Apagar o código morto**

Os 5 arquivos acima. Da entidade `UserTenant`: os métodos `demitir()` e `sair()`, a propriedade `demitidoEm` e seu getter. **Manter** `reativar()` e `isActive` (spec §6.6).

Confira o que ficou pendurado: `grep -rn "demitid\|Demitir" app/src app/templates app/tests`

- [ ] **Step 2: Fotografar a divergência que já existia**

Regra do CLAUDE.md — o que aparecer aqui **não é seu**, e sai do arquivo gerado depois:

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/desvincular-colaborador/app && php bin/console doctrine:schema:update --dump-sql'
```

- [ ] **Step 3: Escrever a migration à mão**

Não use `make:migration` para o `DELETE` — gerador não produz migração de dado.

```php
    public function getDescription(): string
    {
        return 'Apaga vinculos inativos e remove a coluna de demissao: a demissao deixou de existir.';
    }

    public function up(Schema $schema): void
    {
        // Regra nova: vínculo existe = colaborador. Não há mais estado intermediário.
        // Em produção isto apaga 3 linhas (decisão D8 da spec).
        $this->addSql('DELETE FROM user_tenant WHERE is_active = false');
        $this->addSql('ALTER TABLE user_tenant DROP demitido_em');
    }

    public function down(Schema $schema): void
    {
        // As linhas apagadas não voltam — o down só restaura a coluna.
        $this->addSql('ALTER TABLE user_tenant ADD demitido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }
```

⚠️ Compare com a fotografia do Step 2 e **tire tudo que já aparecia lá** — é alteração de outra frente. Cuidado com `DROP INDEX` de índice funcional: o Doctrine não sabe representá-lo no mapeamento e propõe apagar índices criados por SQL cru.

- [ ] **Step 4: Aplicar e validar**

```bash
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/desvincular-colaborador/app && php bin/console doctrine:migrations:migrate --no-interaction'
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/desvincular-colaborador/app && php bin/console doctrine:schema:validate'
```

Expected: mapeamento e banco em dia.

- [ ] **Step 5: Suíte inteira**

Run: `bash scripts/frente-testar.sh desvincular-colaborador`

Expected: verde. Qualquer teste que ainda mencione demissão foi esquecido — a lista completa está na spec §6.2.

- [ ] **Step 6: Commit**

Arquivos: as 5 deleções, a entidade e a migration.
Mensagem: `enterra a demissao: codigo morto, coluna e vinculos inativos`

---

## Fechamento

1. `bash scripts/frente-testar.sh desvincular-colaborador` — suíte inteira verde.
2. **`/review`** (`feature-review-agent`) contra a spec. Risco **ALTO**: re-revisar depois das correções, antes de seguir.
3. **O smoke no navegador é do dono.** Não abra o Playwright. Entregue dizendo o que precisa ser olhado na tela: remover alguém com substituto e sem, tentar remover o último administrador, sair por conta própria, e convidar a pessoa de volta conferindo que ela volta sem os acessos de antes.
4. `push` e `merge` são do humano.
