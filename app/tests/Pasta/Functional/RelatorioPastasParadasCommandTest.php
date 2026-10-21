<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Command\RelatorioPastasParadasCommand;
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
 * `app:pastas:relatorio-pastas-paradas` (spec docs/specs/pastas-paradas.md, "Envio automático"), no
 * molde do `app:pastas:relatorio-sem-processo`. O relógio é injetado (MockClock no UseCase), para
 * provar o comportamento numa primeira segunda-feira sem depender do dia em que a suíte roda.
 */
#[CoversClass(RelatorioPastasParadasCommand::class)]
#[Group('pasta')]
final class RelatorioPastasParadasCommandTest extends KernelTestCase
{
    use CriaEscritorioPastasParadasTrait;

    private const PRIMEIRA_SEGUNDA = '2026-10-05 10:05:00';

    private function tester(string $agora = self::PRIMEIRA_SEGUNDA): CommandTester
    {
        self::bootKernel();
        $c = static::getContainer();

        return new CommandTester(new RelatorioPastasParadasCommand(
            $c->get(TenantRepository::class),
            $this->enviarParadasComRelogio($agora),
            $this->em(),
            $c->get(ManagerRegistry::class),
        ));
    }

    private function escritorio(string $nome = 'Comando'): Tenant
    {
        $tenant = $this->novoEscritorio($nome);
        $paula  = $this->novaPessoa($tenant, 'Paula Primária', $this->novoPerfil($tenant, true));
        $this->pastaAbertaEm($tenant, '1180', '2026-06-01', $paula);

        return $tenant;
    }

    #[TestDox('--dry-run numa primeira segunda-feira: simula (linha "sim", 1 parada, 2 avisos: a primária é também a responsável), sem gravar aviso nem o mês')]
    public function testDryRunNaPrimeiraSegunda(): void
    {
        $tester = $this->tester();
        $tenant = $this->escritorio();

        $codigo = $tester->execute(['--dry-run' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $codigo);
        self::assertStringContainsString('--dry-run', $tester->getDisplay());
        self::assertMatchesRegularExpression('/' . preg_quote((string) $tenant->getName(), '/') . '\s+sim\s+1\s+2\s/u', $tester->getDisplay());
        self::assertSame([], $this->avisosDasParadas($tenant));
        self::assertSame(0, $this->enviosDasParadas($tenant));
    }

    #[TestDox('Sem --dry-run, na primeira segunda-feira: envia e grava o mês; noutro dia, não envia')]
    public function testEnvioRealSoNaPrimeiraSegunda(): void
    {
        $tenant = $this->escritorio();

        self::assertSame(Command::SUCCESS, $this->tester('2026-10-13 10:05:00')->execute(['--tenant' => (string) $tenant->getId()]));
        self::assertSame(0, $this->enviosDasParadas($tenant));

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--tenant' => (string) $tenant->getId()]));
        self::assertSame(1, $this->enviosDasParadas($tenant));
        self::assertCount(1, $this->avisosDasParadas($tenant));
    }

    #[TestDox('Sem --tenant: passa por todos os escritórios ativos, cada um com os próprios avisos')]
    public function testTodosOsEscritorios(): void
    {
        $tester = $this->tester();
        $a      = $this->escritorio('Primeiro');
        $b      = $this->escritorio('Segundo');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, $this->enviosDasParadas($a));
        self::assertSame(1, $this->enviosDasParadas($b));
        self::assertCount(1, $this->avisosDasParadas($a));
        self::assertCount(1, $this->avisosDasParadas($b));
    }

    #[TestDox('Lock: com outra execução em andamento, avisa e não envia nada')]
    public function testLockContraExecucaoSobreposta(): void
    {
        $tester = $this->tester();
        $tenant = $this->escritorio();
        $outro  = fopen(sys_get_temp_dir() . '/jusprime-relatorio-pastas-paradas.lock', 'c');
        self::assertNotFalse($outro);
        self::assertTrue(flock($outro, \LOCK_EX | \LOCK_NB));

        try {
            $codigo = $tester->execute(['--tenant' => (string) $tenant->getId()]);
        } finally {
            flock($outro, \LOCK_UN);
            fclose($outro);
        }

        self::assertSame(Command::SUCCESS, $codigo);
        self::assertStringContainsString('já está em andamento', $tester->getDisplay());
        self::assertSame(0, $this->enviosDasParadas($tenant));
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
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:pastas:relatorio-pastas-paradas'));

        self::assertSame(Command::FAILURE, $tester->execute(['--tenant' => '999999999']));
        self::assertStringContainsString('não encontrado', $tester->getDisplay());
    }
}
