<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Service\FiltroDoEscritorioDoctrine;
use App\Conector\Service\ModoDoConector;
use App\Conector\Service\PortaoDoConector;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\BarreirasDoEscritorio;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Factory\Pasta\PastaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

/**
 * O portão do conector com o container e o banco de verdade, sem requisição e sem sessão (como no `/mcp`): o filtro
 * `tenant` do Doctrine fica ligado no escritório do token, o TenantContext devolve esse escritório, a auditoria grava
 * com ele, e as barreiras e os termos recusam pelos repositórios reais (spec mcp-remoto-oauth 4, 4.1, 4.3; 12.2, 12.3).
 */
#[CoversClass(PortaoDoConector::class)]
#[CoversClass(FiltroDoEscritorioDoctrine::class)]
#[CoversClass(TenantContext::class)]
final class PortaoDoConectorNoBancoTest extends KernelTestCase
{
    use CenarioDoConectorTrait;
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('abrir: o filtro de escritório fica ligado no escritório do token e a consulta não traz pasta de outro escritório')]
    public function testFiltroLigadoNoEscritorioDoToken(): void
    {
        $escritorio = $this->escritorio();
        $outro = $this->escritorio('Outro');
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $escritorio, permissoes: ['modules.pastas.view']);
        $minha = PastaFactory::createOne(['tenant' => $escritorio])->_real();
        $alheia = PastaFactory::createOne(['tenant' => $outro])->_real();
        $this->em()->clear();
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'), 'fora de requisição o filtro nasce desligado');

        $this->portao()->abrir((int) $pessoa->getId(), (int) $escritorio->getId());

        self::assertTrue($this->filtro()->estaLigadoEm((int) $escritorio->getId()));
        self::assertFalse($this->filtro()->estaLigadoEm((int) $outro->getId()));
        $ids = array_map(static fn (Pasta $p): ?int => $p->getId(), $this->em()->getRepository(Pasta::class)->findBy(['id' => [$minha->getId(), $alheia->getId()]]));
        self::assertSame([$minha->getId()], $ids);
    }

    #[TestDox('abrir: sem sessão, o TenantContext devolve o escritório e o vínculo do token')]
    public function testTenantContextSemSessao(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio);
        $contexto = static::getContainer()->get(TenantContext::class);
        self::assertFalse($contexto->hasCurrentTenant(), 'sem sessão, nada antes do portão');

        $this->portao()->abrir((int) $pessoa->getId(), (int) $escritorio->getId());
        $this->em()->clear();

        self::assertTrue($contexto->hasCurrentTenant());
        self::assertTrue($this->em()->contains($contexto->getCurrentTenant()), 'relido pelo EntityManager: gerenciado mesmo depois do clear()');
        self::assertSame($escritorio->getId(), $contexto->getCurrentTenant()?->getId());
        self::assertSame($vinculo->getId(), $contexto->getCurrentUserTenant()?->getId());
    }

    #[TestDox('auditoria: gravação depois de abrir o portão entra no audit_log com o escritório e a pessoa do token')]
    public function testAuditoriaComOEscritorioDoToken(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $escritorio, permissoes: ['modules.pastas.view']);
        $pasta = PastaFactory::createOne(['tenant' => $escritorio])->_real();
        // O firewall do `/mcp` (PR 5) autentica a pessoa do token; aqui, à mão.
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($pessoa, 'main', $pessoa->getRoles()));

        $this->portao()->abrir((int) $pessoa->getId(), (int) $escritorio->getId());
        $pasta = $this->em()->find(Pasta::class, $pasta->getId());
        self::assertInstanceOf(Pasta::class, $pasta);
        $pasta->setNup('CONECTOR-' . uniqid());
        $this->em()->flush();

        $registro = $this->em()->getRepository(AuditLog::class)->findOneBy(['entityClass' => Pasta::class, 'entityId' => (string) $pasta->getId(), 'action' => 'update']);
        self::assertInstanceOf(AuditLog::class, $registro);
        self::assertSame($escritorio->getId(), $registro->getTenantId());
        self::assertSame($pessoa->getId(), $registro->getActorUserId());
        self::assertSame($pessoa->getUserIdentifier(), $registro->getActorEmail());
    }

    #[TestDox('o primário de conta suspensa a pedido é recusado com a mensagem do pedido de exclusão')]
    public function testPrimarioNaContaSuspensaAPedido(): void
    {
        $escritorio = $this->escritorio();
        $primario = $this->pessoa();
        $this->vinculo($primario, $escritorio, sistema: true);
        $this->em()->persist(new PedidoDeExclusaoDeConta($escritorio, $primario, 'Não uso mais', new \DateTimeImmutable()));
        $this->em()->flush();

        $this->assertRecusa(BloqueioPorPedidoDeExclusao::MENSAGEM, $primario, (int) $escritorio->getId());
    }

    #[TestDox('o Master com o vínculo em análise é recusado (sem o passe do Master)')]
    public function testMasterComVinculoEmAnalise(): void
    {
        $escritorio = $this->escritorio();
        $master = $this->pessoa(['ROLE_SUPER_ADMIN']);
        $vinculo = $this->vinculo($master, $escritorio);
        $vinculo->alterarSituacao(SituacaoNaEquipe::EmAnalise);
        $this->em()->flush();

        $this->assertRecusa(SituacaoNaEquipe::EmAnalise->mensagemDeAcesso((string) $escritorio->getName()), $master, (int) $escritorio->getId());
    }

    #[TestDox('sem o aceite da versão vigente dos Termos de Uso: recusa com a mensagem fixa')]
    public function testTermosNaoAceitos(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa(aceitouOsTermos: false);
        $this->vinculo($pessoa, $escritorio);

        $this->assertRecusa(AcessoDoConectorRecusado::TERMOS_NAO_ACEITOS, $pessoa, (int) $escritorio->getId());
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'), 'recusado, o filtro nem chega a ser ligado');
    }

    private function assertRecusa(string $mensagem, User $pessoa, int $tenantId): void
    {
        try {
            $this->portao()->abrir((int) $pessoa->getId(), $tenantId);
            self::fail('O portão devolveu contexto; esperava a recusa: ' . $mensagem);
        } catch (AcessoDoConectorRecusado $recusa) {
            self::assertSame($mensagem, $recusa->getMessage());
        }
        self::assertFalse(static::getContainer()->get(TenantContext::class)->hasCurrentTenant());
    }

    /**
     * Montado com as peças do container: o portão e o filtro ainda não têm quem os use (o `/mcp` chega no PR 5), e o
     * Symfony tira do container compilado o serviço privado sem uso. O que importa é que o TenantContext, o modo e o
     * EntityManager sejam os MESMOS que o resto da aplicação usa.
     */
    private function portao(): PortaoDoConector
    {
        $c = static::getContainer();

        return new PortaoDoConector(
            $c->get(UserRepository::class),
            $c->get(TenantRepository::class),
            $c->get(UserTenantRepository::class),
            new BarreirasDoEscritorio($c->get(BloqueioPorPedidoDeExclusao::class), $c->get(RegraDosPrimarios::class)),
            $c->get(AceiteTermoRepository::class),
            new TermoVigente(),
            $this->filtro(),
            $c->get(ModoDoConector::class),
            $c->get(TenantContext::class),
        );
    }

    private function filtro(): FiltroDoEscritorioDoctrine
    {
        return new FiltroDoEscritorioDoctrine($this->em());
    }
}
