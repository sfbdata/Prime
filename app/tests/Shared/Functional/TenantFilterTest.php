<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Entity\Auth\User;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Tenant;
use App\Shared\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida o mecanismo central de isolamento (TenantFilter) usando Chamado (1ª entidade
 * TenantAware) como cobaia.
 */
#[CoversClass(TenantFilter::class)]
final class TenantFilterTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Com o filtro ligado, findBy/findAll só retornam chamados do tenant ativo')]
    public function testFiltroIsolaListagem(): void
    {
        [$tenantA, $tenantB] = [$this->criarTenant(), $this->criarTenant()];
        $this->criarChamado($tenantA);
        $this->criarChamado($tenantA);
        $this->criarChamado($tenantB);

        $repo = $this->em->getRepository(Chamado::class);

        // Sem filtro: vê os 3.
        self::assertCount(3, $repo->findBy([]));

        // Com filtro para o tenant A: vê só os 2 dele.
        $this->ligarFiltro((int) $tenantA->getId());
        $doA = $repo->findBy([]);
        self::assertCount(2, $doA);
        foreach ($doA as $chamado) {
            self::assertSame($tenantA->getId(), $chamado->getTenant()->getId());
        }

        // Trocando para B: vê só o 1 dele.
        $this->ligarFiltro((int) $tenantB->getId());
        self::assertCount(1, $repo->findBy([]));

        // Desligando: vê os 3 de novo.
        $this->em->getFilters()->disable('tenant');
        self::assertCount(3, $repo->findBy([]));
    }

    #[TestDox('Comportamento de find() por id sob o filtro (decide a necessidade do guard por-id)')]
    public function testFiltroEmFindPorId(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $chamadoB = $this->criarChamado($tenantB);
        $idB = (int) $chamadoB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        // Limpa a identity map para forçar um SELECT real (cenário de request carregando por id).
        $this->em->clear();

        $achado = $this->em->find(Chamado::class, $idB);

        // Se o filtro cobre find(), $achado é null. Se NÃO cobre, retorna o chamado de B
        // (IDOR) — e aí o guard garantirRecursoDoTenant é obrigatório nas rotas por id.
        self::assertNull(
            $achado,
            'find() por id de outro tenant NÃO foi bloqueado pelo filtro — guard por-id é obrigatório.'
        );
    }

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarChamado(Tenant $tenant): Chamado
    {
        $user = new User();
        $user->setEmail('u_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);

        $chamado = new Chamado();
        $chamado->setTitulo('Chamado ' . uniqid());
        $chamado->setDescricao('desc');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($user);
        $this->em->persist($chamado);
        $this->em->flush();

        return $chamado;
    }
}
