<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A migration `Version20261109200000` (spec `docs/specs/ponto-desenho-0811.md`, E6, P71 com o desvio da E6): o
 * feriado ganha a lotação opcional; os que já existiam ficam sem ela (valem para todo o escritório, nenhuma folha
 * muda); apagar a lotação apaga os feriados só dela. O down tira a coluna.
 *
 * Roda dentro da transação do DAMA: o PostgreSQL desfaz a DDL no fim, e o banco volta como estava.
 */
#[CoversNothing]
#[Group('ponto')]
final class MigracaoDoFeriadoPorLotacaoTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261109200000';

    #[TestDox('Down tira a coluna; up devolve a coluna opcional, com índice e chave que apaga junto com a lotação; o feriado que existia fica para todos')]
    public function testDownEUp(): void
    {
        self::bootKernel();
        [, $todas] = $this->feriados();

        self::assertSame(0, $this->executar('--down'));
        self::assertFalse($this->coluna(), 'o down tira a coluna');
        self::assertSame(1, (int) $this->banco()->fetchOne('SELECT COUNT(*) FROM feriado WHERE id = ?', [$todas]), 'o feriado continua');
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));

        self::assertTrue($this->coluna());
        self::assertSame('YES', $this->banco()->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'feriado' AND column_name = 'lotacao_id'",
        ));
        self::assertNull($this->banco()->fetchOne('SELECT lotacao_id FROM feriado WHERE id = ?', [$todas]), 'o que existia vale para todo o escritório');
        self::assertSame('c', $this->banco()->fetchOne(
            "SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_7d8640137e27c2b8'",
        ), 'ON DELETE CASCADE');
        self::assertSame(1, (int) $this->banco()->fetchOne(
            "SELECT COUNT(*) FROM pg_indexes WHERE tablename = 'feriado' AND indexname = 'idx_7d8640137e27c2b8'",
        ));
    }

    #[TestDox('Apagar a lotação apaga os feriados só dela; o de "Todas as sedes" fica')]
    public function testApagarALotacaoApagaOsFeriadosDela(): void
    {
        self::bootKernel();
        [$daLotacao, $todas, $lotacao] = $this->feriados();

        $this->banco()->executeStatement('DELETE FROM lotacao WHERE id = ?', [$lotacao]);

        self::assertSame(0, (int) $this->banco()->fetchOne('SELECT COUNT(*) FROM feriado WHERE id = ?', [$daLotacao]));
        self::assertSame(1, (int) $this->banco()->fetchOne('SELECT COUNT(*) FROM feriado WHERE id = ?', [$todas]));
    }

    /** @return array{0: int, 1: int, 2: int} o feriado da lotação, o de todos e a lotação */
    private function feriados(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $escritorio = (new Tenant())->setName('Escritório feriado por lotação ' . uniqid());
        $lotacao = (new Lotacao())->setNome('MACEIÓ/AL')->setTenant($escritorio);
        $daLotacao = (new Feriado())->setNome('Emancipação de Alagoas')->setData(new \DateTime('2026-09-16'))->setTenant($escritorio)->setLotacao($lotacao);
        $todas = (new Feriado())->setNome('Padroeira')->setData(new \DateTime('2026-08-27'))->setTenant($escritorio);
        foreach ([$escritorio, $lotacao, $daLotacao, $todas] as $entidade) {
            $em->persist($entidade);
        }
        $em->flush();

        return [(int) $daLotacao->getId(), (int) $todas->getId(), (int) $lotacao->getId()];
    }

    private function executar(string $sentido): int
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('doctrine:migrations:execute'));

        return $tester->execute(['versions' => [self::VERSAO], $sentido => true], ['interactive' => false]);
    }

    private function coluna(): bool
    {
        return (bool) $this->banco()->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'feriado' AND column_name = 'lotacao_id'",
        );
    }

    private function banco(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
