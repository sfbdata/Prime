<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Entity\EnvioDeEmail;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Service\Carteiro;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(Carteiro::class)]
final class CarteiroTest extends TestCase
{
    private EnvioDeEmailRepository&MockObject $envios;
    private DescadastroDeEmailInterface&MockObject $descadastro;
    private MessageBusInterface&MockObject $bus;
    private Carteiro $carteiro;

    protected function setUp(): void
    {
        $this->envios = $this->createMock(EnvioDeEmailRepository::class);
        $this->envios->method('emTransacao')->willReturnCallback(static fn (\Closure $trabalho): mixed => $trabalho());
        $this->descadastro = $this->createMock(DescadastroDeEmailInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->carteiro = new Carteiro($this->envios, $this->descadastro, $this->bus, new MockClock('2030-01-15 10:00:00'));
    }

    private static function lista(): MensagemDeEmail
    {
        return new MensagemDeEmail('Ana@Exemplo.com', 'Dicas', '<p>Oi</p>', TipoDeEnvio::Lista, 'automacao:dica', 'comercial_dicas');
    }

    private static function transacional(): MensagemDeEmail
    {
        return new MensagemDeEmail('ana@exemplo.com', 'Boas-vindas', '<p>Oi</p>', TipoDeEnvio::Transacional, 'automacao:val');
    }

    #[TestDox('Lista com endereço descadastrado: grava bloqueado_descadastro, sem conteúdo e sem despachar')]
    public function testDescadastradoNaoEnvia(): void
    {
        $this->descadastro->expects(self::once())->method('estaDescadastrado')
            ->with(null, 'ana@exemplo.com', 'comercial_dicas')->willReturn(true);
        $this->envios->expects(self::once())->method('inserir')
            ->with(self::anything(), self::matchesRegularExpression('/^[a-f0-9]{32}$/'), EnvioDeEmail::BLOQUEADO_DESCADASTRO, self::anything(), null)
            ->willReturn(7);
        $this->envios->expects(self::never())->method('inserirConteudo');
        $this->bus->expects(self::never())->method('dispatch');

        $resultado = $this->carteiro->enviar(self::lista());

        self::assertSame(7, $resultado->envioId);
        self::assertSame(ResultadoDoEnvio::BLOQUEADO_DESCADASTRO, $resultado->situacao);
        self::assertFalse($resultado->foiEnfileirado());
    }

    #[TestDox('Lista com endereço liberado: grava enfileirado com o conteúdo e despacha EnviarEmail(id)')]
    public function testListaLiberadaEnfileira(): void
    {
        $this->descadastro->method('estaDescadastrado')->willReturn(false);
        $agora = new \DateTimeImmutable('2030-01-15 10:00:00');
        $this->envios->expects(self::once())->method('inserir')
            ->with(self::anything(), self::anything(), EnvioDeEmail::ENFILEIRADO, $agora, $agora)->willReturn(9);
        $this->envios->expects(self::once())->method('inserirConteudo')->with(9, self::anything());
        $this->bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof EnviarEmail && $m->envioId === 9))
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $resultado = $this->carteiro->enviar(self::lista());

        self::assertTrue($resultado->foiEnfileirado());
        self::assertSame(9, $resultado->envioId);
    }

    #[TestDox('Transacional não consulta o descadastro e é enfileirado')]
    public function testTransacionalNaoConsulta(): void
    {
        $this->descadastro->expects(self::never())->method('estaDescadastrado');
        $this->envios->method('inserir')->willReturn(3);
        $this->bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        self::assertTrue($this->carteiro->enviar(self::transacional())->foiEnfileirado());
    }

    #[TestDox('O registro e o despacho acontecem dentro do trabalho da transação (nada fora dela)')]
    public function testTudoDentroDaTransacao(): void
    {
        $dentro = false;
        $passos = [];
        $envios = $this->createMock(EnvioDeEmailRepository::class);
        $envios->method('emTransacao')->willReturnCallback(static function (\Closure $trabalho) use (&$dentro): mixed {
            $dentro = true;
            try {
                return $trabalho();
            } finally {
                $dentro = false;
            }
        });
        $envios->method('inserir')->willReturnCallback(static function () use (&$dentro, &$passos): int {
            $passos[] = 'inserir:' . ($dentro ? 'dentro' : 'fora');

            return 1;
        });
        $this->bus->method('dispatch')->willReturnCallback(static function (object $m) use (&$dentro, &$passos): Envelope {
            $passos[] = 'despachar:' . ($dentro ? 'dentro' : 'fora');

            return new Envelope($m);
        });

        (new Carteiro($envios, $this->descadastro, $this->bus, new MockClock()))->enviar(self::transacional());

        self::assertSame(['inserir:dentro', 'despachar:dentro'], $passos);
    }

    #[TestDox('Mensagem incoerente é recusada antes de chegar ao Carteiro (lista em transacional)')]
    public function testMensagemIncoerente(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MensagemDeEmail('ana@exemplo.com', 'X', '<p>x</p>', TipoDeEnvio::Transacional, 'teste', 'comercial_dicas');
    }
}
