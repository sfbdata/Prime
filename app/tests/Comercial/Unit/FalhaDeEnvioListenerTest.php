<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\EventListener\FalhaDeEnvioListener;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Tests\Comercial\Support\LogEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

#[CoversClass(FalhaDeEnvioListener::class)]
final class FalhaDeEnvioListenerTest extends TestCase
{
    private static function evento(object $mensagem, \Throwable $erro, bool $comRetry): WorkerMessageFailedEvent
    {
        $evento = new WorkerMessageFailedEvent(new Envelope($mensagem), 'comercial', $erro);
        if ($comRetry) {
            $evento->setForRetry();
        }

        return $evento;
    }

    #[TestDox('Sem retry: marca falhou com o erro sanitizado e apaga o conteúdo; o log não leva endereço')]
    public function testSemRetryMarcaFalhou(): void
    {
        $envios = $this->createMock(EnvioDeEmailRepository::class);
        $envios->expects(self::once())->method('marcarFalhou')
            ->with(12, 'Expected response code "250" but got "550" for <<e-mail>>, cópia <e-mail> (recusado).');
        $envios->expects(self::once())->method('apagarConteudo')->with(12);
        $log = new LogEmMemoria();
        $erro = new \RuntimeException('Expected response code "250" but got "550" for <ana@exemplo.com>, cópia bia.souza+x@sub.exemplo.com.br (recusado).');
        $embrulhada = new HandlerFailedException(new Envelope(new EnviarEmail(12)), [$erro]);

        (new FalhaDeEnvioListener($envios, $log))(self::evento(new EnviarEmail(12), $embrulhada, false));

        self::assertStringNotContainsString('@', $log->tudo);
    }

    #[TestDox('Com retry pela frente: não mexe no envio')]
    public function testComRetryNaoMexe(): void
    {
        $envios = $this->createMock(EnvioDeEmailRepository::class);
        $envios->expects(self::never())->method('marcarFalhou');
        $envios->expects(self::never())->method('apagarConteudo');

        (new FalhaDeEnvioListener($envios, new LogEmMemoria()))(self::evento(new EnviarEmail(12), new \RuntimeException('x'), true));
    }

    #[TestDox('Outra mensagem (não EnviarEmail): ignora')]
    public function testOutraMensagem(): void
    {
        $envios = $this->createMock(EnvioDeEmailRepository::class);
        $envios->expects(self::never())->method('marcarFalhou');

        (new FalhaDeEnvioListener($envios, new LogEmMemoria()))(self::evento(new VarrerAutomacoes(), new \RuntimeException('x'), false));
    }

    #[TestDox('Sanitizar: troca todo e-mail, corta em 500 caracteres e nunca devolve vazio')]
    public function testSanitizar(): void
    {
        self::assertSame('<e-mail> e <e-mail>', FalhaDeEnvioListener::sanitizar('ana@exemplo.com e JOAO@EXEMPLO.COM'));
        self::assertSame(500, mb_strlen(FalhaDeEnvioListener::sanitizar(str_repeat('á', 800))));
        self::assertSame('Falha no envio.', FalhaDeEnvioListener::sanitizar('   '));
    }
}
