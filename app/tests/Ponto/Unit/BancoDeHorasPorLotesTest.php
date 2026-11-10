<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\BancoDeHorasOutput;
use App\Ponto\DTO\LoteDoBancoDeHorasOutput;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BancoDeHorasPorLotes;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\InicioContagemResolver;
use App\Ponto\Service\LimitesDoBancoDeHoras;
use App\Ponto\Service\LimitesPadraoDoBancoDeHoras;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os lotes do banco de horas (spec ponto-desenho-0811, E17, P51 a e P52; desenho 16 JS L774 a L776). Os saldos de cada
 * mês vêm prontos do `FolhaPontoBuilder` (a conta da folha e das horas pagas tem os próprios testes); aqui só a regra
 * dos lotes: a ordem de abatimento, o vencimento, a situação, a cor e o limite.
 */
#[CoversClass(BancoDeHorasPorLotes::class)]
#[CoversClass(LimitesPadraoDoBancoDeHoras::class)]
final class BancoDeHorasPorLotesTest extends TestCase
{
    private const PRAZO = 6;
    private const LIMITE = 7200;

    /** O dia passado ao serviço no teste em curso, para conferir que a conta usa o mesmo. */
    private string $hojeDaConta = '';

    #[TestDox('as horas negativas abatem primeiro o credito mais antigo')]
    public function testDebitoAbatePrimeiroOCreditoMaisAntigo(): void
    {
        $banco = $this->calcular(['2026-01' => 600, '2026-02' => 300, '2026-03' => -700], '2026-04-10');

        self::assertSame(200, $banco->saldoAtualMinutos);
        self::assertSame(
            [
                ['01/2026', 600, 600, 0, '2026-07-31', 'Compensado'],
                ['02/2026', 300, 100, 200, '2026-08-31', 'No prazo'],
                ['03/2026', 0, 700, 0, null, 'Compensação'],
            ],
            $this->resumo($banco),
            'janeiro some inteiro antes de fevereiro ser tocado; março é a linha da compensação',
        );
    }

    #[TestDox('o banco nao zera em janeiro: o credito de dezembro continua no saldo e no lote')]
    public function testNaoZeraNaViradaDoAno(): void
    {
        $banco = $this->calcular(['2025-12' => 6000, '2026-01' => -600], '2026-02-05');

        self::assertSame(5400, $banco->saldoAtualMinutos);
        self::assertSame(['12/2025', 6000, 600, 5400, '2026-06-30', 'No prazo'], $this->resumo($banco)[0]);
    }

    #[TestDox('credito vencido continua no saldo atual (o desenho soma tudo)')]
    public function testVencidoContinuaNoSaldo(): void
    {
        $banco = $this->calcular(['2025-01' => 300, '2026-05' => 120], '2026-11-09');

        self::assertSame(420, $banco->saldoAtualMinutos);
        self::assertSame('Vencido', $banco->lotes[0]->situacao);
        self::assertSame('#a3232b', $banco->lotes[0]->cor);
    }

