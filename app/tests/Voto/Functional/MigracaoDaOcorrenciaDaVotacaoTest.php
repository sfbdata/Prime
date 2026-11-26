<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Migration das ocorrências do eleitor (M4.4): a tabela `voto.ocorrencia_da_votacao`, o índice por assembleia e as FKs
 * (tenant, assembleia e unidade em cascata; a pauta zera). O down só tira essa tabela.
 */
#[CoversNothing]
final class MigracaoDaOcorrenciaDaVotacaoTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261126150000';

    #[TestDox('down tira a tabela; up devolve com o índice por assembleia e as FKs certas')]
    public function testDownEUp(): void
    {
        self::bootKernel();
        self::assertSame(0, $this->executar('--down'));
        self::assertFalse($this->tabelaExiste($this->banco()), 'o down tira voto.ocorrencia_da_votacao');
        self::assertSame(1, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'voto' AND table_name = 'ocorrencia_da_unidade'"), 'a outra tabela de ocorrência continua');

        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));
        $banco = $this->banco();
        self::assertTrue($this->tabelaExiste($banco));
        self::assertSame(
            1,
            (int) $banco->fetchOne("SELECT COUNT(*) FROM pg_indexes WHERE schemaname = 'voto' AND indexname = 'idx_voto_ocorrencia_votacao_assembleia'"),
        );
        self::assertEqualsCanonicalizing(
            ['tenant:c', 'assembleia:c', 'assembleia_unidade:c', 'assembleia_pauta:n'],
            array_map(
                static fn (array $f): string => $f['tabela'] . ':' . $f['ao_apagar'],
                $banco->fetchAllAssociative(
                    "SELECT regexp_replace(confrelid::regclass::text, '^voto\\.', '') AS tabela, confdeltype::text AS ao_apagar FROM pg_constraint
                      WHERE conrelid = 'voto.ocorrencia_da_votacao'::regclass AND contype = 'f'",
                ),
            ),
        );
    }

    private function executar(string $sentido): int
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('doctrine:migrations:execute'));

        return $tester->execute(['versions' => [self::VERSAO], $sentido => true], ['interactive' => false]);
    }

    private function banco(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function tabelaExiste(Connection $banco): bool
    {
        return 1 === (int) $banco->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'voto' AND table_name = 'ocorrencia_da_votacao'");
    }
}
