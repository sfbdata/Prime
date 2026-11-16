<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\DivulgacaoDaAssembleiaOutput;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\DTO\VotacaoParaVotar;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\TipoDaPauta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A frase, a cor e o botão "Votar" de cada situação da votação na aba Votar (desenho 18). */
#[CoversClass(VotacaoParaVotar::class)]
final class VotacaoParaVotarTest extends TestCase
{
    /** @return iterable<string, array{EstadoDaPauta, bool, bool, string, string, bool}> */
    public static function situacoes(): iterable
    {
        yield 'aguardando a mesa' => [EstadoDaPauta::Aguardando, false, false, 'aguardando', '#0a6fa0', false];
        yield 'aberta' => [EstadoDaPauta::Aberta, false, false, 'aberta', '#0a6fa0', true];
        yield 'aberta e suspensa' => [EstadoDaPauta::Aberta, true, false, 'suspensa', '#8a6200', true];
        yield 'encerrada' => [EstadoDaPauta::Encerrada, false, false, 'encerrada', '#0a6fa0', false];
        yield 'cancelada' => [EstadoDaPauta::Encerrada, false, true, 'cancelada', '#8a6200', false];
    }

    #[DataProvider('situacoes')]
    #[TestDox('$_dataName')]
    public function testSituacao(EstadoDaPauta $estado, bool $suspensa, bool $cancelada, string $aviso, string $cor, bool $podeVotar): void
    {
        $votacao = self::votacao(self::pauta($estado, $suspensa, $cancelada));

        self::assertSame($aviso, $votacao->aviso());
        self::assertSame($cor, $votacao->estiloDoAviso()['cor']);
        self::assertSame($podeVotar, $votacao->podeVotar());
    }

    #[TestDox('Fim do período: o encerramento ganha do prazo; sem nenhum dos dois, nulo')]
    public function testFim(): void
    {
        $prazo = new \DateTimeImmutable('2026-11-16 20:00');
        $fim = new \DateTimeImmutable('2026-11-16 19:30');

        self::assertSame($fim, self::votacao(self::pauta(EstadoDaPauta::Encerrada, false, false, $prazo, $fim))->fim());
        self::assertSame($prazo, self::votacao(self::pauta(EstadoDaPauta::Aberta, false, false, $prazo))->fim());
        self::assertNull(self::votacao(self::pauta(EstadoDaPauta::Aberta))->fim());
    }

    #[TestDox('O código de conferência sai do código público do evento, sem o token de unidade')]
    public function testCodigoDeConferencia(): void
    {
        $votacao = self::votacao(self::pauta(EstadoDaPauta::Aberta));

        self::assertSame('ABCD-4321', $votacao->divulgacao->codigoDeConferencia());
        self::assertSame('https://bluejus.com.br/voto/v/abcd0000000000000000000000004321', $votacao->divulgacao->link);
        self::assertSame('pauta', $votacao->modalidade());
    }

    private static function votacao(PautaNoPainel $pauta): VotacaoParaVotar
    {
        $assembleia = new AssembleiaNoPainel(1, 'AGO', PesoDoVoto::Unidade, EstadoDaAssembleia::Publicada, 'Síndica', new \DateTimeImmutable(), new \DateTimeImmutable(), false, false, 0, 0, '0.000000', [$pauta]);
        $codigo = 'abcd0000000000000000000000004321';

        return new VotacaoParaVotar($assembleia, $pauta, new DivulgacaoDaAssembleiaOutput(1, 'AGO', $codigo, 'https://bluejus.com.br/voto/v/' . $codigo));
    }

    private static function pauta(EstadoDaPauta $estado, bool $suspensa = false, bool $cancelada = false, ?\DateTimeImmutable $prazo = null, ?\DateTimeImmutable $encerradaEm = null): PautaNoPainel
    {
        return new PautaNoPainel(
            id: 1,
            ordem: 1,
            titulo: 'Aprovação das contas',
            descricao: null,
            estado: $estado,
            assembleiaPublicada: true,
            convocacao: null,
            quorumExigido: null,
            abertaEm: null,
            abertaPorNome: null,
            prazoAte: $prazo,
            encerradaEm: $encerradaEm,
            encerradaPorNome: null,
            motivoEncerramento: null,
            opcoes: [],
            votantes: 0,
            pesoVotante: '0.000000',
            unidadesAptas: 0,
            pesoApto: '0.000000',
            resultado: null,
            tipo: TipoDaPauta::Deliberacao,
            suspensaEm: $suspensa ? new \DateTimeImmutable() : null,
            canceladaEm: $cancelada ? new \DateTimeImmutable() : null,
        );
    }
}
