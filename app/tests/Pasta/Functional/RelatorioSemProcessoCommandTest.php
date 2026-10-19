<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Command\RelatorioSemProcessoCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:pastas:relatorio-sem-processo` (spec D5): roda por escritório; com `--dry-run` não grava
 * aviso nem histórico, seja qual for o dia.
 */
#[CoversClass(RelatorioSemProcessoCommand::class)]
#[Group('pasta')]
final class RelatorioSemProcessoCommandTest extends KernelTestCase
{
    use CriaEscritorioSemProcessoTrait;

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('app:pastas:relatorio-sem-processo'));
    }

    #[TestDox('--dry-run --tenant: sai com sucesso, mostra a linha do escritório e não grava nada')]
    public function testDryRun(): void
    {
        $tester = $this->tester();
        $tenant = $this->novoEscritorio('Comando');
        $this->novaPessoa($tenant, 'Paula Primária', $this->novoPerfil($tenant, true), controladoria: true);
        $this->novaPasta($tenant, '1180');

        $codigo = $tester->execute(['--dry-run' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $codigo);
        self::assertStringContainsString('--dry-run', $tester->getDisplay());
        self::assertStringContainsString((string) $tenant->getName(), $tester->getDisplay());
        self::assertSame([], $this->avisosDoRelatorio($tenant));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM relatorio_sem_processo_envio WHERE tenant_id = ?', [$tenant->getId()]));
    }

    #[TestDox('--tenant inexistente: falha com a mensagem')]
    public function testTenantInexistente(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--tenant' => '999999999']));
        self::assertStringContainsString('não encontrado', $tester->getDisplay());
    }
}
