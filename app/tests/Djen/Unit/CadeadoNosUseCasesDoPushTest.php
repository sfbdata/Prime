<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\UseCase\AtualizarAnotacoesDoProcessoUseCase;
use App\Djen\UseCase\CumprirPrazoDaPublicacaoUseCase;
use App\Djen\UseCase\DefinirPrazoDaPublicacaoUseCase;
use App\Djen\UseCase\IncluirProcessoCadastradoNoPushUseCase;
use App\Djen\UseCase\IncluirProcessoNoAcompanhamentoUseCase;
use App\Djen\UseCase\PararDeAcompanharProcessoUseCase;
use App\Djen\UseCase\ReconciliarPublicacoesComProcessosUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\CumprirPrazoDaPastaUseCase;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cadeado da pasta nos UseCases do Push (auditoria, lote A: 3.08 e 3.09). O processo cujas pastas são todas ocultas
 * para a pessoa não abre para ela (K3): com o processo na lista de ocultos, cada UseCase recusa e não grava nada.
 */
#[CoversClass(PararDeAcompanharProcessoUseCase::class)]
#[CoversClass(IncluirProcessoNoAcompanhamentoUseCase::class)]
#[CoversClass(IncluirProcessoCadastradoNoPushUseCase::class)]
#[CoversClass(AtualizarAnotacoesDoProcessoUseCase::class)]
#[CoversClass(DefinirPrazoDaPublicacaoUseCase::class)]
#[CoversClass(CumprirPrazoDaPublicacaoUseCase::class)]
#[Group('djen')]
final class CadeadoNosUseCasesDoPushTest extends TestCase
{
    private const NUMERO = '07044444420268070001';
    private const ID = 42;