    /**
     * Crédito de janeiro/2026 com prazo de 6 meses vence em 31/07/2026 (o último dia de "competência mais prazo").
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function situacoes(): iterable
    {
        yield 'um dia depois do vencimento' => ['2026-08-01', 'Vencido', '#a3232b'];
        yield 'no dia do vencimento' => ['2026-07-31', 'Vence hoje', '#8a6200'];
        yield 'a 1 dia' => ['2026-07-30', 'Vence em 1 dia', '#8a6200'];
        yield 'a 2 dias' => ['2026-07-29', 'Vence em 2 dias', '#8a6200'];
        yield 'a 30 dias' => ['2026-07-01', 'Vence em 30 dias', '#8a6200'];
        yield 'a 31 dias' => ['2026-06-30', 'No prazo', '#1e7b45'];
    }

    #[DataProvider('situacoes')]
    #[TestDox('situacao e cor do lote com credito: $situacao')]
    public function testSituacaoECorPeloVencimento(string $hoje, string $situacao, string $cor): void
    {
        $lote = $this->calcular(['2026-01' => 300], $hoje)->lotes[0];

        self::assertSame('2026-07-31', $lote->venceEm?->format('Y-m-d'));
        self::assertSame($situacao, $lote->situacao);
        self::assertSame($cor, $lote->cor);
    }

    #[TestDox('lote compensado e linha de compensacao usam o cinza do desenho, mesmo vencidos')]
    public function testCompensadoECompensacaoSaoCinza(): void
    {
        $banco = $this->calcular(['2025-01' => 60, '2025-02' => -60], '2026-11-09');

        self::assertSame(['Compensado', 'Compensação'], array_map(static fn (LoteDoBancoDeHorasOutput $l): string => $l->situacao, $banco->lotes));
        self::assertSame(['#5b7383', '#5b7383'], array_map(static fn (LoteDoBancoDeHorasOutput $l): string => $l->cor, $banco->lotes));
    }

    #[TestDox('mes zerado nao vira lote; sem nenhum mes, saldo zero e lista vazia')]
    public function testMesZeradoNaoViraLote(): void
    {
        self::assertSame(['01/2026'], array_column($this->resumo($this->calcular(['2025-12' => 0, '2026-01' => 30], '2026-02-01')), 0));

        $vazio = $this->calcular([], '2026-02-01');
        self::assertSame(0, $vazio->saldoAtualMinutos);
        self::assertSame([], $vazio->lotes);
        self::assertFalse($vazio->acimaDoLimite);
    }

    #[TestDox('o aviso do limite so passa do limite: igual ao limite nao avisa')]
    public function testAcimaDoLimiteSoQuandoPassa(): void
    {
        self::assertFalse($this->calcular(['2026-01' => self::LIMITE], '2026-02-01')->acimaDoLimite);
        self::assertTrue($this->calcular(['2026-01' => self::LIMITE + 1], '2026-02-01')->acimaDoLimite);
    }

    #[TestDox('o limite e o prazo vem da configuracao do escritorio da folha')]
    public function testLimiteEPrazoDoEscritorio(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $limites = $this->createMock(LimitesDoBancoDeHoras::class);
        $limites->expects(self::once())->method('limiteEmMinutos')->with($tenant)->willReturn(600);
        $limites->expects(self::once())->method('prazoEmMeses')->with($tenant)->willReturn(1);

        $this->hojeDaConta = '2026-02-01';
        $banco = $this->servico(['2026-01' => 700], $limites, $tenant)
            ->calcular($this->createStub(User::class), $tenant, new \DateTimeImmutable('2026-02-01'));

        self::assertSame(600, $banco->limiteMinutos);
        self::assertSame(1, $banco->prazoMeses);
        self::assertTrue($banco->acimaDoLimite);
        self::assertSame('2026-02-28', $banco->lotes[0]->venceEm?->format('Y-m-d'), 'prazo de 1 mês: fim de fevereiro');
    }

    #[TestDox('o padrao sem a configuracao do escritorio e 120 horas e 6 meses')]
    public function testLimitesPadrao(): void
    {
        $padrao = new LimitesPadraoDoBancoDeHoras();
        $tenant = $this->createStub(Tenant::class);

        self::assertSame(120 * 60, $padrao->limiteEmMinutos($tenant));
        self::assertSame(6, $padrao->prazoEmMeses($tenant));
    }

    /** @param array<string, int> $saldos */
    private function calcular(array $saldos, string $hoje): BancoDeHorasOutput
    {
        $limites = $this->createStub(LimitesDoBancoDeHoras::class);
        $limites->method('limiteEmMinutos')->willReturn(self::LIMITE);
        $limites->method('prazoEmMeses')->willReturn(self::PRAZO);
        $tenant = $this->createStub(Tenant::class);
        $this->hojeDaConta = $hoje;

        return $this->servico($saldos, $limites, $tenant)
            ->calcular($this->createStub(User::class), $tenant, new \DateTimeImmutable($hoje . ' 15:40:00'));
    }

    /** @param array<string, int> $saldos */
    private function servico(array $saldos, LimitesDoBancoDeHoras $limites, Tenant $tenant): BancoDeHorasPorLotes
    {
        $folha = $this->createMock(FolhaPontoBuilder::class);
        $folha->expects(self::once())
            ->method('saldosDoBancoPorCompetencia')
            ->with(self::anything(), $tenant, self::anything(), self::anything(), self::anything(), self::callback(
                // Uma fonte de "hoje": o dia que o serviço recebe (sem a hora) é o mesmo da conta dos saldos.
                fn (mixed $dia): bool => $dia instanceof \DateTimeImmutable && $dia->format('H:i:s') === '00:00:00' && $dia->format('Y-m-d') === $this->hojeDaConta,
            ))
            ->willReturn($saldos);

        return new BancoDeHorasPorLotes(
            $folha,
            new InicioContagemResolver($this->createStub(RegistroPontoRepository::class), $this->createStub(JustificativaPontoRepository::class)),
            $this->createStub(FeriadoRepository::class),
            $limites,
        );
    }

    /** @return list<array{string, int, int, int, ?string, string}> competência, crédito, compensado, saldo, vencimento e situação */
    private function resumo(BancoDeHorasOutput $banco): array
    {
        return array_map(
            static fn (LoteDoBancoDeHorasOutput $lote): array => [
                $lote->competencia,
                $lote->creditoMinutos,
                $lote->compensadoMinutos,
                $lote->saldoMinutos,
                $lote->venceEm?->format('Y-m-d'),
                $lote->situacao,
            ],
            $banco->lotes,
        );
    }
}
