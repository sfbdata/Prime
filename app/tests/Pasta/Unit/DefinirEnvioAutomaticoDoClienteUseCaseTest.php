<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Cliente\Repository\ClienteEmailRepository;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use App\Pasta\UseCase\DefinirEnvioAutomaticoDoClienteUseCase;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Um switch do "Envio automático" (spec push-envio-automatico §2): estado desejado, ligar exige e-mail
 * cadastrado, desligar sempre, pasta sem cliente recusa.
 */
#[CoversClass(DefinirEnvioAutomaticoDoClienteUseCase::class)]
final class DefinirEnvioAutomaticoDoClienteUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private Pasta $pasta;
    private ClientePF $cliente;
    private ?EnvioAutomaticoDoCliente $linha = null;
    private EnvioAutomaticoDoClienteRepository&MockObject $automaticos;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->cliente = new ClientePF();
        $this->cliente->setTenant($this->tenant);
        $this->cliente->setEmail('maria@ficha.com.br');
        DefineId::em($this->cliente, 50);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->addCliente($this->cliente);
        $this->automaticos = $this->createMock(EnvioAutomaticoDoClienteRepository::class);
        $this->automaticos->method('doClienteDoTenant')->willReturnCallback(fn (): ?EnvioAutomaticoDoCliente => $this->linha);
        $this->automaticos->method('garantirDoCliente')->willReturnCallback(fn (): EnvioAutomaticoDoCliente => $this->linha ??= new EnvioAutomaticoDoCliente($this->tenant, $this->cliente, null, new \DateTimeImmutable()));
    }

    private function useCase(): DefinirEnvioAutomaticoDoClienteUseCase
    {
        $emails = $this->createStub(ClienteEmailRepository::class);
        $emails->method('listarDoCliente')->willReturn([]);

        return new DefinirEnvioAutomaticoDoClienteUseCase(new EmailsDoClienteDaPasta($emails), $this->automaticos, new MockClock('2026-10-19 10:00:00'));
    }

    #[TestDox('Liga a chave do cliente (o e-mail da ficha conta como cadastrado), grava quem e quando')]
    public function testLiga(): void
    {
        $usuario = new User();
        $this->automaticos->expects(self::once())->method('salvar');

        $this->useCase()->executar($this->pasta, ChaveDoEnvioAutomatico::NovasMovimentacoes, true, $usuario, $this->tenant);

        self::assertNotNull($this->linha);
        self::assertTrue($this->linha->ligada(ChaveDoEnvioAutomatico::NovasMovimentacoes));
        self::assertFalse($this->linha->ligada(ChaveDoEnvioAutomatico::ResumoSemanal));
        self::assertSame($usuario, $this->linha->getAtualizadoPor());
    }

    #[TestDox('Pedir o estado que já vale (desligar o que nasceu desligado) não grava nada')]
    public function testMesmoEstadoNaoGrava(): void
    {
        $this->automaticos->expects(self::never())->method('salvar');
        $this->automaticos->expects(self::never())->method('garantirDoCliente');

        $this->useCase()->executar($this->pasta, ChaveDoEnvioAutomatico::ResumoSemanal, false, new User(), $this->tenant);
    }

    #[TestDox('Sem e-mail nenhum: ligar dá "Cadastre um e-mail para ativar."; desligar é aceito')]
    public function testSemEmail(): void
    {
        $this->cliente->setEmail('');
        $this->linha = new EnvioAutomaticoDoCliente($this->tenant, $this->cliente, null, new \DateTimeImmutable());
        $this->linha->definir(ChaveDoEnvioAutomatico::ResumoSemanal, true, new User(), new \DateTimeImmutable());

        $this->useCase()->executar($this->pasta, ChaveDoEnvioAutomatico::ResumoSemanal, false, new User(), $this->tenant);
        self::assertFalse($this->linha->ligada(ChaveDoEnvioAutomatico::ResumoSemanal));

        $this->expectException(EmailDoClienteRecusadoException::class);
        $this->expectExceptionMessage('Cadastre um e-mail para ativar.');
        $this->useCase()->executar($this->pasta, ChaveDoEnvioAutomatico::NovasMovimentacoes, true, new User(), $this->tenant);
    }

    #[TestDox('Pasta sem cliente: "A pasta não tem cliente vinculado."')]
    public function testSemCliente(): void
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        $this->expectException(EmailDoClienteRecusadoException::class);
        $this->expectExceptionMessage('A pasta não tem cliente vinculado.');
        $this->useCase()->executar($pasta, ChaveDoEnvioAutomatico::NovasMovimentacoes, true, new User(), $this->tenant);
    }
}
