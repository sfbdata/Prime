<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Shared\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida o isolamento do domínio Ponto após RegistroPonto e JustificativaPonto virarem
 * TenantAware (modelo POR VÍNCULO). Usa um único usuário com registros/justificativas em DOIS
 * tenants (cenário do empregado compartilhado) — é o vetor que os guards de posse pré-existentes
 * NÃO fechavam e que o filtro agora fecha. em->clear() força o find() a executar SQL real.
 *
 * findCompetenciasComRegistroPorUsuario é SQL nativo (NÃO passa pelo filtro) → o escopo de tenant
 * é manual (parâmetro Tenant) e é testado explicitamente aqui.
 */
#[CoversClass(TenantFilter::class)]
#[CoversClass(RegistroPontoRepository::class)]
final class PontoIsolamentoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('findByUserAndCompetencia só retorna batidas do tenant ativo (usuário compartilhado)')]
    public function testFindByUserAndCompetenciaIsolaPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUser();

        $regA = $this->criarRegistro($usuario, $tenantA, '2026-03-10 09:00:00');
        $regB = $this->criarRegistro($usuario, $tenantB, '2026-03-15 09:00:00');

        /** @var RegistroPontoRepository $repo */
        $repo = static::getContainer()->get(RegistroPontoRepository::class);

        // sem filtro: vê as duas (estado de vazamento)
        self::assertCount(2, $repo->findByUserAndCompetencia($usuario, 2026, 3));

        $this->ligarFiltro((int) $tenantA->getId());
        $doA = $repo->findByUserAndCompetencia($usuario, 2026, 3);
        self::assertCount(1, $doA);
        self::assertSame($regA->getId(), $doA[0]->getId());

        $this->ligarFiltro((int) $tenantB->getId());
        $doB = $repo->findByUserAndCompetencia($usuario, 2026, 3);
        self::assertCount(1, $doB);
        self::assertSame($regB->getId(), $doB[0]->getId());
    }

    #[TestDox('findCompetenciasComRegistroPorUsuario (SQL nativo) escopa por tenant via parâmetro')]
    public function testFindCompetenciasNativeSqlIsolaPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUser();

        $this->criarRegistro($usuario, $tenantA, '2026-03-10 09:00:00');
        $this->criarRegistro($usuario, $tenantB, '2026-04-10 09:00:00');

        /** @var RegistroPontoRepository $repo */
        $repo = static::getContainer()->get(RegistroPontoRepository::class);

        $compA = $repo->findCompetenciasComRegistroPorUsuario($usuario, $tenantA);
        self::assertSame(['2026-03'], array_column($compA, 'valor'), 'SQL nativo vazou competência de outro tenant');

        $compB = $repo->findCompetenciasComRegistroPorUsuario($usuario, $tenantB);
        self::assertSame(['2026-04'], array_column($compB, 'valor'));
    }

    #[TestDox('find() por id de RegistroPonto de outro tenant retorna null (fecha IDOR admin de ponto)')]
    public function testFindPorIdFechaIdorDoRegistro(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $regB = $this->criarRegistro($this->criarUser(), $tenantB, '2026-03-10 09:00:00');
        $idB = (int) $regB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull($this->em->find(RegistroPonto::class, $idB), 'IDOR aberto em RegistroPonto');
    }

    #[TestDox('find() por id de JustificativaPonto de outro tenant retorna null (fecha IDOR de aprovar/rejeitar/anexo)')]
    public function testFindPorIdFechaIdorDaJustificativa(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $justB = $this->criarJustificativa($this->criarUser(), $tenantB, 'pendente');
        $idB = (int) $justB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull(
            $this->em->find(JustificativaPonto::class, $idB),
            'IDOR aberto em JustificativaPonto: rotas aprovar/rejeitar/reverter/anexo a carregam por id direto',
        );
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant PONTO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('ponto_' . uniqid() . '@test.com');
        $user->setFullName('User Ponto');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarRegistro(User $user, Tenant $tenant, string $dataHora): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $registro->setDataHora(new \DateTime($dataHora));
        $this->em->persist($registro);
        $this->em->flush();

        return $registro;
    }

    private function criarJustificativa(User $user, Tenant $tenant, string $status): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setUser($user);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus($status);
        $this->em->persist($justificativa);
        $this->em->flush();

        return $justificativa;
    }
}
