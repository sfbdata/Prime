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

    #[TestDox('com o segredo da Assinatura e a chave da selfie do ambiente iguais aos do modelo, falha sem mostrar os valores')]
    public function testAssinaturaIgualAoModeloFalhaSemMostrarOsValores(): void
    {
        self::bootKernel();
        $segredo = (string) static::getContainer()->getParameter('assinatura.segredo');
        $chave = (string) static::getContainer()->getParameter('assinatura.selfie_chave');
        self::assertNotSame('', $segredo);
        self::assertNotSame('', $chave);

        $tester = $this->tester();
        $tester->setInputs(["ASSINATURA_SEGREDO={$segredo}\nASSINATURA_SELFIE_CHAVE={$chave}"]);
        $codigo = $tester->execute(['--modelo' => '-']);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('ASSINATURA_SEGREDO', $tester->getDisplay());
        self::assertStringContainsString('ASSINATURA_SELFIE_CHAVE', $tester->getDisplay());
        self::assertFalse(str_contains($tester->getDisplay(), $segredo), 'O valor do segredo não pode aparecer na saída.');
        self::assertFalse(str_contains($tester->getDisplay(), $chave), 'O valor da chave não pode aparecer na saída.');
    }

    #[TestDox('com o segredo do selo da Assinatura do ambiente igual ao do modelo, falha sem mostrar o valor')]
    public function testSeloIgualAoModeloFalhaSemMostrarOValor(): void
    {
        self::bootKernel();
        $selo = (string) static::getContainer()->getParameter('assinatura.selo_segredo');
        self::assertGreaterThanOrEqual(32, \strlen($selo), 'o .env.test tem o segredo do selo');

        $tester = $this->tester();
        $tester->setInputs(["ASSINATURA_SELO_SEGREDO={$selo}"]);
        $codigo = $tester->execute(['--modelo' => '-']);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('ASSINATURA_SELO_SEGREDO', $tester->getDisplay());
        self::assertFalse(str_contains($tester->getDisplay(), $selo), 'O valor do segredo não pode aparecer na saída.');
    }

    #[TestDox('confere o token do validador de PDF do ambiente: vazio (o .env.test) aponta o runbook; igual ao modelo é recusado; nunca mostra o valor')]
    public function testValidadorConfereOTokenDoAmbienteSemMostrarOValor(): void
    {
        // O .env.test deixa o token vazio; no contêiner de dev ele pode chegar pelo .env da raiz (runbook, seção Dev).
        $token = (string) ($_SERVER['ASSINATURA_VALIDADOR_TOKEN'] ?? '');

        $tester = $this->tester();
        $tester->setInputs(["ASSINATURA_VALIDADOR_TOKEN={$token}"]);
        $codigo = $tester->execute(['--modelo' => '-']);

        self::assertSame(Command::FAILURE, $codigo);
        $saida = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('ASSINATURA_VALIDADOR_TOKEN', $saida);
        if (trim($token) === '') {
            self::assertStringContainsString('ASSINATURA_VALIDADOR_TOKEN não está definido', $saida);
            self::assertStringContainsString('assinatura-validador-em-producao.md', $saida);

            return;
        }
        self::assertFalse(str_contains($tester->getDisplay(), trim($token)), 'O valor do token não pode aparecer na saída.');
    }

    private function tester(): CommandTester
    {
        self::bootKernel();

        return new CommandTester((new Application(self::$kernel))->find('app:conferir-segredos'));
    }
}
