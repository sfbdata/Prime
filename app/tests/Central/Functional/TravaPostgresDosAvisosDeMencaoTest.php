<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Contract\TravaDoGrupo;
use App\Central\Contract\TravaDosAvisosDeMencao;
use App\Central\Entity\Conversa;
use App\Central\Service\TravaPostgresDoGrupo;
use App\Entity\Tenant\Tenant;
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
#[CoversClass(TravaPostgresDoGrupo::class)]
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

    #[TestDox('A trava do grupo (B) também exclui entre sessões, por grupo — e recusa trabalhar fora de transação')]
    public function testTravaDoGrupo(): void
    {
        self::bootKernel();
        /** @var TravaDoGrupo $trava */
        $trava = static::getContainer()->get(TravaDoGrupo::class);
        self::assertInstanceOf(TravaPostgresDoGrupo::class, $trava);
        $grupo = Conversa::grupo(new Tenant(), $this->pessoa(1), 'G', new \DateTimeImmutable());
        DefineId::em($grupo, 920001);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$mesmo, $outro] = $em->wrapInTransaction(function () use ($trava, $grupo): array {
            $trava->travar($grupo);
            $sessao = $this->outraSessao();
            try {
                $sessao->beginTransaction();
                $r = [
                    $sessao->fetchOne('SELECT pg_try_advisory_xact_lock(?, ?)', [TravaPostgresDoGrupo::CLASSE_TRAVA, 920001]),
                    $sessao->fetchOne('SELECT pg_try_advisory_xact_lock(?, ?)', [TravaPostgresDoGrupo::CLASSE_TRAVA, 920002]),
                ];
                $sessao->rollBack();
            } finally {
                $sessao->close();
            }

            return $r;
        });
        self::assertFalse((bool) $mesmo);
        self::assertTrue((bool) $outro);

        $this->expectException(\LogicException::class);
        $trava->travar($grupo);
    }
}
