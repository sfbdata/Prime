<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ObrigacaoOutput;
use App\Cobranca\DTO\PrescricaoOutput;
use App\Cobranca\Service\CalculadoraPrescricao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A contagem de dias até o limite de ajuizamento (spec do cabeçalho §1.3). Calculadora PURA: relógio
 * sempre injetado, nenhuma consulta, nenhum `new \DateTimeImmutable()` escondido — é justamente isso
 * que permite fixar a faixa de severidade aqui sem esperar a data virar.
 *
 * As datas de vencimento dos casos de faixa são LITERAIS de propósito. Derivá-las com a mesma
 * aritmética do código sob teste (`agora + N dias − 5 anos`) faria o teste concordar com um erro de
 * um dia na subtração em vez de acusá-lo.
 */
#[CoversClass(CalculadoraPrescricao::class)]
final class CalculadoraPrescricaoTest extends TestCase
{
    private const AGORA = '2026-07-27';

    private CalculadoraPrescricao $calculadora;

    protected function setUp(): void
    {
        $this->calculadora = new CalculadoraPrescricao();
    }

    /**
     * Prazo de 5 anos sobre o vencimento, medido a partir de 27/07/2026. Cada linha fica UM dia do lado
     * de dentro ou de fora da fronteira — é onde `<=` vira `<` sem ninguém notar.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function faixas(): iterable
    {
        // Limite em 26/07/2026: ontem. Vencido por um dia já é esgotado.
        yield 'um dia depois do limite → esgotada' => ['2021-07-26', -1, PrescricaoOutput::SEVERIDADE_ESGOTADA];
        // Limite HOJE: ainda dá para ajuizar, e é o caso mais crítico que existe sem estar perdido.
        yield 'vence hoje → crítica' => ['2021-07-27', 0, PrescricaoOutput::SEVERIDADE_CRITICA];
        yield 'exatamente 90 dias → crítica' => ['2021-10-25', 90, PrescricaoOutput::SEVERIDADE_CRITICA];
        yield '91 dias → atenção' => ['2021-10-26', 91, PrescricaoOutput::SEVERIDADE_ATENCAO];
        yield 'exatamente 180 dias → atenção' => ['2022-01-23', 180, PrescricaoOutput::SEVERIDADE_ATENCAO];
        yield '181 dias → informativa' => ['2022-01-24', 181, PrescricaoOutput::SEVERIDADE_INFORMATIVA];
    }

    #[Test]
    #[DataProvider('faixas')]
    public function aSeveridadeSegueAFaixaDeDiasRestantes(string $vencimento, int $diasEsperados, string $severidade): void
    {
        $saida = $this->calcular([$this->obrigacao(1, $vencimento)]);

        self::assertNotNull($saida);
        self::assertSame($diasEsperados, $saida->diasRestantes);
        self::assertSame($severidade, $saida->severidade);
        self::assertSame($severidade === PrescricaoOutput::SEVERIDADE_ESGOTADA, $saida->esgotada());
    }

    #[Test]
    #[TestDox('sem obrigação em aberto não há o que prescrever — devolve null e a caixa some da tela')]
    public function semObrigacaoEmAbertoDevolveNull(): void
    {
        self::assertNull($this->calcular([]));
    }

    #[Test]
    #[TestDox('entre várias, escolhe a competência com o vencimento MAIS ANTIGO')]
    public function escolheAObrigacaoMaisAntiga(): void
    {
        // Fora de ordem de propósito: a mais antiga não é nem a primeira nem a última da lista.
        $saida = $this->calcular([
            $this->obrigacao(10, '2023-05-10', 'Maio/2023'),
            $this->obrigacao(11, '2022-02-08', 'Fevereiro/2022'),
            $this->obrigacao(12, '2024-11-30', 'Novembro/2024'),
        ]);

        self::assertNotNull($saida);
        self::assertSame(11, $saida->obrigacaoId, 'o link "Ver competência" aponta para a mais antiga');
        self::assertSame('Fevereiro/2022', $saida->obrigacaoDescricao);
        self::assertEquals(new \DateTimeImmutable('2022-02-08'), $saida->vencimentoMaisAntigo);
    }

    #[Test]
    #[TestDox('o prazo limite é o vencimento mais antigo somado a PRAZO_PADRAO_ANOS')]
    public function oPrazoLimiteSaiDaConstanteUnica(): void
    {
        $saida = $this->calcular([$this->obrigacao(1, '2022-02-08')]);

        self::assertNotNull($saida);
        self::assertEquals(new \DateTimeImmutable('2027-02-08'), $saida->prazoLimite);
        self::assertSame(CalculadoraPrescricao::PRAZO_PADRAO_ANOS, $saida->prazoAnos);
        self::assertSame(5, $saida->prazoAnos, 'a tela diz de onde saiu a conta: "5 anos"');
    }

    /**
     * A contagem é em DIAS DE CALENDÁRIO. Se ela medisse segundos, a mesma tela diria "90 dias" de
     * manhã e "89" à noite — e a faixa de severidade mudaria de cor sozinha ao longo do expediente.
     */
    #[Test]
    #[TestDox('o número de dias não muda ao longo do dia (contagem por calendário, não por segundos)')]
    public function aContagemEhEstavelAoLongoDoDia(): void
    {
        $obrigacoes = [$this->obrigacao(1, '2021-10-25')];

        $deManha = $this->calculadora->calcular($obrigacoes, new \DateTimeImmutable(self::AGORA . ' 07:00:00'));
        $aNoite = $this->calculadora->calcular($obrigacoes, new \DateTimeImmutable(self::AGORA . ' 23:59:59'));

        self::assertNotNull($deManha);
        self::assertNotNull($aNoite);
        self::assertSame(90, $deManha->diasRestantes);
        self::assertSame($deManha->diasRestantes, $aNoite->diasRestantes);
        self::assertSame($deManha->severidade, $aNoite->severidade);
    }

    /** @param list<ObrigacaoOutput> $obrigacoesEmAberto */
    private function calcular(array $obrigacoesEmAberto): ?PrescricaoOutput
    {
        return $this->calculadora->calcular($obrigacoesEmAberto, new \DateTimeImmutable(self::AGORA));
    }

    private function obrigacao(int $id, string $vencimento, string $descricao = 'Competência'): ObrigacaoOutput
    {
        return new ObrigacaoOutput(
            id: $id,
            descricao: $descricao,
            valorOriginal: 10000,
            encargosReconhecidos: 0,
            valorAtual: 10000,
            vencimentoOriginal: new \DateTimeImmutable($vencimento),
            referenciaExterna: null,
            substituidaPorAcordo: false,
            ehParcelaAcordo: false,
            parcelaDeAcordoDesfeito: false,
        );
    }
}
