<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A decisão ÚNICA de quais batidas do dia valem (`docs/specs/ponto-folha-uma-batida-por-tipo.md` §9.3,
 * §9.9 e §10). Folha, células, intervalo, saldo e o quadro "suas batidas de hoje" leem esta escolha.
 *
 * As datas ficam no passado de propósito, com a vigência injetada em 01/05/2026: a folha não apura dia
 * futuro, então testar a regra nova com a vigência real (futura) não provaria nada no `buildRows`.
 */
#[CoversClass(EscolhaDasBatidasDoDia::class)]
#[CoversClass(BatidasEscolhidas::class)]
final class EscolhaDasBatidasDoDiaTest extends TestCase
{
    private const VIGENCIA   = '2026-05-01';
    private const DIA_LEGADO = '2026-04-15';
    private const DIA_NOVO   = '2026-05-15';

    private EscolhaDasBatidasDoDia $escolha;

    protected function setUp(): void
    {
        $this->escolha = new EscolhaDasBatidasDoDia(self::VIGENCIA);
    }

    /** @return array<string, array{0: string}> */
    public static function dias(): array
    {
        return [
            'antes da vigência (regra legada)' => [self::DIA_LEGADO],
            'depois da vigência (regra única)' => [self::DIA_NOVO],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Repetição do mesmo registro: mesmo tipo, consecutivas, até 5 min
    // ──────────────────────────────────────────────────────────────────

    #[DataProvider('dias')]
    public function testRepeticaoDoMesmoTipoValeAPrimeiraESemMarca(string $dia): void
    {
        $repouso = $this->batida('repouso', '12:43:00', $dia);
        $repeticao = $this->batida('repouso', '12:43:24', $dia);

        $escolha = $this->escolher([
            $this->batida('entrada', '10:14:21', $dia),
            $repouso,
            $repeticao,
            $this->batida('retorno', '13:57:51', $dia),
            $this->batida('saida', '18:09:26', $dia),
        ], $dia);

        self::assertSame($repouso, $escolha->repouso);
        self::assertSame([$repeticao], $escolha->desconsideradas);
        self::assertSame([], $escolha->aConferir, 'repetir o mesmo registro não é ambiguidade');
    }

    #[DataProvider('dias')]
    public function testRepeticaoDaSaidaValeAUltima(string $dia): void
    {
        $primeira = $this->batida('saida', '17:18:53', $dia);
        $ultima = $this->batida('saida', '17:20:01', $dia);

        $escolha = $this->escolher([$this->batida('entrada', '07:10:00', $dia), $primeira, $ultima], $dia);

        self::assertSame($ultima, $escolha->saida);
        self::assertSame([$primeira], $escolha->desconsideradas);
        self::assertSame([], $escolha->aConferir);
    }

    public function testJanelaDeRepeticaoVaiAteTrezentosSegundosExatos(): void
    {
        $noLimite = $this->escolher([
            $this->batida('repouso', '12:00:00', self::DIA_NOVO),
            $this->batida('repouso', '12:05:00', self::DIA_NOVO),
        ], self::DIA_NOVO);
        self::assertSame([], $noLimite->aConferir, '300 s ainda é o mesmo registro');

        $umSegundoDepois = $this->escolher([
            $this->batida('repouso', '12:00:00', self::DIA_NOVO),
            $this->batida('repouso', '12:05:01', self::DIA_NOVO),
        ], self::DIA_NOVO);
        self::assertSame([BatidasEscolhidas::MARCA_REPOUSOS_DISTINTOS], $umSegundoDepois->aConferir, '301 s já são dois repousos');
    }

    public function testJanelaSeMedeContraARepeticaoAnterior(): void
    {
        // 12:00 → 12:04 → 12:08: cada uma a 4 min da anterior. É um toque só, repetido.
        $escolha = $this->escolher([
            $this->batida('repouso', '12:00:00', self::DIA_NOVO),
            $this->batida('repouso', '12:04:00', self::DIA_NOVO),
            $this->batida('repouso', '12:08:00', self::DIA_NOVO),
        ], self::DIA_NOVO);

        self::assertSame('12:00:00', $escolha->repouso?->getDataHora()->format('H:i:s'));
        self::assertSame([], $escolha->aConferir);
    }

    public function testRepeticaoSoValeEntreBatidasConsecutivas(): void
    {
        // Um retorno no meio separa os dois repousos: não é o mesmo registro repetido.
        $escolha = $this->escolher([
            $this->batida('repouso', '12:00:00', self::DIA_NOVO),
            $this->batida('retorno', '12:01:00', self::DIA_NOVO),
            $this->batida('repouso', '12:02:00', self::DIA_NOVO),
        ], self::DIA_NOVO);

        self::assertContains(BatidasEscolhidas::MARCA_REPOUSOS_DISTINTOS, $escolha->aConferir);
    }

    // ──────────────────────────────────────────────────────────────────
    // Dia ambíguo: marcado, nunca reinterpretado
    // ──────────────────────────────────────────────────────────────────

    #[DataProvider('dias')]
    public function testDoisRepousosDistintosMantemOPrimeiroEMarcam(string $dia): void
    {
        // O 19/06 da produção: repousos a 62 min um do outro. Decisão do dono: não escolher sozinho.
        $primeiro = $this->batida('repouso', '12:28:42', $dia);

        $escolha = $this->escolher([
            $this->batida('entrada', '08:32:00', $dia),
            $primeiro,
            $this->batida('repouso', '13:30:55', $dia),
            $this->batida('retorno', '14:56:00', $dia),
            $this->batida('saida', '19:20:00', $dia),
        ], $dia);

        self::assertSame($primeiro, $escolha->repouso);
        self::assertSame([BatidasEscolhidas::MARCA_REPOUSOS_DISTINTOS], $escolha->aConferir);
        self::assertTrue($escolha->temIntervalo());
    }

    #[DataProvider('dias')]
    public function testRetornoAntesDoRepousoNaoTemIntervaloEMarca(string $dia): void
    {
        $escolha = $this->escolher([
            $this->batida('entrada', '08:35:21', $dia),
            $this->batida('retorno', '12:15:37', $dia),
            $this->batida('repouso', '12:16:03', $dia),
            $this->batida('saida', '17:03:08', $dia),
        ], $dia);

        self::assertFalse($escolha->temIntervalo());
        self::assertSame([BatidasEscolhidas::MARCA_RETORNO_ANTES_DO_REPOUSO], $escolha->aConferir);
    }

    public function testRetornoErradoCedoNaoEReinterpretadoDepoisDaVigencia(): void
    {
        // Divergência registrada no §10.1: o par real (12:00→13:00) NÃO é escolhido sozinho.
        $retornoCedo = $this->batida('retorno', '08:05:00', self::DIA_NOVO);

        $escolha = $this->escolher([
            $this->batida('entrada', '08:00:00', self::DIA_NOVO),
            $retornoCedo,
            $this->batida('repouso', '12:00:00', self::DIA_NOVO),
            $this->batida('retorno', '13:00:00', self::DIA_NOVO),
            $this->batida('saida', '18:00:00', self::DIA_NOVO),
        ], self::DIA_NOVO);

        self::assertSame($retornoCedo, $escolha->retorno);
        self::assertFalse($escolha->temIntervalo());
        self::assertSame(
            [BatidasEscolhidas::MARCA_RETORNOS_DISTINTOS, BatidasEscolhidas::MARCA_RETORNO_ANTES_DO_REPOUSO],
            $escolha->aConferir
        );
    }

    public function testCadaTipoRepetidoEmMomentosDiferentesGeraSuaMarca(): void
    {
        $escolha = $this->escolher([
            $this->batida('entrada', '08:19:10', self::DIA_NOVO),
            $this->batida('entrada', '12:10:47', self::DIA_NOVO),
            $this->batida('repouso', '12:17:00', self::DIA_NOVO),
            $this->batida('retorno', '13:18:33', self::DIA_NOVO),
            $this->batida('retorno', '17:12:00', self::DIA_NOVO),
            $this->batida('saida', '17:30:00', self::DIA_NOVO),
            $this->batida('saida', '23:57:14', self::DIA_NOVO),
        ], self::DIA_NOVO);

        self::assertSame('08:19:10', $escolha->entrada?->getDataHora()->format('H:i:s'));
        self::assertSame('13:18:33', $escolha->retorno?->getDataHora()->format('H:i:s'));
        self::assertSame('23:57:14', $escolha->saida?->getDataHora()->format('H:i:s'));
        self::assertSame([
            BatidasEscolhidas::MARCA_ENTRADAS_DISTINTAS,
            BatidasEscolhidas::MARCA_RETORNOS_DISTINTOS,
            BatidasEscolhidas::MARCA_SAIDAS_DISTINTAS,
        ], $escolha->aConferir);
        self::assertCount(3, $escolha->desconsideradas);
    }

    public function testDiaSemBatidaNaoEscolheNadaNemMarca(): void
    {
        $escolha = $this->escolha->escolher([], new \DateTimeImmutable(self::DIA_NOVO));

        self::assertNull($escolha->entrada);
        self::assertNull($escolha->repouso);
        self::assertNull($escolha->retorno);
        self::assertNull($escolha->saida);
        self::assertSame([], $escolha->escolhidas());
        self::assertSame([], $escolha->aConferir);
        self::assertFalse($escolha->temIntervalo());
    }

    public function testListaVaziaSemDiaInformadoNaoQuebra(): void
    {
        $escolha = $this->escolha->escolher([]);

        self::assertSame([], $escolha->escolhidas());
        self::assertSame([], $escolha->desconsideradas);
    }

    public function testEscolhidasVemNaOrdemDosQuatroTiposEDoTipoDevolveCadaUma(): void
    {
        $entrada = $this->batida('entrada', '09:00:00', self::DIA_NOVO);
        $repouso = $this->batida('repouso', '12:00:00', self::DIA_NOVO);
        $retorno = $this->batida('retorno', '13:00:00', self::DIA_NOVO);
        $saida = $this->batida('saida', '18:00:00', self::DIA_NOVO);

        $escolha = $this->escolher([$saida, $retorno, $repouso, $entrada], self::DIA_NOVO);

        self::assertSame([$entrada, $repouso, $retorno, $saida], $escolha->escolhidas());
        self::assertSame($retorno, $escolha->doTipo(RegistroPonto::TIPO_RETORNO));
        self::assertTrue($escolha->temIntervalo());
    }

    // ──────────────────────────────────────────────────────────────────
    // Vigência
    // ──────────────────────────────────────────────────────────────────

    public function testDiaAnteriorAVigenciaUsaARegraLegadaEODiaDelaAUnica(): void
    {
        $vespera = $this->escolher([$this->batida('entrada', '09:00:00', '2026-04-30')], '2026-04-30');
        $primeiroDia = $this->escolher([$this->batida('entrada', '09:00:00', '2026-05-01')], '2026-05-01');

        self::assertSame(BatidasEscolhidas::REGRA_LEGADA, $vespera->regra);
        self::assertSame(BatidasEscolhidas::REGRA_UNICA, $primeiroDia->regra);
    }

    public function testSemDiaInformadoAVigenciaSaiDaPropriaBatida(): void
    {
        $escolha = $this->escolha->escolher([$this->batida('entrada', '09:00:00', self::DIA_LEGADO)]);

        self::assertSame(BatidasEscolhidas::REGRA_LEGADA, $escolha->regra);
    }

    public function testRegraLegadaPreservaAOrdemEmQueAsBatidasChegam(): void
    {
        // O código de antes da vigência tomava a primeira batida DA LISTA, não a mais cedo. Com a lista
        // do repositório (ordenada por horário) dá no mesmo; fora dela, a legada fica como era.
        $chegouPrimeiro = $this->batida('repouso', '12:30:00', self::DIA_LEGADO);

        $escolha = $this->escolher([$chegouPrimeiro, $this->batida('repouso', '12:00:00', self::DIA_LEGADO)], self::DIA_LEGADO);

        self::assertSame($chegouPrimeiro, $escolha->repouso);
    }

    public function testRegraUnicaOrdenaPorHorarioEDepoisPorId(): void
    {
        $maisCedo = $this->batida('repouso', '12:00:00', self::DIA_NOVO);
        $escolha = $this->escolher([$this->batida('repouso', '12:30:00', self::DIA_NOVO), $maisCedo], self::DIA_NOVO);
        self::assertSame($maisCedo, $escolha->repouso);

        $idMaior = $this->batida('repouso', '12:00:00', self::DIA_NOVO, 20);
        $idMenor = $this->batida('repouso', '12:00:00', self::DIA_NOVO, 10);
        $empate = $this->escolher([$idMaior, $idMenor], self::DIA_NOVO);
        self::assertSame($idMenor, $empate->repouso, 'no mesmo segundo, vale o registro gravado primeiro');
    }

    public function testVigenciaPadraoEhSempreOPrimeiroDiaDeUmaCompetencia(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-01$/', EscolhaDasBatidasDoDia::VIGENCIA);
        self::assertSame(EscolhaDasBatidasDoDia::VIGENCIA, (new EscolhaDasBatidasDoDia())->vigencia()->format('Y-m-d'));
    }

    // ──────────────────────────────────────────────────────────────────

    /** @param list<RegistroPonto> $batidas */
    private function escolher(array $batidas, string $dia): BatidasEscolhidas
    {
        return $this->escolha->escolher($batidas, new \DateTimeImmutable($dia));
    }

    private function batida(string $tipo, string $hora, string $dia, ?int $id = null): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTimeImmutable("{$dia} {$hora}"));
        if ($id !== null) {
            (new \ReflectionProperty(RegistroPonto::class, 'id'))->setValue($registro, $id);
        }

        return $registro;
    }
}
