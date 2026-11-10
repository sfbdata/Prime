<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A migration `Version20261109180000` (spec docs/specs/ponto-desenho-0811.md, E7 e seção 6) desce e sobe de verdade no
 * banco de teste, dentro da transação do DAMA (o banco volta como estava). Prova o que a entidade não prova: os
 * padrões no banco (150 m, 120 h, 6 meses, trabalho externo ligado), a unicidade NOMEADA por escritório e o
 * `SET NULL` de quem alterou; e que nenhum escritório ganha linha (sem linha valem os padrões, P96).
 */
#[CoversNothing]
#[Group('ponto')]
final class MigracaoDaConfiguracaoDoPontoTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261109180000';

    #[TestDox('down tira a tabela; up cria a tabela vazia, com os padrões, a unicidade por escritório e o SET NULL de quem alterou')]
    public function testDownEUp(): void
    {
        self::bootKernel();
        self::assertSame(0, $this->executar('--down'));
        self::assertNull($this->banco()->fetchOne("SELECT to_regclass('public.ponto_config_escritorio')"));

        // A migration executada fica congelada na fábrica de dependências: cada execução precisa de um kernel novo.
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));
        $banco = $this->banco();
        self::assertSame(0, (int) $banco->fetchOne('SELECT COUNT(*) FROM ponto_config_escritorio'), 'nenhum escritório ganha linha');

        $padroes = $banco->fetchAllKeyValue(
            "SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'ponto_config_escritorio' AND column_default IS NOT NULL AND column_name <> 'id'",
        );
        ksort($padroes);
        self::assertSame(
            ['banco_limite_horas' => '120', 'banco_prazo_meses' => '6', 'permite_trabalho_externo' => 'true', 'raio_padrao_metros' => '150'],
            $padroes,
        );
        self::assertSame(
            'CREATE UNIQUE INDEX uniq_ponto_config_escritorio_tenant ON public.ponto_config_escritorio USING btree (tenant_id)',
            $banco->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'uniq_ponto_config_escritorio_tenant'"),
        );
        self::assertSame(
            ['atualizado_por_id' => 'SET NULL', 'tenant_id' => 'NO ACTION'],
            $this->regrasDeExclusao($banco),
            'o escritório sai pela purga (sem cascata); quem alterou some sem levar a linha',
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

    /** @return array<string, string> coluna => regra de exclusão da FK */
    private function regrasDeExclusao(Connection $banco): array
    {
        $regras = $banco->fetchAllKeyValue(
            "SELECT kcu.column_name, rc.delete_rule
               FROM information_schema.referential_constraints rc
               JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name
              WHERE kcu.table_name = 'ponto_config_escritorio'",
        );
        ksort($regras);

        return $regras;
    }
}
