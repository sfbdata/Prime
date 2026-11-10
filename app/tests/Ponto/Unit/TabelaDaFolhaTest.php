<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\DTO\LinhaDaFolhaImpressa;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Service\ImagemDaFolha\ImagemDaFolha;
use App\Ponto\Service\ImagemDaFolha\PaginaDaFolha;
use App\Ponto\Service\ImagemDaFolha\TabelaDaFolha;
use App\Ponto\Service\ImagemDoComprovante\TelaDoComprovante;
use App\Ponto\Service\LinhasDaFolhaImpressa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As observações do dia na tabela da folha em PDF (spec ponto-desenho-0811, E16, decisão 27; P35 a e P34; desenho 16
 * L622 e L673), medidas com as fontes de verdade, na escala do PDF. A fonte encolhe de 10 até 8, um ponto por vez, até
 * caber na coluna; se nem em 8 couber, só os ajustes da E10 ("<Batida> ajustada em ...", que vêm primeiro) se cortam
 * com "...", e o feriado, a pendência e as justificativas ficam inteiros. Se nem eles sozinhos couberem, corta o texto
 * todo, como antes. A linha não cresce, e a impressão dos registros continua com a observação inteira.
 *
 * Cada dia é montado pelo {@see LinhasDaFolhaImpressa} a partir da linha do motor da folha, como no PDF.
 */
#[Group('ponto')]
#[CoversClass(TabelaDaFolha::class)]
#[CoversClass(LinhasDaFolhaImpressa::class)]
#[CoversClass(LinhaDaFolhaImpressa::class)]
final class TabelaDaFolhaTest extends TestCase
{
    /** A largura útil da coluna "Justificativa / Observações" (316 menos 6 de cada lado). */
    private const LARGURA_DA_OBSERVACAO = 304;
    private const FERIADO = 'Nossa Senhora Aparecida';

    /** A página da última tabela desenhada, para medir o texto com as mesmas fontes. */
    private PaginaDaFolha $pagina;

    /**
     * @param array<string, mixed>        $campos         a linha do motor da folha
     * @param list<array{string, string}> $justificativas tipo e situação
     * @param list<string>                $inteiros       o que precisa sair inteiro no que é desenhado
     */
    #[DataProvider('diasComAjuste')]
    #[TestDox('$_dataName')]
    public function testOsAjustesSeCortamAntesDoResto(array $campos, array $justificativas, array $inteiros, int $tamanho, bool $cortada): void
    {
        $linha = $this->linha($campos, $justificativas);

        $desenhada = $this->observacaoDesenhada($linha);

        foreach ($inteiros as $inteiro) {
            self::assertStringContainsString($inteiro, $desenhada['texto']);
        }
        self::assertSame($tamanho, (int) $desenhada['tamanho'], 'o tamanho da fonte');
        self::assertLessThanOrEqual(self::LARGURA_DA_OBSERVACAO, $this->pagina->largura($desenhada['texto'], 'normal', $desenhada['tamanho']), 'cabe na coluna');
        self::assertSame($cortada, str_contains($desenhada['texto'], '...'), 'cortada com "..."');
        self::assertTrue(mb_check_encoding($desenhada['texto'], 'UTF-8'), 'o corte não parte um caractere');
        if ($cortada) {
            self::assertStringStartsWith('Entrada', $desenhada['texto'], 'o corte é só na parte dos ajustes, que vem primeiro');
            self::assertMatchesRegularExpression('/\.\.\. · ' . preg_quote($inteiros[0], '/') . '/u', $desenhada['texto'], 'o "..." fica no fim dos ajustes, antes do resto');
        }
        self::assertSame($linha->observacao, $linha->paraAImpressao()['observacao'], 'a impressão dos registros (decisão 3) continua com o texto inteiro');
    }

