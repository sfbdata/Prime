<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Contract\TravaDosAvisosDeMencao;
use App\Central\Service\TravaPostgresDosAvisosDeMencao;
use App\Entity\Auth\User;
use App\Tests\Inteligencia\Support\DefineId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A trava do aviso de menção exclui de verdade entre SESSÕES do Postgres — é isso que impede dois envios
 * simultâneos de gravarem dois avisos. A sessão do teste (aberta em transação pelo DAMA) segura a trava; uma
 * segunda conexão, independente, tenta pegá-la sem esperar (`pg_try_advisory_xact_lock`).
 */
#[CoversClass(TravaPostgresDosAvisosDeMencao::class)]
final class TravaPostgresDosAvisosDeMencaoTest extends KernelTestCase
{
    private function pessoa(int $id): User
    {
        $u = new User();
        DefineId::em($u, $id);

        return $u;
    }

    private function outraSessao(): Connection
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return DriverManager::getConnection($em->getConnection()->getParams());
    }

    #[TestDox('Fora de transação a trava se recusa — ela cairia no mesmo instante e a corrida voltaria calada')]
    public function testExigeTransacao(): void
    {
        self::bootKernel();
        $trava = static::getContainer()->get(TravaDosAvisosDeMencao::class);

        $this->expectException(\LogicException::class);
        $trava->travar($this->pessoa(910001));
    }

    #[TestDox('Enquanto uma sessão segura a trava de uma pessoa, outra sessão não a obtém; a de outra pessoa, sim')]
    public function testExcluiEntreSessoes(): void
    {
        self::bootKernel();
        /** @var TravaDosAvisosDeMencao $trava */
        $trava = static::getContainer()->get(TravaDosAvisosDeMencao::class);
        self::assertInstanceOf(TravaPostgresDosAvisosDeMencao::class, $trava, 'a interface aponta para a trava do Postgres');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$mesma, $diferente] = $em->wrapInTransaction(function () use ($trava): array {
            $trava->travar($this->pessoa(910001));

            $outra = $this->outraSessao();
            try {
                $outra->beginTransaction();
                $resultado = [
                    $outra->fetchOne('SELECT pg_try_advisory_xact_lock(?, ?)', [TravaPostgresDosAvisosDeMencao::CLASSE_TRAVA, 910001]),
                    $outra->fetchOne('SELECT pg_try_advisory_xact_lock(?, ?)', [TravaPostgresDosAvisosDeMencao::CLASSE_TRAVA, 910002]),
                ];
                $outra->rollBack();
            } finally {
                $outra->close();
            }

            return $resultado;
        });

        self::assertFalse((bool) $mesma, 'a mesma pessoa fica travada para a outra sessão');
        self::assertTrue((bool) $diferente, 'outra pessoa não é afetada');
    }
}
