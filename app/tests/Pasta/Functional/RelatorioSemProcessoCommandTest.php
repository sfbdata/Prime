<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Command\RelatorioSemProcessoCommand;
use App\Repository\TenantRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:pastas:relatorio-sem-processo` (spec D5). O relógio é injetado (MockClock no UseCase), para
 * provar o comportamento numa primeira segunda-feira sem depender do dia em que a suíte roda.
 */
#[CoversClass(RelatorioSemProcessoCommand::class)]
#[Group('pasta')]
final class RelatorioSemProcessoCommandTest extends KernelTestCase
{
    use CriaEscritorioSemProcessoTrait;

    /** Primeira segunda-feira de outubro de 2026, 10h05. */
    private const PRIMEIRA_SEGUNDA = '2026-10-05 10:05:00';

    private function tester(string $agora = self::PRIMEIRA_SEGUNDA): CommandTester
    {
        self::bootKernel();
        $c = static::getContainer();

        return new CommandTester(new RelatorioSemProcessoCommand(
            $c->get(TenantRepository::class),
            $this->enviarComRelogio($agora),
            $this->em(),
            $c->get(ManagerRegistry::class),
        ));
    }

    private function escritorio(): Tenant
    {
        $tenant = $this->novoEscritorio('Comando');
        $this->novaPessoa($tenant, 'Paula Primária', $this->novoPerfil($tenant, true), controladoria: true);
        $this->novaPasta($tenant, '1180');

        return $tenant;
    }

    private function envios(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM relatorio_sem_processo_envio WHERE tenant_id = ?', [$tenant->getId()]);
    }

    #[TestDox('--dry-run numa primeira segunda-feira: simula o envio (linha "sim"), sem gravar aviso nem histórico')]
    public function testDryRunNaPrimeiraSegunda(): void
    {
        $tester = $this->tester();
        $tenant = $this->escritorio();

        $codigo = $tester->execute(['--dry-run' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $codigo);
        self::assertStringContainsString('--dry-run', $tester->getDisplay());
        self::assertMatchesRegularExpression('/' . preg_quote((string) $tenant->getName(), '/') . '\s+sim\s+1\s/u', $tester->getDisplay());
        self::assertSame([], $this->avisosDoRelatorio($tenant));
        self::assertSame(0, $this->envios($tenant));
    }

    #[TestDox('Sem --dry-run, na primeira segunda-feira: envia e grava o mês; noutro dia, não envia')]
    public function testEnvioRealSoNaPrimeiraSegunda(): void
    {
        $tenant = $this->escritorio();

        self::assertSame(Command::SUCCESS, $this->tester('2026-10-13 10:05:00')->execute(['--tenant' => (string) $tenant->getId()]));
        self::assertSame(0, $this->envios($tenant));

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--tenant' => (string) $tenant->getId()]));
        self::assertSame(1, $this->envios($tenant));
        self::assertCount(1, $this->avisosDoRelatorio($tenant));
    }

    #[TestDox('Erro que fecha o EntityManager num escritório: o comando falha nele e reabre o EntityManager')]
    public function testReabreOEntityManagerFechado(): void
    {
        $tester = $this->tester();
        $tenant = $this->escritorio();
        $this->em()->close();

        $codigo = $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('Falha no escritório #' . $tenant->getId(), $tester->getDisplay());
        self::assertTrue($this->em()->isOpen(), 'o próximo escritório encontra o EntityManager aberto');
    }

    #[TestDox('--tenant inexistente: falha com a mensagem')]
    public function testTenantInexistente(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:pastas:relatorio-sem-processo'));

        self::assertSame(Command::FAILURE, $tester->execute(['--tenant' => '999999999']));
        self::assertStringContainsString('não encontrado', $tester->getDisplay());
    }
}
