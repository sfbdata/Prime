<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Command\TestarConexaoDaInteligenciaCommand;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(TestarConexaoDaInteligenciaCommand::class)]
final class TestarConexaoDaInteligenciaCommandTest extends TestCase
{
    #[TestDox('Sem chave: avisa e sai com 1, sem chamar o provedor')]
    public function testSemChave(): void
    {
        $falso = new ProvedorFalso();
        $falso->desconfigurar();
        $tester = new CommandTester(new TestarConexaoDaInteligenciaCommand($falso));

        self::assertSame(1, $tester->execute([]));
        self::assertFalse($falso->foiChamado());
    }

    #[TestDox('Com chave: chamada mínima (16 tokens) e "conectado (modelo X)"')]
    public function testConectado(): void
    {
        $falso = new ProvedorFalso();
        $falso->responderCom('ok', 'claude-haiku-5-5');
        $tester = new CommandTester(new TestarConexaoDaInteligenciaCommand($falso));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('conectado (modelo claude-haiku-5-5)', $tester->getDisplay());
        self::assertSame(16, $falso->ultimoPedido()?->maxTokens);
    }

    #[TestDox('Falha do provedor: mostra o erro e sai com 1')]
    public function testFalha(): void
    {
        $falso = new ProvedorFalso();
        $falso->falharDefinitivamente('chave recusada (401)');
        $tester = new CommandTester(new TestarConexaoDaInteligenciaCommand($falso));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Falha na conexão', $tester->getDisplay());
    }
}