    /** @return iterable<string, array{array<string, mixed>, list<array{string, string}>, list<string>, int, bool}> */
    public static function diasComAjuste(): iterable
    {
        $entrada = ['entradaAjuste' => ['tipo' => 'ajuste', 'por' => 'Gestora Ana', 'em' => '13/10/2026']];
        $saida = ['saidaAjuste' => ['tipo' => 'ajuste', 'por' => 'Gestora Ana', 'em' => '14/10/2026']];
        $incompleto = ['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'registroIncompleto' => true];
        $feriado = ['isFeriado' => true, 'nomeFeriado' => self::FERIADO];

        yield '1 ajuste e "Registro incompleto": já cabia em 10, sai inteiro em 10' => [
            $saida + $incompleto, [], ['Saída ajustada em 14/10/2026 · Registro incompleto'], 10, false,
        ];
        yield '1 ajuste e "Horário fora de sequência: revisar": não cabe em 10, encolhe para 9 e sai inteiro' => [
            $saida + ['entrada' => '08:00:00', 'repouso' => '12:30:00', 'retorno' => '12:10:00', 'saida' => '17:00:00'], [],
            ['Saída ajustada em 14/10/2026 · Horário fora de sequência: revisar'], 9, false,
        ];
        yield '1 ajuste, "Registro incompleto" e uma falta em análise: em 8 ainda não cabe, o ajuste se corta e a pendência e a justificativa ficam inteiras' => [
            $entrada + $incompleto, [['falta_justificada', 'pendente']], ['Registro incompleto · Falta Justificada: em análise'], 8, true,
        ];
        yield '2 ajustes, feriado e "Registro incompleto": os ajustes se cortam e o feriado e a pendência ficam inteiros' => [
            $entrada + $saida + $incompleto + $feriado, [], ['Feriado: ' . self::FERIADO . ' · Registro incompleto'], 8, true,
        ];
    }

    #[TestDox('Sem ajuste, a linha que já cabia sai em 10 como antes; a que não cabe em 10 encolhe um ponto por vez e sai inteira; a que nem em 8 cabe é cortada em 8')]
    public function testSemAjuste(): void
    {
        $feriado = ['isFeriado' => true, 'nomeFeriado' => self::FERIADO];
        $cabia = $this->observacaoDesenhada($this->linha($feriado + ['entrada' => '08:00:00', 'registroIncompleto' => true], []));
        $encolhe = $this->observacaoDesenhada($this->linha($feriado, [['atestado_medico', 'pendente']]));
        $longa = $this->linha($feriado + ['entrada' => '08:00:00', 'registroIncompleto' => true], [['falta_justificada', 'pendente']]);
        $cortada = $this->observacaoDesenhada($longa);

        self::assertSame(['Feriado: ' . self::FERIADO . ' · Registro incompleto', 10], [$cabia['texto'], (int) $cabia['tamanho']]);
        self::assertSame(['Feriado: ' . self::FERIADO . ' · Atestado Médico: em análise', 9], [$encolhe['texto'], (int) $encolhe['tamanho']]);
        self::assertSame([$this->pagina->caber($longa->observacao, 'normal', 8, self::LARGURA_DA_OBSERVACAO), 8], [$cortada['texto'], (int) $cortada['tamanho']]);
        self::assertStringEndsWith('...', $cortada['texto']);
    }

    #[TestDox('Só com os ajustes (as quatro batidas ajustadas), que nem em 8 cabem: corta os ajustes com "..." em 8, sem separador sobrando e sem partir um caractere')]
    public function testSoAjustesQueNaoCabem(): void
    {
        $ajuste = static fn (string $em): array => ['tipo' => 'ajuste', 'por' => 'Gestora Ana', 'em' => $em];
        $linha = $this->linha([
            'entradaAjuste' => $ajuste('13/10/2026'), 'repousoAjuste' => $ajuste('14/10/2026'),
            'retornoAjuste' => $ajuste('15/10/2026'), 'saidaAjuste' => $ajuste('16/10/2026'),
            'entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '17:00:00',
        ], []);
        $desenhada = $this->observacaoDesenhada($linha);

        self::assertGreaterThan(self::LARGURA_DA_OBSERVACAO, $this->pagina->largura($linha->observacao, 'normal', 8), 'o cenário: nem em 8 cabe');
        self::assertSame(8, (int) $desenhada['tamanho']);
        self::assertStringStartsWith('Entrada ajustada em 13/10/2026', $desenhada['texto']);
        self::assertStringEndsWith('...', $desenhada['texto']);
        self::assertStringNotContainsString(' · ...', $desenhada['texto'], 'sem separador sobrando antes do "..."');
        self::assertLessThanOrEqual(self::LARGURA_DA_OBSERVACAO, $this->pagina->largura($desenhada['texto'], 'normal', $desenhada['tamanho']), 'cabe na coluna');
        self::assertTrue(mb_check_encoding($desenhada['texto'], 'UTF-8'), 'o corte não parte um caractere');
        self::assertSame($linha->observacao, $linha->paraAImpressao()['observacao'], 'a impressão dos registros continua com o texto inteiro');
    }

    #[TestDox('Se nem o feriado, a pendência e as justificativas sozinhos cabem em 8, corta o texto todo com "...", como antes')]
    public function testRestoQueNaoCabeSozinho(): void
    {
        $linha = $this->linha(
            ['entradaAjuste' => ['tipo' => 'ajuste', 'por' => 'Gestora Ana', 'em' => '13/10/2026'], 'entrada' => '08:00:00', 'registroIncompleto' => true, 'isFeriado' => true, 'nomeFeriado' => self::FERIADO],
            [['atestado_medico', 'pendente'], ['esquecimento_registro', 'pendente'], ['falta_justificada', 'rejeitado']],
        );

        $desenhada = $this->observacaoDesenhada($linha);

        self::assertSame(8, (int) $desenhada['tamanho']);
        self::assertSame($this->pagina->caber($linha->observacao, 'normal', 8, self::LARGURA_DA_OBSERVACAO), $desenhada['texto']);
        self::assertStringStartsWith('Entrada ajustada em 13/10/2026 · Feriado', $desenhada['texto']);
        self::assertStringEndsWith('...', $desenhada['texto']);
    }

    /**
     * @param array<string, mixed>        $campos
     * @param list<array{string, string}> $justificativas tipo e situação
     */
    private function linha(array $campos, array $justificativas): LinhaDaFolhaImpressa
    {
        $data = '2026-10-12';
        $dia = $campos + [
            'diaMes' => '12', 'diaSemana' => 'ignorado', 'chaveDia' => $data,
            'entrada' => '', 'repouso' => '', 'retorno' => '', 'saida' => '',
            'fimSemana' => false, 'isFeriado' => false, 'nomeFeriado' => null,
            'minutosIntervalo' => null, 'minutosTrabalhadosDia' => null, 'saldoDia' => null, 'saldoAcumulado' => null,
            'registroIncompleto' => false, 'justificativa' => null, 'aConferir' => [],
        ];
        $doDia = array_map(static fn (array $j): JustificativaPonto => (new JustificativaPonto())->setData(new \DateTime($data))->setTipo($j[0])->setStatus($j[1]), $justificativas);

        return (new LinhasDaFolhaImpressa())->linha($dia, $doDia);
    }

    /**
     * Desenha a tabela só com este dia, na escala do PDF e com as fontes de verdade, e devolve a observação desenhada
     * (o texto que começa na coluna "Justificativa / Observações", abaixo do cabeçalho).
     *
     * @return array{texto: string, fonte: string, tamanho: float, x: float, y: float, cor: string}
     */
    private function observacaoDesenhada(LinhaDaFolhaImpressa $linha): array
    {
        $pastaDasFontes = dirname(__DIR__, 3) . '/vendor/dompdf/dompdf/lib/fonts';
        $fontes = [
            'normal'       => $pastaDasFontes . '/DejaVuSans.ttf',
            'negrito'      => $pastaDasFontes . '/DejaVuSans-Bold.ttf',
            'mono'         => $pastaDasFontes . '/DejaVuSansMono.ttf',
            'mono-negrito' => $pastaDasFontes . '/DejaVuSansMono-Bold.ttf',
        ];
        $this->pagina = new PaginaDaFolha(new TelaDoComprovante(ImagemDaFolha::ESCALA, $fontes, ImagemDaFolha::ALTURA, ImagemDaFolha::QUALIDADE_JPEG));
        (new TabelaDaFolha($this->pagina))->desenhar([$linha]);
        $inicioDaColuna = ImagemDaFolha::X + 34 + 76 + 66 * 4 + 72 + 62 + 66 + 6;
        $observacoes = array_values(array_filter(
            $this->pagina->textos(),
            static fn (array $t): bool => abs($t['x'] - $inicioDaColuna) < .01 && $t['y'] > TabelaDaFolha::TOPO + 36,
        ));
        self::assertCount(1, $observacoes, 'uma observação, numa linha só');

        return $observacoes[0];
    }
}
