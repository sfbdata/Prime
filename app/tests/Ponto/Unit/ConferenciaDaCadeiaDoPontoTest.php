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
 * trecho sem selo (no começo ou no meio, por falta do segredo) com as faixas dos números, e a chave escolhida pela
 * versão gravada em cada linha.
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

    #[TestDox('Selo apagado de uma linha selada no meio: alterada nela (a seguinte se prende ao selo que sumiu)')]
    public function testSeloApagado(): void
    {
        $cadeia = $this->cadeia([1, 1, 1]);
        $cadeia[1]['selo'] = null;
        $cadeia[1]['versao_da_chave'] = null;

        self::assertSame(2, $this->conferir($cadeia)->sequenciaAlterada);
    }

    #[TestDox('Trecho inicial sem selo (antes do segredo) e o resto selado: trecho sem selo com a faixa, nunca íntegra')]
    public function testTrechoInicial(): void
    {
        $resultado = $this->conferir($this->cadeia([null, null, 1, 1]));

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::TRECHO_SEM_SELO, $resultado->situacao);
        self::assertSame([[1, 2]], $resultado->trechosSemSelo);
        self::assertSame('Os eventos nº 1 a 2 estão sem selo e não podem ser conferidos. Os demais eventos conferem com o selo.', $resultado->texto());
    }

    #[TestDox('Falta do segredo no meio (selada, duas sem selo, selada): trecho sem selo nº 2 a 3, não alterada')]
    public function testFaltaDoSegredoNoMeio(): void
    {
        $cadeia = $this->cadeia([1, null, null, 1]);

        $resultado = $this->conferir($cadeia);

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::TRECHO_SEM_SELO, $resultado->situacao);
        self::assertSame([[2, 3]], $resultado->trechosSemSelo);
        self::assertSame('Os eventos nº 2 a 3 estão sem selo e não podem ser conferidos. Os demais eventos conferem com o selo.', $resultado->texto());
        self::assertSame($cadeia[0]['selo'], $cadeia[3]['selo_anterior'], 'a selada depois do trecho se prende à última selada antes dele');
    }

    #[TestDox('Linha selada mexida DEPOIS de um trecho sem selo: alterada nela')]
    public function testAlteracaoDepoisDoTrecho(): void
    {
        $cadeia = $this->cadeia([1, null, 1, 1]);
        $primeira = $cadeia;
        $primeira[2]['detalhe'] = ['tipo' => 'saida'];
        $segunda = $cadeia;
        $segunda[3]['autor_nome'] = 'Outro';

        self::assertSame(3, $this->conferir($primeira)->sequenciaAlterada);
        self::assertSame(4, $this->conferir($segunda)->sequenciaAlterada);
    }

    #[TestDox('Apagar o selo da selada logo antes de um trecho sem selo (para alargá-lo): alterada nela')]
    public function testTrechoAlargadoParaTras(): void
    {
        $cadeia = $this->cadeia([1, 1, null, 1]);
        $cadeia[1]['selo'] = null;
        $cadeia[1]['versao_da_chave'] = null;

        self::assertSame(2, $this->conferir($cadeia)->sequenciaAlterada);
    }

    #[TestDox('Selo apagado da última linha: aparece como trecho sem selo com o número dela (limite aceito, visível)')]
    public function testSeloApagadoDaUltima(): void
    {
        $cadeia = $this->cadeia([1, 1, 1]);
        $cadeia[2]['selo'] = null;
        $cadeia[2]['versao_da_chave'] = null;

        $resultado = $this->conferir($cadeia);

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::TRECHO_SEM_SELO, $resultado->situacao);
        self::assertSame('O evento nº 3 está sem selo e não pode ser conferido. Os demais eventos conferem com o selo.', $resultado->texto());
    }

    #[TestDox('Vários trechos: o texto cita três faixas e conta o resto; tudo sem selo não diz que os demais conferem')]
    public function testVariosTrechos(): void
    {
        $varios = $this->conferir($this->cadeia([null, 1, null, null, 1, null, 1, null, 1]));
        $tudoSemSelo = $this->conferir($this->cadeia([null, null]));

        self::assertSame([[1, 1], [3, 4], [6, 6], [8, 8]], $varios->trechosSemSelo);
        self::assertSame('Os eventos nº 1, nº 3 a 4, nº 6 e mais 1 trecho estão sem selo e não podem ser conferidos. Os demais eventos conferem com o selo.', $varios->texto());
        self::assertSame('Os eventos nº 1 a 2 estão sem selo e não podem ser conferidos.', $tudoSemSelo->texto());
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
     * Uma cadeia como a TrilhaDoPonto grava: cada item é a versão da chave da linha (nula = sem selo), e o
     * `selo_anterior` é o último selo da cadeia (por cima das linhas sem selo).
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
            $anterior                 = $linha['selo'] ?? $anterior;
        }

        return $cadeia;
    }
}
