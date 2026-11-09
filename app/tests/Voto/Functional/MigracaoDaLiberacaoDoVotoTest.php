<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A migration `Version20261109140000` (spec bluejus-voto §7, M1b) desce e sobe de verdade no banco de teste, dentro da
 * transação do DAMA (o banco volta como estava; o Postgres desfaz DDL na transação). Prova o que o mapeamento não
 * prova: o schema `voto` some e volta, a tabela nasce com a unicidade nomeada por escritório e a chave estrangeira
 * apaga a liberação junto com o escritório (a purga definitiva só enxerga o schema `public`).
 */
#[CoversNothing]
final class MigracaoDaLiberacaoDoVotoTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261109140000';

    #[TestDox('down tira a tabela voto.liberacao e o schema voto; up devolve os dois, com a unicidade por escritório e o padrão não liberado')]
    public function testDownEUp(): void
    {
        self::bootKernel();
        self::assertSame(0, $this->executar('--down'));
        $banco = $this->banco();
        self::assertFalse($this->schemaExiste($banco), 'o down tira o schema voto');
        self::assertFalse($this->tabelaExiste($banco), 'o down tira a tabela voto.liberacao');

        // A migration executada fica congelada na fábrica de dependências: cada execução precisa de um kernel novo.
        // A conexão do DAMA é estática e segue a mesma, dentro da mesma transação.
        $this->subir();
        $banco = $this->banco();
        self::assertTrue($this->schemaExiste($banco));
        self::assertTrue($this->tabelaExiste($banco));
        self::assertSame(
            [
                ['column_name' => 'id', 'is_nullable' => 'NO', 'column_default' => null],
                ['column_name' => 'liberado', 'is_nullable' => 'NO', 'column_default' => 'false'],
                ['column_name' => 'criada_em', 'is_nullable' => 'NO', 'column_default' => null],
                ['column_name' => 'atualizada_em', 'is_nullable' => 'NO', 'column_default' => null],
                ['column_name' => 'tenant_id', 'is_nullable' => 'NO', 'column_default' => null],
            ],
            $banco->fetchAllAssociative(
                "SELECT column_name, is_nullable, column_default FROM information_schema.columns
                  WHERE table_schema = 'voto' AND table_name = 'liberacao' ORDER BY ordinal_position",
            ),
        );
        self::assertSame(
            'CREATE UNIQUE INDEX uniq_voto_liberacao_tenant ON voto.liberacao USING btree (tenant_id)',
            $banco->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'voto' AND indexname = 'uniq_voto_liberacao_tenant'"),
        );
        self::assertSame(
            ['tabela' => 'tenant', 'ao_apagar' => 'c'],
            $banco->fetchAssociative(
                "SELECT confrelid::regclass::text AS tabela, confdeltype::text AS ao_apagar FROM pg_constraint
                  WHERE conrelid = 'voto.liberacao'::regclass AND contype = 'f'",
            ),
            'chave estrangeira para tenant com ON DELETE CASCADE',
        );
    }

    #[TestDox('apagar o escritório apaga a liberação dele junto, e só a dele')]
    public function testApagarOEscritorioApagaALiberacao(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $apagado = (new Tenant())->setName('Escritório apagado ' . uniqid());
        $mantido = (new Tenant())->setName('Escritório mantido ' . uniqid());
        $em->persist($apagado);
        $em->persist($mantido);
        $em->flush();
        $banco = $this->banco();
        foreach ([$apagado, $mantido] as $tenant) {
            $banco->executeStatement(
                'INSERT INTO voto.liberacao (tenant_id, liberado, criada_em, atualizada_em) VALUES (?, TRUE, NOW(), NOW())',
                [$tenant->getId()],
            );
        }

        $banco->executeStatement('DELETE FROM tenant WHERE id = ?', [$apagado->getId()]);

        self::assertSame(0, $this->liberacoesDo($banco, (int) $apagado->getId()));
        self::assertSame(1, $this->liberacoesDo($banco, (int) $mantido->getId()));
    }

    private function subir(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));
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

    private function schemaExiste(Connection $banco): bool
    {
        return (int) $banco->fetchOne("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = 'voto'") === 1;
    }

    private function tabelaExiste(Connection $banco): bool
    {
        return (int) $banco->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'voto' AND table_name = 'liberacao'") === 1;
    }

    private function liberacoesDo(Connection $banco, int $tenantId): int
    {
        return (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.liberacao WHERE tenant_id = ?', [$tenantId]);
    }
}
