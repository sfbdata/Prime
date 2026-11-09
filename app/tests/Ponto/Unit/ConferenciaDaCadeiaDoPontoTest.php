<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\DTO\ConferenciaDaTrilhaDoPontoOutput;
use App\Ponto\Service\ConferenciaDaCadeiaDoPonto;
use App\Ponto\Service\SeloDaTrilhaDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A conferência da cadeia da trilha do Ponto (E9), sem banco: íntegra, alterada no primeiro evento que não confere,
 * não selada no trecho inicial sem selo, e a chave escolhida pela versão gravada em cada linha.
 */
#[CoversClass(ConferenciaDaCadeiaDoPonto::class)]
#[CoversClass(ConferenciaDaTrilhaDoPontoOutput::class)]
final class ConferenciaDaCadeiaDoPontoTest extends TestCase
{
    /** @var array<int, string> */
    private const CHAVES = [1 => 'chave-da-versao-1', 2 => 'chave-da-versao-2'];

    #[TestDox('Cadeia selada inteira: íntegra, com o texto do desenho')]
    public function testIntegra(): void
    {
        $resultado = $this->conferir($this->cadeia([1, 1, 1]));

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::INTEGRA, $resultado->situacao);
        self::assertSame('Cadeia de eventos íntegra: cada evento é selado com o anterior. Registros originais nunca são sobrescritos.', $resultado->texto());
    }

    #[TestDox('Trilha vazia: íntegra (nada a conferir)')]
    public function testVazia(): void
    {
        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::INTEGRA, $this->conferir([])->situacao);
    }

    #[TestDox('Detalhe mexido (UPDATE) na linha 2: alterada no nº 2, com o número no texto')]
    public function testDetalheMexido(): void
    {
        $cadeia = $this->cadeia([1, 1, 1]);
        $cadeia[1]['detalhe'] = ['tipo' => 'saida'];

        $resultado = $this->conferir($cadeia);

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::ALTERADA, $resultado->situacao);
        self::assertSame(2, $resultado->sequenciaAlterada);
        self::assertSame('Atenção: a cadeia de eventos foi alterada. O primeiro evento que não confere é o nº 2.', $resultado->texto());
    }

    #[TestDox('Linha apagada no meio: alterada na sequência que falta')]
    public function testLinhaApagada(): void
    {
        $cadeia = $this->cadeia([1, 1, 1]);
        unset($cadeia[1]);

        self::assertSame(2, $this->conferir(array_values($cadeia))->sequenciaAlterada);
    }

    #[TestDox('Linha refeita com selo válido mas fora do elo (selo anterior trocado): alterada nela')]
    public function testEloQuebrado(): void
    {
        $cadeia = $this->cadeia([1, 1, 1]);
        $cadeia[2]['selo_anterior'] = str_repeat('0', 64);
        $cadeia[2]['selo'] = SeloDaTrilhaDoPonto::calcular(self::CHAVES[1], $cadeia[2]);

        self::assertSame(3, $this->conferir($cadeia)->sequenciaAlterada);
    }

    #[TestDox('Selo apagado depois de uma linha selada: alterada')]
    public function testSeloApagado(): void
    {
        $cadeia = $this->cadeia([1, 1, 1]);
        $cadeia[1]['selo'] = null;
        $cadeia[1]['versao_da_chave'] = null;

        self::assertSame(2, $this->conferir($cadeia)->sequenciaAlterada);
    }

    #[TestDox('Trecho inicial sem selo (antes do segredo) e o resto selado: não selada até o último sem selo')]
    public function testTrechoInicial(): void
    {
        $resultado = $this->conferir($this->cadeia([null, null, 1, 1]));

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::NAO_SELADA, $resultado->situacao);
        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::TRECHO_INICIAL, $resultado->motivoSemSelo);
        self::assertSame(2, $resultado->ultimaSemSelo);
        self::assertSame('Os eventos até o nº 2 foram gravados antes do selo e não podem ser conferidos. Do nº 3 em diante, cada evento é selado com o anterior.', $resultado->texto());
    }

    #[TestDox('Trecho inicial sem selo e uma selada mexida depois: alterada vence')]
    public function testTrechoInicialComAlteracao(): void
    {
        $cadeia = $this->cadeia([null, 1, 1]);
        $cadeia[2]['acao'] = 'justificativa_abonada';

        self::assertSame(3, $this->conferir($cadeia)->sequenciaAlterada);
    }

    #[TestDox('Troca de chave: as linhas da versão 1 conferem pela chave 1 e as da 2 pela chave 2')]
    public function testTrocaDeVersaoDaChave(): void
    {
        $cadeia = $this->cadeia([1, 1, 2, 2]);

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::INTEGRA, $this->conferir($cadeia)->situacao);
    }

    #[TestDox('Troca de chave: sem a chave da versão gravada, ou com outra chave nela, a linha não confere')]
    public function testVersaoSemChave(): void
    {
        $cadeia = $this->cadeia([1, 2]);

        $soA2 = ConferenciaDaCadeiaDoPonto::conferir($cadeia, static fn (int $v): ?string => $v === 2 ? self::CHAVES[2] : null);
        $trocada = ConferenciaDaCadeiaDoPonto::conferir($cadeia, static fn (int $v): ?string => self::CHAVES[2]);

        self::assertSame(1, $soA2->sequenciaAlterada);
        self::assertSame(1, $trocada->sequenciaAlterada);
    }

    #[TestDox('Sem segredo: o texto diz que a chave do selo não está configurada')]
    public function testTextoSemSegredo(): void
    {
        self::assertSame(
            'Cadeia de eventos sem selo: a chave do selo não está configurada no servidor, então os eventos não podem ser conferidos.',
            ConferenciaDaTrilhaDoPontoOutput::semSegredo()->texto(),
        );
    }

    /**
     * @param list<array<string, mixed>> $cadeia
     */
    private function conferir(array $cadeia): ConferenciaDaTrilhaDoPontoOutput
    {
        return ConferenciaDaCadeiaDoPonto::conferir($cadeia, static fn (int $versao): ?string => self::CHAVES[$versao] ?? null);
    }

    /**
     * Uma cadeia como a TrilhaDoPonto grava: cada item é a versão da chave da linha (nula = sem selo).
     *
     * @param list<?int> $versoes
     *
     * @return list<array<string, mixed>>
     */
    private function cadeia(array $versoes): array
    {
        $cadeia   = [];
        $anterior = null;
        foreach ($versoes as $i => $versao) {
            $linha = [
                'selo_anterior' => $anterior,
                'tenant_id'     => 3,
                'sequencia'     => $i + 1,
                'em'            => sprintf('2026-03-10 08:%02d:00', $i),
                'autor_id'      => 11,
                'autor_nome'    => 'Gestor',
                'acao'          => 'batida_registrada',
                'detalhe'       => ['tipo' => 'entrada', 'n' => $i],
            ];
            $linha['selo']            = $versao === null ? null : SeloDaTrilhaDoPonto::calcular(self::CHAVES[$versao], $linha);
            $linha['versao_da_chave'] = $versao;
            $cadeia[]                 = $linha;
            $anterior                 = $linha['selo'];
        }

        return $cadeia;
    }
}
