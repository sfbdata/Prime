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
 * Migration das ocorrências e avaliações do conselho (spec voto-avaliacoes-do-conselho-1611, seção 1): a tabela, o índice
 * único PARCIAL de uma avaliação pendente por unidade, as FKs com CASCADE e as duas colunas do hash na assembleia. O down
 * as tira sem tocar no resto do schema `voto`.
 */
#[CoversNothing]
final class MigracaoDasAvaliacoesDoVotoTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261117050000';

    #[TestDox('down tira a tabela e as colunas do hash; up devolve tudo, com o índice parcial e as FKs em cascata')]
    public function testDownEUp(): void
    {
        self::bootKernel();
        self::assertSame(0, $this->executar('--down'));
        $banco = $this->banco();
        self::assertFalse($this->tabelaExiste($banco), 'o down tira voto.ocorrencia_da_unidade');
        self::assertSame(0, $this->colunasDoHash($banco), 'o down tira hash_da_lista e lista_travada_em');
        self::assertSame(1, (int) $banco->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'voto' AND table_name = 'assembleia'"), 'a assembleia continua');

        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));
        $banco = $this->banco();
        self::assertTrue($this->tabelaExiste($banco));
        self::assertSame(2, $this->colunasDoHash($banco));
        self::assertSame(
            "CREATE UNIQUE INDEX uniq_voto_ocorrencia_pendente ON voto.ocorrencia_da_unidade USING btree (unidade_id) WHERE ((avaliacao)::text = 'pendente'::text)",
            $banco->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'voto' AND indexname = 'uniq_voto_ocorrencia_pendente'"),
        );
        self::assertEqualsCanonicalizing(
            ['tenant:c', 'assembleia:c', 'assembleia_unidade:c'],
            array_map(
                static fn (array $f): string => $f['tabela'] . ':' . $f['ao_apagar'],
                $banco->fetchAllAssociative(
                    "SELECT regexp_replace(confrelid::regclass::text, '^voto\\.', '') AS tabela, confdeltype::text AS ao_apagar FROM pg_constraint
                      WHERE conrelid = 'voto.ocorrencia_da_unidade'::regclass AND contype = 'f'",
                ),
            ),
            'FKs para tenant, assembleia e unidade, todas com ON DELETE CASCADE',
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
        return 1 === (int) $banco->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'voto' AND table_name = 'ocorrencia_da_unidade'");
    }

    private function colunasDoHash(Connection $banco): int
    {
        return (int) $banco->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'voto' AND table_name = 'assembleia' AND column_name IN ('hash_da_lista', 'lista_travada_em')",
        );
    }
}
