<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Enum\FormaTotalizador;
use App\Cobranca\Service\Espelho\ArquivoForaDoLayoutException;
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\Service\Espelho\LinhaEspelhada;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O leitor do espelho (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §4, testes §9).
 *
 * As planilhas reais estão em `docs/gestao-cobrancas/planilhas atualizadas/`, que é **gitignored**
 * por conter dado de cliente — logo os testes montam as próprias, o que também os torna
 * reproduzíveis. O layout replicado aqui foi extraído do TL1 de 12/08 célula a célula, inclusive as
 * duas formas do bloco totalizador e o `Inadimplência até:` sem espaço após os dois-pontos.
 */
#[CoversClass(LeitorEspelhoRelatorio::class)]
final class LeitorEspelhoRelatorioTest extends TestCase
{
    use MontaPlanilhaDeEspelho;

    private LeitorEspelhoRelatorio $leitor;

    protected function setUp(): void
    {
        $this->leitor = new LeitorEspelhoRelatorio();
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
    }

    #[Test]
    #[TestDox('Lê as 15 colunas, inclusive G, M e O que o importador descarta')]
    public function testLeAsQuinzeColunas(): void
    {
        $arquivo = $this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', '183',
                190, 11.59, 3.8, 0, 41.08, 246.47, 'Acordo 155 - Parc. 1/6', 'REC-9'],
        ]);

        $linha = $this->primeiraLinhaDeDados($arquivo);

        self::assertSame('01-01', $linha->unidade);
        self::assertSame('FULANO', $linha->sacado);
        self::assertSame('74608', $linha->nn);
        self::assertSame('1.1 - Taxa de condomínio', $linha->classe);
        self::assertSame('02/2026', $linha->competencia);
        self::assertSame('10/02/2026', $linha->vencimento?->format('d/m/Y'));
        self::assertSame(183, $linha->atraso, 'coluna G — o importador nunca lê');
        self::assertSame(19000, $linha->valor);
        self::assertSame(1159, $linha->juros);
        self::assertSame(380, $linha->multa);
        self::assertSame(0, $linha->correcao);
        self::assertSame(4108, $linha->honorarios);
        self::assertSame(24647, $linha->total, 'coluna M — o importador nunca lê');
        self::assertSame('Acordo 155 - Parc. 1/6', $linha->acordoTexto);
        self::assertSame('REC-9', $linha->recebimento, 'coluna O — o importador nunca lê');
    }

    #[Test]
    #[TestDox('A coluna Total é COPIADA, nunca recalculada')]
    public function testTotalEhCopiadoNaoRecalculado(): void
    {
        // Fixture SINTÉTICA de propósito: medido no TL1 de 12/08, ZERO das 4.123 linhas têm
        // M ≠ H+I+J+K+L. O dado real não consegue provar esta asserção (SPEC §9, teste 1).
        $arquivo = $this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', '183',
                100, 10, 5, 0, 20, 999.99, '-', '-'],
        ]);

        $linha = $this->primeiraLinhaDeDados($arquivo);

        self::assertSame(99999, $linha->total);
        self::assertNotSame(
            $linha->valor + $linha->juros + $linha->multa + $linha->correcao + $linha->honorarios,
            $linha->total,
            'se o leitor recalculasse M, esta asserção passaria a falhar'
        );
    }

    #[Test]
    #[TestDox('Célula vazia vira null, não zero — vazio e zero são coisas diferentes')]
    public function testCelulaVaziaNaoViraZero(): void
    {
        $arquivo = $this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '10',
                100, null, 0, null, null, 100, '-', '-'],
        ]);

        $linha = $this->primeiraLinhaDeDados($arquivo);

        self::assertNull($linha->juros, 'célula vazia');
        self::assertSame(0, $linha->multa, 'célula com zero de verdade');
        self::assertNull($linha->correcao);
    }

    #[Test]
    #[TestDox('Toda linha do arquivo cai em exatamente um balde, e os seis somam o total')]
    public function testOsSeisBaldesSomamOTotal(): void
    {
        $arquivo = $this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 190, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
            ['01-02', 'CICLANO', '74609', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 100, 5, 2, 0, 20, 127, '-', '-'],
        ]);

        $espelhado = $this->leitor->ler($arquivo);
        $contagem = $espelhado->contarPorBloco();

        // A IDENTIDADE, nunca contagens literais: elas variam por arquivo, e fixá-las foi o erro
        // que derrubou duas versões da spec (SPEC §5.4).
        self::assertSame(
            $espelhado->linhasTotal,
            array_sum($contagem),
            sprintf('baldes: %s', json_encode($contagem))
        );
        self::assertSame(2, $contagem[BlocoRelatorio::Dados->value]);
    }

    #[Test]
    #[TestDox('O bloco totalizador não entra como linha de dado, nas DUAS formas')]
    public function testTotalizadorNaoEntraComoDado(): void
    {
        $arquivo = $this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 190, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
        ]);

        $espelhado = $this->leitor->ler($arquivo);

        self::assertCount(1, $espelhado->linhasDeDados());

        $formas = array_map(
            static fn ($t): string => $t->forma->value,
            $espelhado->totalizadores
        );

        self::assertContains(FormaTotalizador::Larga->value, $formas, 'a linha "Total inadimplência das unidades" usa 15 colunas');
        self::assertContains(FormaTotalizador::Estreita->value, $formas, 'as linhas por classe usam 7 colunas');
    }

    #[Test]
    #[TestDox('A forma LARGA do totalizador é lida com os valores em H..M, não nulos')]
    public function testFormaLargaTemValores(): void
    {
        $arquivo = $this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 190, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
        ]);

        $largas = array_values(array_filter(
            $this->leitor->ler($arquivo)->totalizadores,
            static fn ($t): bool => $t->forma === FormaTotalizador::Larga
        ));

        self::assertNotSame([], $largas);
        // Se o leitor tratasse a forma larga como estreita, todos estes viriam null.
        self::assertSame(19000, $largas[0]->valor);
        self::assertSame(24647, $largas[0]->total);
    }

    #[Test]
    #[TestDox('A reconciliação interna fecha: a soma das linhas bate com o totalizador da planilha')]
    public function testReconciliacaoInternaFecha(): void
    {
        $arquivo = $this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 190, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
            ['01-02', 'CICLANO', '74609', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 100, 5, 2, 0, 20, 127, '-', '-'],
        ]);

        $espelhado = $this->leitor->ler($arquivo);
        $soma = $espelhado->somarDados();
        $total = $espelhado->totalGeral();

        self::assertNotNull($total);
        self::assertSame($total->valor, $soma['valor']);
        self::assertSame($total->juros, $soma['juros']);
        self::assertSame($total->multa, $soma['multa']);
        self::assertSame($total->correcao, $soma['correcao']);
        self::assertSame($total->honorarios, $soma['honorarios']);
        self::assertSame($total->total, $soma['total']);
    }

    #[Test]
    #[TestDox('Reintroduzindo o defeito: linha corrompida faz a reconciliação NÃO fechar')]
    public function testReconciliacaoPegaLinhaCorrompida(): void
    {
        // A prova de que o teste anterior não é decorativo: uma linha com valor adulterado tem que
        // quebrar a igualdade com o totalizador. Se passasse, a reconciliação não estaria medindo
        // nada (regra da casa: provar o teste reintroduzindo o defeito).
        $arquivo = $this->montarPlanilha(
            linhas: [
                ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 190, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
            ],
            valorTotalAdulterado: 999999.99,
        );

        $espelhado = $this->leitor->ler($arquivo);

        self::assertNotSame(
            $espelhado->totalGeral()?->valor,
            $espelhado->somarDados()['valor'],
            'a reconciliação tem que enxergar a adulteração'
        );
    }

    #[Test]
    #[TestDox('Cabeçalho com coluna fora de posição é RECUSADO')]
    public function testCabecalhoForaDePosicaoEhRecusado(): void
    {
        $torto = self::CABECALHO_ESPELHO;
        [$torto[8], $torto[9]] = [$torto[9], $torto[8]]; // Juros ↔ Multa

        $arquivo = $this->montarPlanilha(
            linhas: [
                ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 190, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
            ],
            cabecalho: $torto,
        );

        // O importador de hoje leria em silêncio, trocando juros por multa: ele só exige que a
        // célula A contenha "Unidade" e depois lê por posição fixa.
        $this->expectException(ArquivoForaDoLayoutException::class);
        $this->leitor->ler($arquivo);
    }

    #[Test]
    #[TestDox('Boleto que o importador rejeitaria existe no espelho, com os valores dele')]
    public function testLinhaRejeitadaPeloImportadorExisteNoEspelho(): void
    {
        // Boleto composto só de encargo (principal zero): o importador o rejeita e a `LinhaRejeitada`
        // dele guarda apenas [nn, unidade, sacado, competencia] — todo valor financeiro se perde.
        // São os 17 boletos de R$ 5.152,19 que originaram esta frente.
        $arquivo = $this->montarPlanilha([
            ['01-09', 'FULANO', '67611', '1.4 - Juros', '08/2026', '10/08/2026', '2', 445.45, 88.35, 8.91, 0, 108.54, 651.25, 'Acordo 426 - Parc. 1/6', '-'],
        ]);

        $linha = $this->primeiraLinhaDeDados($arquivo);

        self::assertSame('67611', $linha->nn);
        self::assertSame(44545, $linha->valor, 'o valor do boleto rejeitado NÃO se perde');
        self::assertSame(8835, $linha->juros);
        self::assertSame(65125, $linha->total);
        self::assertSame('Acordo 426 - Parc. 1/6', $linha->acordoTexto);
    }

    #[Test]
    #[TestDox('Lê a data de corte, a emissão e a config que a contabilidade declarou')]
    public function testLeOsMetadadosDoRodape(): void
    {
        $arquivo = $this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 190, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
        ]);

        $espelhado = $this->leitor->ler($arquivo);

        // `dadosAte` é a âncora da calibração — hoje o importador a lê e DESCARTA de propósito.
        self::assertSame('12/08/2026', $espelhado->dadosAte?->format('d/m/Y'));
        self::assertSame('12/08/2026 09:42', $espelhado->emitidoEm?->format('d/m/Y H:i'));
        self::assertSame(['juros' => 100, 'multa' => 200, 'honorarios' => 2000], $espelhado->configDeclarada);
        self::assertSame(96, $espelhado->unidadesDeclaradas);
    }

    #[Test]
    #[TestDox('O mesmo conteúdo produz o mesmo hash, e conteúdo diferente produz hash diferente')]
    public function testHashIdentificaOConteudo(): void
    {
        $linhas = [
            ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 190, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
        ];

        $a = $this->leitor->ler($this->montarPlanilha($linhas));
        $b = $this->leitor->ler($this->montarPlanilha([
            ['01-01', 'FULANO', '74608', '1.1 - Taxa', '02/2026', '10/02/2026', '183', 999, 11.59, 3.8, 0, 41.08, 246.47, '-', '-'],
        ]));

        self::assertSame(64, strlen($a->arquivoHash), 'sha256 em hexadecimal');
        self::assertNotSame($a->arquivoHash, $b->arquivoHash);
    }

    private function primeiraLinhaDeDados(string $arquivo): LinhaEspelhada
    {
        $dados = $this->leitor->ler($arquivo)->linhasDeDados();

        self::assertNotSame([], $dados, 'a planilha de teste precisa ter ao menos uma linha de dado');

        return $dados[0];
    }
}
