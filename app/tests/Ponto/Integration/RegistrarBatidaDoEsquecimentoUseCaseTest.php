<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regras do UseCase que grava a batida do esquecimento abonado. Usa o EntityManager real (o UseCase só
 * persiste) e escritórios gravados, porque a guarda de tenant compara ids.
 */
#[CoversClass(RegistrarBatidaDoEsquecimentoUseCase::class)]
final class RegistrarBatidaDoEsquecimentoUseCaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RegistrarBatidaDoEsquecimentoUseCase $useCase;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
        $this->useCase = static::getContainer()->get(RegistrarBatidaDoEsquecimentoUseCase::class);
    }

    #[TestDox('Esquecimento completo vira batida com a data do pedido, a hora esquecida e o tipo pedido')]
    public function testEsquecimentoViraBatida(): void
    {
        $tenant = $this->criarTenant();
        $justificativa = $this->justificativa($tenant, 'esquecimento_registro', 'retorno', '13:15');

        $registro = ($this->useCase)($justificativa, $tenant);

        self::assertInstanceOf(RegistroPonto::class, $registro);
        self::assertSame('2026-03-10 13:15:00', $registro->getDataHora()?->format('Y-m-d H:i:s'));
        self::assertSame('retorno', $registro->getTipo());
        self::assertSame($tenant, $registro->getTenant());
        self::assertSame(RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO, $registro->getObservacao());
        self::assertTrue($this->em->contains($registro), 'a batida fica persistida para o flush de quem chama');
    }

    #[TestDox('Justificativa que não é esquecimento, ou sem tipo ou hora, não gera batida')]
    public function testSemEsquecimentoCompletoNaoGeraBatida(): void
    {
        $tenant = $this->criarTenant();

        self::assertNull(($this->useCase)($this->justificativa($tenant, 'atestado_medico', null, null), $tenant));
        self::assertNull(($this->useCase)($this->justificativa($tenant, 'esquecimento_registro', null, '08:00'), $tenant));
        self::assertNull(($this->useCase)($this->justificativa($tenant, 'esquecimento_registro', 'entrada', null), $tenant));
    }

    #[TestDox('Justificativa de outro escritório é recusada')]
    public function testOutroEscritorioERecusado(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        $this->expectException(\DomainException::class);
        ($this->useCase)($this->justificativa($tenantB, 'esquecimento_registro', 'entrada', '08:00'), $tenantA);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ESQUECIMENTO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function justificativa(Tenant $tenant, string $tipo, ?string $tipoRegistro, ?string $hora): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus('abonado');
        $justificativa->setTipo($tipo);
        $justificativa->setTipoRegistroEsquecido($tipoRegistro);
        $justificativa->setHoraRegistroEsquecido($hora !== null ? new \DateTime('1970-01-01 ' . $hora . ':00') : null);

        return $justificativa;
    }
}
