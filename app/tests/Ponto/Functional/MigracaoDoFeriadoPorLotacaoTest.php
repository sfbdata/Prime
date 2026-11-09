<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Exception\AbortMigration;
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
 * muda); apagar a lotação apaga os feriados só dela. Lotação com vigência: a tabela `lotacao_vigencia` nasce com uma
 * versão inicial por vínculo (a lotação de hoje, sem data: vale desde sempre). O down tira a tabela e a coluna, e
 * recusa quando alguém já trocou de lotação.
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

    #[TestDox('Up cria uma versão inicial por vínculo, com a lotação de hoje e sem data (desde sempre); down tira a tabela')]
    public function testVersaoInicialPorVinculo(): void
    {
        self::bootKernel();
        [$escritorio, $lotacao, $comLotacao, $semLotacao] = $this->vinculos();

        self::assertSame(0, $this->executar('--down'));
        self::assertFalse($this->tabela(), 'o down tira a tabela');
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));

        $versoes = $this->banco()->fetchAllAssociative(
            'SELECT user_id, lotacao_id, vale_a_partir_de FROM lotacao_vigencia WHERE tenant_id = ? ORDER BY user_id',
            [$escritorio],
        );
        self::assertSame([
            ['user_id' => $comLotacao, 'lotacao_id' => $lotacao, 'vale_a_partir_de' => null],
            ['user_id' => $semLotacao, 'lotacao_id' => null, 'vale_a_partir_de' => null],
        ], $versoes);
        self::assertSame(
            (int) $this->banco()->fetchOne('SELECT COUNT(*) FROM user_tenant'),
            (int) $this->banco()->fetchOne('SELECT COUNT(*) FROM lotacao_vigencia'),
            'uma versão por vínculo',
        );
        self::assertSame('n', $this->banco()->fetchOne("SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_2889a82c7e27c2b8'"), 'lotação apagada: SET NULL');
        self::assertSame('c', $this->banco()->fetchOne("SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_2889a82ca76ed395'"), 'pessoa apagada: CASCADE');
        self::assertSame('c', $this->banco()->fetchOne("SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_2889a82c9033212a'"), 'escritório apagado: CASCADE');
    }

    #[TestDox('Down recusa quando alguém já trocou de lotação (versão com data): não apaga o histórico em silêncio')]
    public function testDownRecusaComTroca(): void
    {
        self::bootKernel();
        [$escritorio, $lotacao, $comLotacao] = $this->vinculos();
        $this->banco()->executeStatement(
            'INSERT INTO lotacao_vigencia (user_id, tenant_id, lotacao_id, vale_a_partir_de) VALUES (?, ?, ?, CURRENT_DATE)',
            [$comLotacao, $escritorio, $lotacao],
        );

        try {
            $this->executar('--down');
            self::fail('o down devia recusar');
        } catch (AbortMigration $recusa) {
            self::assertStringContainsString('voltar apagaria o histórico da lotação', $recusa->getMessage());
        }
        self::assertTrue($this->tabela(), 'a tabela fica');
        self::assertTrue($this->coluna(), 'a coluna do feriado fica');
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

    /** @return array{0: int, 1: int, 2: int, 3: int} o escritório, a lotação, quem tem a lotação e quem está sem */
    private function vinculos(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $escritorio = (new Tenant())->setName('Escritório lotação com vigência ' . uniqid());
        $lotacao = (new Lotacao())->setNome('MACEIÓ/AL')->setTenant($escritorio);
        $ana = (new User())->setEmail('lv_ana_' . uniqid() . '@test.com')->setFullName('Ana')->setRoles(['ROLE_USER'])->setPassword('x');
        $bruno = (new User())->setEmail('lv_bruno_' . uniqid() . '@test.com')->setFullName('Bruno')->setRoles(['ROLE_USER'])->setPassword('x');
        foreach ([$escritorio, $lotacao, $ana, $bruno, (new UserTenant($ana, $escritorio))->setLotacao($lotacao), new UserTenant($bruno, $escritorio)] as $entidade) {
            $em->persist($entidade);
        }
        $em->flush();
        $em->getConnection()->executeStatement('DELETE FROM lotacao_vigencia WHERE tenant_id = ?', [$escritorio->getId()]);

        return [(int) $escritorio->getId(), (int) $lotacao->getId(), (int) $ana->getId(), (int) $bruno->getId()];
    }

    private function tabela(): bool
    {
        return $this->banco()->fetchOne("SELECT to_regclass('lotacao_vigencia')") !== null;
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
