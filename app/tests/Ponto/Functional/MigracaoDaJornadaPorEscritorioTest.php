<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A migration `Version20261020150700` desce e sobe de verdade no banco da frente (spec seguranca-b-jornada §4). Roda
 * dentro da transação do DAMA: o PostgreSQL desfaz a DDL no fim, e o banco volta como estava.
 */
#[CoversNothing]
final class MigracaoDaJornadaPorEscritorioTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261020150700';

    #[TestDox('down tira a coluna da jornada e a chave; up devolve as duas, com o índice único por pessoa e escritório')]
    public function testDownEUp(): void
    {
        self::bootKernel();
        $banco = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        self::assertSame(0, $this->executar('--down'));
        self::assertFalse($this->coluna($banco, 'jornada_colaborador', 'tenant_id'));
        self::assertFalse($this->coluna($banco, 'user_tenant', 'registra_ponto'));

        // A migration executada fica congelada na fábrica de dependências: o up precisa de um kernel novo. A conexão do
        // DAMA é estática e segue a mesma, dentro da mesma transação.
        self::ensureKernelShutdown();
        self::bootKernel();
        $banco = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, $this->executar('--up'));
        self::assertTrue($this->coluna($banco, 'jornada_colaborador', 'tenant_id'));
        self::assertTrue($this->coluna($banco, 'user_tenant', 'registra_ponto'));
        self::assertSame(1, (int) $banco->fetchOne("SELECT COUNT(*) FROM pg_indexes WHERE indexname = 'uq_jornada_colaborador_user_tenant'"));
    }

    private function executar(string $sentido): int
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('doctrine:migrations:execute'));

        return $tester->execute(['versions' => [self::VERSAO], $sentido => true], ['interactive' => false]);
    }

    private function coluna(Connection $banco, string $tabela, string $coluna): bool
    {
        return (bool) $banco->fetchOne(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$tabela, $coluna],
        );
    }
}
