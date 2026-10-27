<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Entity\ProcessoAcompanhado;
use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Djen\UseCase\AtualizarAnotacoesDoProcessoUseCase;
use App\Djen\UseCase\IncluirProcessoNoAcompanhamentoUseCase;
use App\Djen\UseCase\PararDeAcompanharProcessoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncluirProcessoNoAcompanhamentoUseCase::class)]
#[CoversClass(PararDeAcompanharProcessoUseCase::class)]
#[CoversClass(AtualizarAnotacoesDoProcessoUseCase::class)]
#[CoversClass(ProcessoAcompanhado::class)]
final class AcompanhamentoDoProcessoUseCasesTest extends TestCase
{
    private const NUMERO = '07022222220268070001';

    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    private function incluirCom(?Processo $processo, ?ProcessoAcompanhado $linha, ?ProcessoAcompanhado &$salvo = null): IncluirProcessoNoAcompanhamentoUseCase
    {
        $processos = $this->createStub(ProcessoRepository::class);
        $processos->method('findByNumeroProcessoDoTenant')->willReturn($processo);
        $acompanhados = $this->createMock(ProcessoAcompanhadoRepository::class);
        $acompanhados->method('findOneDoTenantPorNumero')->willReturn($linha);
        $acompanhados->method('salvar')->willReturnCallback(static function (ProcessoAcompanhado $l) use (&$salvo): void {
            $salvo = $l;
        });

        return new IncluirProcessoNoAcompanhamentoUseCase($processos, $acompanhados);
    }

    #[Test]
    public function incluiNumeroSemProcessoCadastradoComAnotacoes(): void
    {
        $salvo = null;
        $this->incluirCom(null, null, $salvo)->executar($this->tenant, $this->usuario, '0702222-22.2026.8.07.0001', 'adv@exemplo.com', ' Cliente X ');

        self::assertNotNull($salvo);
        self::assertSame(self::NUMERO, $salvo->getNumeroProcesso());
        self::assertTrue($salvo->isAcompanhando());
        self::assertSame('adv@exemplo.com', $salvo->getEmailAlternativo());
        self::assertSame('Cliente X', $salvo->getObservacao());
    }

    #[Test]
    #[DataProvider('entradasInvalidas')]
    public function recusaEntradaInvalida(string $numero, string $email, string $observacao, string $mensagem): void
    {
        $this->expectException(AcompanhamentoInvalidoException::class);
        $this->expectExceptionMessage($mensagem);

        $this->incluirCom(null, null)->executar($this->tenant, $this->usuario, $numero, $email, $observacao);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'número curto' => ['123', '', '', 'Informe o número completo'];
        yield 'e-mail ruim' => [self::NUMERO, 'sem-arroba', '', 'E-mail alternativo inválido.'];
        yield 'observação longa' => [self::NUMERO, '', str_repeat('a', 151), 'até 150 caracteres'];
    }

    #[Test]
    public function recusaNumeroJaAcompanhado(): void
    {
        $this->expectException(AcompanhamentoInvalidoException::class);
        $this->expectExceptionMessage('já está sendo acompanhado');

        $this->incluirCom(null, new ProcessoAcompanhado($this->tenant, self::NUMERO))->executar($this->tenant, $this->usuario, self::NUMERO);
    }

    #[Test]
    public function processoCadastradoPausadoVoltaAoMonitoramento(): void
    {
        $processo = (new Processo())->setNumeroProcesso(self::NUMERO);
        $processo->pausarMonitoramento($this->usuario, new \DateTimeImmutable('-1 day'));
        $salvo = null;

        $this->incluirCom($processo, null, $salvo)->executar($this->tenant, $this->usuario, self::NUMERO, '', 'Importante');

        self::assertTrue($processo->monitoramentoAtivo());
        self::assertNotNull($salvo);
        self::assertFalse($salvo->isAcompanhando(), 'a linha só guarda anotações: o monitoramento é do processo');
    }

    #[Test]
    public function processoComBaixaNaoEntra(): void
    {
        $processo = (new Processo())->setNumeroProcesso(self::NUMERO);
        $processo->setDataBaixa(new \DateTime('2026-01-01'));
        $this->expectException(AcompanhamentoInvalidoException::class);
        $this->expectExceptionMessage('baixa');

        $this->incluirCom($processo, null)->executar($this->tenant, $this->usuario, self::NUMERO);
    }

    #[Test]
    public function pararPausaOProcessoEDesligaONumeroIncluido(): void
    {
        $processo = (new Processo())->setNumeroProcesso(self::NUMERO);
        $linha = new ProcessoAcompanhado($this->tenant, '07022222220268070002');
        $processos = $this->createStub(ProcessoRepository::class);
        $processos->method('findByNumeroProcessoDoTenant')->willReturnCallback(static fn (string $n) => $n === self::NUMERO ? $processo : null);
        $acompanhados = $this->createMock(ProcessoAcompanhadoRepository::class);
        $acompanhados->method('findOneDoTenantPorNumero')->willReturnCallback(static fn ($t, string $n) => $n === '07022222220268070002' ? $linha : null);
        $acompanhados->expects(self::once())->method('gravar');

        $parados = (new PararDeAcompanharProcessoUseCase($processos, $acompanhados))->executar($this->tenant, $this->usuario, [self::NUMERO, '0702222-22.2026.8.07.0002', self::NUMERO]);

        self::assertSame(2, $parados);
        self::assertFalse($processo->monitoramentoAtivo());
        self::assertFalse($linha->isAcompanhando());
    }

    #[Test]
    public function anotacoesGravamAreaFaseEObservacao(): void
    {
        $salvo = null;
        $repo = $this->createMock(ProcessoAcompanhadoRepository::class);
        $repo->method('findOneDoTenantPorNumero')->willReturn(null);
        $repo->method('salvar')->willReturnCallback(static function (ProcessoAcompanhado $l) use (&$salvo): void {
            $salvo = $l;
        });

        (new AtualizarAnotacoesDoProcessoUseCase($repo))->executar($this->tenant, $this->usuario, self::NUMERO, 'Obs', 'Cível', 'Recurso protocolado', '', true);

        self::assertNotNull($salvo);
        self::assertSame('Cível', $salvo->getArea());
        self::assertSame('Recurso protocolado', $salvo->getFase());
        self::assertFalse($salvo->isAcompanhando(), 'processo cadastrado: a linha não liga o número ao Push');
        self::assertNull($salvo->getEmailAlternativo());
    }

    #[Test]
    public function anotacoesRecusamAreaEFaseForaDaLista(): void
    {
        $this->expectException(AcompanhamentoInvalidoException::class);

        (new AtualizarAnotacoesDoProcessoUseCase($this->createMock(ProcessoAcompanhadoRepository::class)))
            ->executar($this->tenant, $this->usuario, self::NUMERO, '', 'Espacial', '', '', false);
    }
}
