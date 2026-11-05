<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Shared\Command\ConferirSegredosCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:conferir-segredos` (auditoria 4.11): a conta é do ConferenteDeSegredos (teste unitário); aqui só a borda.
 */
#[CoversClass(ConferirSegredosCommand::class)]
final class ConferirSegredosCommandTest extends KernelTestCase
{
    #[TestDox('sem o modelo para comparar, falha com mensagem clara')]
    public function testSemModeloFalha(): void
    {
        $tester = $this->tester();
        $codigo = $tester->execute(['--modelo' => sys_get_temp_dir() . '/nao-existe-' . bin2hex(random_bytes(6))]);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('.env.prod.example', $tester->getDisplay());
    }

    #[TestDox('com o APP_SECRET do ambiente igual ao do modelo (lido da entrada padrão), falha sem mostrar o valor')]
    public function testSegredoIgualAoModeloFalhaSemMostrarOValor(): void
    {
        $segredo = (string) static::getContainer()->getParameter('kernel.secret');
        self::assertNotSame('', $segredo);

        $tester = $this->tester();
        $tester->setInputs(["APP_SECRET={$segredo}"]);
        $codigo = $tester->execute(['--modelo' => '-']);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('APP_SECRET', $tester->getDisplay());
        self::assertFalse(str_contains($tester->getDisplay(), $segredo), 'O valor do segredo não pode aparecer na saída.');
    }

    private function tester(): CommandTester
    {
        self::bootKernel();

        return new CommandTester((new Application(self::$kernel))->find('app:conferir-segredos'));
    }
}