    private Tenant $tenant;
    private User $usuario;
    private Processo $processo;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->usuario = new User();
        $this->processo = (new Processo())->setNumeroProcesso(self::NUMERO);
        (new \ReflectionProperty(Processo::class, 'id'))->setValue($this->processo, self::ID);
    }

    private function processos(): ProcessoRepository
    {
        $processos = $this->createMock(ProcessoRepository::class);
        $processos->method('findByNumeroProcessoDoTenant')->willReturn($this->processo);
        $processos->expects(self::never())->method('save');

        return $processos;
    }

    private function acompanhadosQueNaoGravam(): ProcessoAcompanhadoRepository
    {
        $acompanhados = $this->createMock(ProcessoAcompanhadoRepository::class);
        $acompanhados->expects(self::never())->method('salvar');
        $acompanhados->expects(self::never())->method('gravar');

        return $acompanhados;
    }

    #[Test]
    public function pararRecusaProcessoOcultoSemPausarNada(): void
    {
        $this->expectException(AcompanhamentoInvalidoException::class);
        try {
            (new PararDeAcompanharProcessoUseCase($this->processos(), $this->acompanhadosQueNaoGravam(), $this->pastasDoProcesso()))
                ->executar($this->tenant, $this->usuario, [self::NUMERO], [self::ID], static fn (): bool => true);
        } finally {
            self::assertTrue($this->processo->monitoramentoAtivo());
        }
    }

    /** Uma pasta (NUP CAD1) ligada ao processo. */
    private function pastasDoProcesso(): PastaRepository
    {
        $pasta = (new Pasta())->setNup('CAD1');
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('vinculosDosProcessos')->willReturn([new PastaProcesso($pasta, $this->processo)]);

        return $pastas;
    }

    #[Test]
    public function pararRecusaQuemNaoEditaAlgumaPastaDoProcesso(): void
    {
        $this->expectException(AcompanhamentoInvalidoException::class);
        $this->expectExceptionMessage('Você não tem permissão para pausar o processo da Pasta CAD1. Nada foi pausado.');
        try {
            (new PararDeAcompanharProcessoUseCase($this->processos(), $this->acompanhadosQueNaoGravam(), $this->pastasDoProcesso()))
                ->executar($this->tenant, $this->usuario, [self::NUMERO], [], static fn (): bool => false);
        } finally {
            self::assertTrue($this->processo->monitoramentoAtivo());
        }
    }

    #[Test]
    public function incluirRecusaProcessoOculto(): void
    {
        $this->processo->pausarMonitoramento($this->usuario, new \DateTimeImmutable('-1 day'));
        $this->expectException(AcompanhamentoInvalidoException::class);
        try {
            (new IncluirProcessoNoAcompanhamentoUseCase($this->processos(), $this->acompanhadosQueNaoGravam()))
                ->executar($this->tenant, $this->usuario, self::NUMERO, [self::ID]);
        } finally {
            self::assertFalse($this->processo->monitoramentoAtivo());
        }
    }

    #[Test]
    public function incluirCadastradoRecusaProcessoOculto(): void
    {
        $this->processo->pausarMonitoramento($this->usuario, new \DateTimeImmutable('-1 day'));
        $reconciliar = (new \ReflectionClass(ReconciliarPublicacoesComProcessosUseCase::class))->newInstanceWithoutConstructor();
        $this->expectException(AcompanhamentoInvalidoException::class);
        try {
            (new IncluirProcessoCadastradoNoPushUseCase($this->processos(), $this->acompanhadosQueNaoGravam(), $reconciliar))
                ->executarPorNumero($this->tenant, $this->usuario, self::NUMERO, [self::ID]);
        } finally {
            self::assertFalse($this->processo->monitoramentoAtivo());
        }
    }

    #[Test]
    public function anotacoesRecusamProcessoOculto(): void
    {
        $this->expectException(AcompanhamentoInvalidoException::class);

        (new AtualizarAnotacoesDoProcessoUseCase($this->acompanhadosQueNaoGravam()))
            ->executar($this->tenant, $this->usuario, self::NUMERO, 'Obs', '', '', '', self::ID, [self::ID]);
    }

    /** O repositório devolve `null` para a publicação de número oculto (como a de outro escritório). */
    private function publicacoesSemAVisivel(): PublicacaoDjenRepository
    {
        $publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $publicacoes->expects(self::once())->method('findOneByIdVisivelDoTenant')->with(7, $this->tenant, [self::NUMERO])->willReturn(null);
        $publicacoes->expects(self::never())->method('findOneByIdDoTenant');
        $publicacoes->expects(self::never())->method('flush');

        return $publicacoes;
    }

    #[Test]
    public function definirPrazoRecusaPublicacaoOculta(): void
    {
        $this->expectException(VinculoDaPublicacaoInvalidoException::class);
        $this->expectExceptionMessage('Publicação não encontrada.');

        (new DefinirPrazoDaPublicacaoUseCase($this->publicacoesSemAVisivel()))->executar($this->tenant, 7, 10, [self::NUMERO]);
    }

    #[Test]
    public function cumprirPrazoRecusaPublicacaoOculta(): void
    {
        $cumprirNaPasta = (new \ReflectionClass(CumprirPrazoDaPastaUseCase::class))->newInstanceWithoutConstructor();
        $this->expectException(VinculoDaPublicacaoInvalidoException::class);
        $this->expectExceptionMessage('Publicação não encontrada.');

        (new CumprirPrazoDaPublicacaoUseCase($this->publicacoesSemAVisivel(), $this->createMock(PastaPrazoRepository::class), $cumprirNaPasta))
            ->executar($this->tenant, $this->usuario, 7, true, [self::NUMERO]);
    }

    #[Test]
    public function quemVeOProcessoContinuaPausando(): void
    {
        $acompanhados = $this->createMock(ProcessoAcompanhadoRepository::class);
        $acompanhados->expects(self::once())->method('gravar');

        $parados = (new PararDeAcompanharProcessoUseCase($this->processos(), $acompanhados, $this->pastasDoProcesso()))
            ->executar($this->tenant, $this->usuario, [self::NUMERO], [99], static fn (): bool => true);

        self::assertSame(1, $parados);
        self::assertFalse($this->processo->monitoramentoAtivo());
    }
}
