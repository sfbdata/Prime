<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `criadorComoResponsavel` do CriarPastaUseCase (Nova demanda das Minhas Demandas, desenho 15). Integração, e não
 * unitário com mock: o UseCase recebe o EntityManager direto (transação do número da pasta), e o EntityManager não
 * se mocka (tests/CLAUDE.md).
 */
#[CoversClass(CriarPastaUseCase::class)]
final class CriarPastaCriadorComoResponsavelTest extends KernelTestCase
{
    #[TestDox('com criadorComoResponsavel, quem cria vira o responsável da pasta')]
    public function testCriadorViraResponsavel(): void
    {
        $pasta = $this->criar(true);

        self::assertNotNull($pasta->getResponsavel());
        self::assertSame($pasta->getCriadoPor()?->getId(), $pasta->getResponsavel()->getId());
    }

    #[TestDox('sem criadorComoResponsavel (padrão do Expediente), a pasta nasce sem responsável')]
    public function testSemOpcaoFicaSemResponsavel(): void
    {
        $pasta = $this->criar(false);

        self::assertNull($pasta->getResponsavel());
        self::assertNotNull($pasta->getCriadoPor());
    }

    private function criar(bool $criadorComoResponsavel): Pasta
    {
        $tenant  = TenantFactory::createOne()->_real();
        $criador = UserFactory::createOne()->_real();
        $useCase = static::getContainer()->get(CriarPastaUseCase::class);

        $pasta = $useCase->executar(
            new CriarPastaDTO(nomeCliente: 'Cliente Demanda', criadorComoResponsavel: $criadorComoResponsavel),
            $criador,
            $tenant,
        );

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = $pasta->getId();
        $em->clear();
        $relida = $em->find(Pasta::class, $id);
        self::assertInstanceOf(Pasta::class, $relida);

        return $relida;
    }
}
