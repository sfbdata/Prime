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
 * Responsável automático do CriarPastaUseCase (spec `acesso-restrito-e-compartilhar.md`, regra 6): toda pasta nova
 * nasce com quem a criou como responsável; só os caminhos automáticos (acervo, Drive, correção) passam `false`.
 * Integração, e não unitário com mock: o UseCase recebe o EntityManager direto (transação do número da pasta), e o
 * EntityManager não se mocka (tests/CLAUDE.md).
 */
#[CoversClass(CriarPastaUseCase::class)]
final class CriarPastaCriadorComoResponsavelTest extends KernelTestCase
{
    #[TestDox('pelo padrão do DTO, quem cria vira o responsável da pasta')]
    public function testPadraoCriadorViraResponsavel(): void
    {
        $pasta = $this->criar(new CriarPastaDTO(nomeCliente: 'Cliente Demanda'));

        self::assertNotNull($pasta->getResponsavel());
        self::assertSame($pasta->getCriadoPor()?->getId(), $pasta->getResponsavel()->getId());
    }

    #[TestDox('com criadorComoResponsavel false (acervo, Drive, correção), a pasta nasce sem responsável')]
    public function testCaminhoAutomaticoFicaSemResponsavel(): void
    {
        $pasta = $this->criar(new CriarPastaDTO(nomeCliente: 'Cliente Acervo', criadorComoResponsavel: false));

        self::assertNull($pasta->getResponsavel());
        self::assertNotNull($pasta->getCriadoPor());
    }

    private function criar(CriarPastaDTO $dto): Pasta
    {
        $tenant  = TenantFactory::createOne()->_real();
        $criador = UserFactory::createOne()->_real();
        $useCase = static::getContainer()->get(CriarPastaUseCase::class);

        $pasta = $useCase->executar(
            $dto,
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
