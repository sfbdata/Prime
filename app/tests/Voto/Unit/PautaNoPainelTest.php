<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\DTO\PautaNoPainel;
use App\Voto\Enum\EstadoDaPauta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A pauta como o gestor a vê: situação e cor do chip, participação por unidade e por peso, quórum e o tempo que falta.
 * Só números de participação; resultado só existe com a pauta encerrada.
 */
#[CoversClass(PautaNoPainel::class)]
final class PautaNoPainelTest extends TestCase
{
    #[TestDox('Situação e cor do chip: rascunho, publicada (aguardando a mesa), votação aberta e encerrada')]
    public function testSituacao(): void
    {
        self::assertSame(['rascunho', '#5b7383'], [self::pauta(publicada: false)->situacao(), self::pauta(publicada: false)->cor()]);
        $aguardando = self::pauta();
        self::assertSame(['publicada', '#0a6fa0'], [$aguardando->situacao(), $aguardando->cor()]);
        self::assertTrue($aguardando->aguardandoAMesa());
        self::assertSame(['aberta', '#1e7b45'], [self::pauta(EstadoDaPauta::Aberta)->situacao(), self::pauta(EstadoDaPauta::Aberta)->cor()]);
        self::assertSame(['encerrada', '#8a6200'], [self::pauta(EstadoDaPauta::Encerrada)->situacao(), self::pauta(EstadoDaPauta::Encerrada)->cor()]);
        self::assertFalse(self::pauta(publicada: false)->aguardandoAMesa());
    }

    #[TestDox('Participação: unidades sobre aptas (inteiro) e peso sobre o peso apto (2 casas na conta, 1 na tela)')]
    public function testParticipacao(): void
    {
        $pauta = self::pauta(EstadoDaPauta::Aberta, votantes: 2, pesoVotante: '0.523000', aptas: 3, pesoApto: '1.000000', quorum: '50.00');

        self::assertSame(67, $pauta->pctUnidades());
        self::assertSame('52.30', $pauta->pctPeso());
        self::assertSame('52,3', $pauta->pctPesoTexto());
        self::assertSame('52.30', $pauta->larguraDaBarra());
        self::assertSame('50', $pauta->quorumTexto());
        self::assertTrue($pauta->exigeQuorum());
        self::assertTrue($pauta->quorumAtingido());
    }

    #[TestDox('Quórum: abaixo do exigido não é atingido; 2ª convocação (0%) não exige; sem aptos nada divide por zero')]
    public function testQuorum(): void
    {
        self::assertFalse(self::pauta(EstadoDaPauta::Aberta, votantes: 1, pesoVotante: '0.499999', aptas: 2, pesoApto: '1.000000', quorum: '50.00')->quorumAtingido());
        $segunda = self::pauta(EstadoDaPauta::Aberta, votantes: 0, pesoVotante: '0.000000', aptas: 2, pesoApto: '1.000000', quorum: '0.00');
        self::assertFalse($segunda->exigeQuorum());
        self::assertTrue($segunda->quorumAtingido());
        $semAptos = self::pauta(EstadoDaPauta::Aberta, quorum: '50.00');
        self::assertSame(0, $semAptos->pctUnidades());
        self::assertSame('0.00', $semAptos->pctPeso());
        self::assertSame('0', $semAptos->pctPesoTexto());
        self::assertFalse(self::pauta()->quorumAtingido(), 'sem abrir não há quórum exigido');
        self::assertSame('', self::pauta()->quorumTexto());
    }

    #[TestDox('Tempo que falta: só com a votação aberta e com prazo; zero depois do prazo')]
    public function testSegundosRestantes(): void
    {
        $agora = new \DateTimeImmutable('2026-11-10 20:00:00');

        self::assertSame(90, self::pauta(EstadoDaPauta::Aberta, prazo: $agora->modify('+90 seconds'))->segundosRestantes($agora));
        self::assertSame(0, self::pauta(EstadoDaPauta::Aberta, prazo: $agora->modify('-5 seconds'))->segundosRestantes($agora));
        self::assertNull(self::pauta(EstadoDaPauta::Aberta)->segundosRestantes($agora), 'sem prazo');
        self::assertNull(self::pauta(EstadoDaPauta::Encerrada, prazo: $agora->modify('+90 seconds'))->segundosRestantes($agora));
    }

    private static function pauta(
        EstadoDaPauta $estado = EstadoDaPauta::Aguardando,
        bool $publicada = true,
        int $votantes = 0,
        string $pesoVotante = '0.000000',
        int $aptas = 0,
        string $pesoApto = '0.000000',
        ?string $quorum = null,
        ?\DateTimeImmutable $prazo = null,
    ): PautaNoPainel {
        return new PautaNoPainel(
            id: 1,
            ordem: 1,
            titulo: 'Aprovação das contas',
            descricao: null,
            estado: $estado,
            assembleiaPublicada: $publicada,
            convocacao: null === $quorum ? null : 1,
            quorumExigido: $quorum,
            abertaEm: null,
            abertaPorNome: null,
            prazoAte: $prazo,
            encerradaEm: null,
            encerradaPorNome: null,
            motivoEncerramento: null,
            opcoes: [['numero' => 1, 'rotulo' => 'Sim'], ['numero' => 2, 'rotulo' => 'Não']],
            votantes: $votantes,
            pesoVotante: $pesoVotante,
            unidadesAptas: $aptas,
            pesoApto: $pesoApto,
            resultado: null,
        );
    }
}
