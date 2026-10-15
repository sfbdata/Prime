<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\EspelhoRemoto\Command\ReconciliarEspelhoCommand;
use App\Shared\EspelhoRemoto\Command\ReplicarEspelhoCommand;
use App\Shared\EspelhoRemoto\FabricaDoEspelhoRemoto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Os dois comandos de operador, com a configuração do container de teste — que, como a de
 * produção sem as variáveis, não tem espelho configurado: ninguém consegue replicar por engano.
 */
#[CoversClass(ReplicarEspelhoCommand::class)]
#[CoversClass(ReconciliarEspelhoCommand::class)]
#[CoversClass(FabricaDoEspelhoRemoto::class)]
final class ComandosDoEspelhoTest extends KernelTestCase
{
    #[TestDox('sem ESPELHO_R2_*, replicar e reconciliar recusam com mensagem clara e saem com falha')]
    public function testSemConfiguracaoRecusa(): void
    {
        $aplicacao = new Application(self::bootKernel());

        foreach (['armazenamento:replicar-espelho', 'armazenamento:reconciliar-espelho'] as $nome) {
            $tester = new CommandTester($aplicacao->find($nome));
            $codigo = $tester->execute([]);

            self::assertSame(Command::FAILURE, $codigo, $nome);
            self::assertStringContainsString('não está configurado', $tester->getDisplay(), $nome);
        }
    }

    #[TestDox('replicar recusa --limite menor que 1')]
    public function testLimiteInvalido(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('armazenamento:replicar-espelho'));

        self::assertSame(Command::INVALID, $tester->execute(['--limite' => '0']));
    }

    #[TestDox('a fábrica só se diz configurada com os quatro valores; o segredo não aparece no erro')]
    public function testFabrica(): void
    {
        self::assertFalse((new FabricaDoEspelhoRemoto('https://x.r2.cloudflarestorage.com', 'bucket-a', 'chave', ''))->estaConfigurado());
        self::assertTrue((new FabricaDoEspelhoRemoto('https://x.r2.cloudflarestorage.com', 'bucket-a', 'chave', 'segredo'))->estaConfigurado());

        try {
            (new FabricaDoEspelhoRemoto('', '', 'chave', 'SEGREDO-X'))->criar();
            self::fail('devia recusar');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('SEGREDO-X', $e->getMessage());
        }
    }
}
