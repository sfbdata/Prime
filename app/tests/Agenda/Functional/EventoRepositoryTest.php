<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Entity\Agenda\Evento;
use App\Entity\Agenda\LegendaCor;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\EventoRepository;
use App\Repository\LegendaCorRepository;
use App\Shared\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida que o TenantFilter isola o domínio Agenda após Evento + LegendaCor virarem
 * TenantAware. Cobre o calendário (incluindo o ramo `visibilidade=todos` que hoje vaza
 * eventos entre escritórios), a carga por id (IDOR) e as legendas de cor.
 */
#[CoversClass(TenantFilter::class)]
final class EventoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('findForCalendar (visibilidade=todos) só retorna eventos do tenant ativo')]
    public function testFiltroIsolaCalendario(): void
    {
        [$tenantA, $tenantB] = [$this->criarTenant(), $this->criarTenant()];
        $eA = $this->criarEvento($tenantA, 'Audiência A');
        $eB = $this->criarEvento($tenantB, 'Audiência B');

        /** @var EventoRepository $repo */
        $repo = $this->em->getRepository(Evento::class);

        // Sem filtro: o ramo visibilidade=todos devolve os 2 (vazamento de hoje).
        $todos = $repo->findForCalendar(null, null, null);
        self::assertContains($eA, $todos);
        self::assertContains($eB, $todos);

        // Com filtro do tenant A: só o evento de A.
        $this->ligarFiltro((int) $tenantA->getId());
        $doA = $repo->findForCalendar(null, null, null);
        self::assertContains($eA, $doA);
        self::assertNotContains($eB, $doA, 'calendário vazou evento de outro tenant');
    }

    #[TestDox('findByDateRange só retorna eventos do tenant ativo')]
    public function testFiltroIsolaDateRange(): void
    {
        [$tenantA, $tenantB] = [$this->criarTenant(), $this->criarTenant()];
        $this->criarEvento($tenantA, 'A1');
        $this->criarEvento($tenantA, 'A2');
        $this->criarEvento($tenantB, 'B1');

        /** @var EventoRepository $repo */
        $repo = $this->em->getRepository(Evento::class);
        $ini = new \DateTimeImmutable('-1 day');
        $fim = new \DateTimeImmutable('+30 days');

        $this->ligarFiltro((int) $tenantA->getId());
        $eventos = $repo->findByDateRange($ini, $fim, null);
        foreach ($eventos as $evento) {
            if ($evento instanceof Evento) {
                self::assertSame($tenantA->getId(), $evento->getTenant()->getId());
            }
        }
        self::assertCount(2, array_filter($eventos, static fn($e) => $e instanceof Evento));
    }

    #[TestDox('find() por id de Evento de outro tenant retorna null (fecha IDOR)')]
    public function testFindPorIdFechaIdorDoEvento(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eB = $this->criarEvento($tenantB, 'Evento B');
        $idB = (int) $eB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull($this->em->find(Evento::class, $idB), 'IDOR aberto em Evento');
    }

    #[TestDox('Legendas (findAllOrdered) e find() por id isolam por tenant')]
    public function testFiltroIsolaLegendas(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $legA = $this->criarLegenda($tenantA, 'Audiência');
        $legB = $this->criarLegenda($tenantB, 'Prazo');
        $idLegB = (int) $legB->getId();

        /** @var LegendaCorRepository $repo */
        $repo = $this->em->getRepository(LegendaCor::class);

        $this->ligarFiltro((int) $tenantA->getId());
        $ordenadas = $repo->findAllOrdered();
        self::assertContains($legA, $ordenadas);
        self::assertNotContains($legB, $ordenadas, 'legendas vazaram de outro tenant');

        $this->em->clear();
        self::assertNull($this->em->find(LegendaCor::class, $idLegB), 'IDOR aberto em LegendaCor');
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant AGE ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('age_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);

        return $user;
    }

    private function criarEvento(Tenant $tenant, string $titulo): Evento
    {
        $n = ++$this->seq;
        $evento = new Evento();
        $evento->setTitulo($titulo . ' ' . $n);
        $evento->setDataInicio((new \DateTimeImmutable('+' . $n . ' days'))->setTime(9, 0));
        $evento->setDataFim((new \DateTimeImmutable('+' . $n . ' days'))->setTime(10, 0));
        $evento->setVisibilidade(Evento::VISIBILIDADE_TODOS);
        $evento->setCriador($this->criarUser());
        $evento->setTenant($tenant);
        $this->em->persist($evento);
        $this->em->flush();

        return $evento;
    }

    private function criarLegenda(Tenant $tenant, string $nome): LegendaCor
    {
        $legenda = new LegendaCor();
        $legenda->setNome($nome . ' ' . (++$this->seq));
        $legenda->setCor('#00a65a');
        $legenda->setOrdem(0);
        $legenda->setTenant($tenant);
        $this->em->persist($legenda);
        $this->em->flush();

        return $legenda;
    }
}
