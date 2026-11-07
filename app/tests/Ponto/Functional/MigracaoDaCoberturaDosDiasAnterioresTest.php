<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A migration `Version20261107110000` (spec `docs/specs/ponto-jornada-vigencia.md` §10.1): toda jornada que já existia
 * passa a cobrir os dias antes da data dela, para a folha de ninguém mudar; as criadas depois nascem sem a marca.
 *
 * Roda dentro da transação do DAMA: o PostgreSQL desfaz a DDL no fim, e o banco volta como estava.
 */
#[CoversNothing]
final class MigracaoDaCoberturaDosDiasAnterioresTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261107110000';

    #[TestDox('As jornadas que já existiam ficam marcadas como cobrindo os dias anteriores; a coluna nasce falsa')]
    public function testJornadasExistentesCobremOsDiasAnteriores(): void
    {
        self::bootKernel();
        $id = $this->jornadaSemAMarca();
        // Como a migration encontra o banco: toda jornada é de antes das versões.
        $this->banco()->executeStatement('UPDATE jornada_colaborador SET cobre_dias_anteriores = true');

        self::assertSame(0, $this->executar('--down'));
        self::assertFalse($this->coluna(), 'o down tira a coluna');
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));

        self::assertTrue((bool) $this->banco()->fetchOne('SELECT cobre_dias_anteriores FROM jornada_colaborador WHERE id = ?', [$id]));
        self::assertSame('false', $this->banco()->fetchOne(
            "SELECT column_default FROM information_schema.columns WHERE table_name = 'jornada_colaborador' AND column_name = 'cobre_dias_anteriores'",
        ));
    }

    #[TestDox('O down recusa quando há jornada criada pela tela: o up seguinte a faria cobrir os dias anteriores')]
    public function testDownRecusaComJornadaDaTela(): void
    {
        self::bootKernel();
        $this->jornadaSemAMarca();

        try {
            $this->executar('--down');
            self::fail('o down tinha de recusar');
        } catch (AbortMigration $recusa) {
            self::assertStringContainsString('voltar mudaria o saldo', $recusa->getMessage());
        }
        self::assertTrue($this->coluna(), 'a coluna continua lá');
    }

    private function jornadaSemAMarca(): int
    {
        $escritorio = (new Tenant())->setName('Escritório cobertura ' . uniqid());
        $pessoa = (new User())->setEmail('cobertura_' . uniqid() . '@teste.com')->setFullName('Pessoa da cobertura');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setIsActive(true);
        $jornada = (new JornadaColaborador())->setUser($pessoa)->setTenant($escritorio)->setCargaHorariaSemanal(2400);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($escritorio);
        $em->persist($pessoa);
        $em->persist($jornada);
        $em->flush();
        self::assertFalse($jornada->cobreDiasAnteriores());

        return (int) $jornada->getId();
    }

    private function executar(string $sentido): int
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('doctrine:migrations:execute'));

        return $tester->execute(['versions' => [self::VERSAO], $sentido => true], ['interactive' => false]);
    }

    private function coluna(): bool
    {
        return (bool) $this->banco()->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'jornada_colaborador' AND column_name = 'cobre_dias_anteriores'",
        );
    }

    private function banco(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
