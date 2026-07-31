<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Importacao\AcordoDetalhadoImportavel;
use App\Cobranca\Service\Importacao\AcordosDetalhadosAdapter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Leitura da fonte "Acordos detalhados" da contábil L.G (spec
 * `docs/specs/cobranca-importar-acordos-detalhados.md` §2/§9).
 *
 * A fixture é MONTADA aqui, célula a célula, reproduzindo o layout medido na planilha real de
 * 2026-07-29 — inclusive as três armadilhas que o dado real tem e um exemplo inventado não teria:
 *
 * 1. TODAS as células são STRING, nunca número. "170,00" não é o float 170.
 * 2. O desconto vem como `-\u{00A0}3,04`: sinal separado do número por um espaço NÃO-QUEBRÁVEL.
 *    `str_replace(',', '.')` seguido de `is_numeric()` recusa isso e derrubaria a parcela inteira.
 * 3. O cabeçalho usa separador de milhar: "1.020,00" são mil e vinte reais, não 1,02.
 *
 * A planilha real não pode virar fixture: tem CPF de 229 pessoas (gitignored). A conferência contra
 * ela é feita à parte, comparando as somas por aba com o cabeçalho de cada uma.
 */
#[CoversClass(AcordosDetalhadosAdapter::class)]
final class AcordosDetalhadosAdapterTest extends TestCase
{
    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        $this->temporarios = [];
    }

    #[TestDox('Lê uma aba por acordo, com cabeçalho, contas originais e parcelas')]
    public function testLeUmaAbaPorAcordo(): void
    {
        $leitura = (new AcordosDetalhadosAdapter())->ler($this->planilhaCompleta());

        self::assertCount(2, $leitura->acordos, 'duas abas, dois acordos');
        self::assertSame([], $leitura->rejeitadas);

        $primeiro = $leitura->acordos[0];
        self::assertSame(28, $primeiro->numero);
        self::assertSame('QUADRA 01 CHACARA 01/09', $primeiro->unidade);
        self::assertSame('FRANCISCO DE CARVALHO COUTINHO', $primeiro->sacado);
        self::assertSame('Em andamento', $primeiro->situacao);
        self::assertSame('2026-06-18', $primeiro->dataBase?->format('Y-m-d'));
        self::assertSame('2026-06-18', $primeiro->criadoEm?->format('Y-m-d'));
        self::assertSame('2026-07-29', $primeiro->emissao?->format('Y-m-d'));
    }

    #[TestDox('Separador de milhar no cabeçalho: "1.020,00" são R$ 1.020,00, não R$ 1,02')]
    public function testMilharDoCabecalho(): void
    {
        $acordo = $this->primeiroAcordo();

        self::assertSame(102000, $acordo->valorTotalContasOriginaisCentavos);
        self::assertSame(114750, $acordo->valorFinalAcordadoCentavos);
    }

    #[TestDox('As duas seções não se misturam: contas originais e parcelas saem separadas')]
    public function testDuasSecoesSeparadas(): void
    {
        $acordo = $this->primeiroAcordo();

        self::assertCount(6, $acordo->contasOriginais, 'as 6 contas originais');
        self::assertCount(1, $acordo->parcelas, 'um único NN de parcela (1/1)');

        self::assertSame(['60394', '60396', '60490', '60747', '61032', '61263'], array_map(
            static fn ($c): string => $c->nn,
            $acordo->contasOriginais,
        ));
        self::assertSame('61366', $acordo->parcelas[0]->nn);
    }

    #[TestDox('Conta original carrega NN, competência, vencimento e valor — a chave de casamento é NN+competência')]
    public function testCamposDaContaOriginal(): void
    {
        $conta = $this->primeiroAcordo()->contasOriginais[0];

        self::assertSame('60394', $conta->nn);
        self::assertSame('1.1 - Taxa de condomínio', $conta->classe);
        self::assertSame('02/2026', $conta->competencia);
        self::assertSame('2026-02-13', $conta->vencimento->format('Y-m-d'));
        self::assertSame(17000, $conta->valorCentavos);
    }

    #[TestDox('Um NN de parcela ocupa várias linhas: o valor da parcela é a SOMA delas')]
    public function testParcelaEmVariasLinhasSoma(): void
    {
        $parcela = $this->primeiroAcordo()->parcelas[0];

        // 6 taxas de R$ 170,00 + honorário de R$ 127,50 = R$ 1.147,50 — o "Valor final acordado" do cabeçalho.
        self::assertSame(114750, $parcela->valorCentavos);
        self::assertSame(114750, $this->primeiroAcordo()->valorFinalAcordadoCentavos);
    }

    #[TestDox('Desconto negativo com espaço não-quebrável ("-\u{00A0}3,04") entra como -304 centavos')]
    public function testDescontoNegativoComEspacoNaoQuebravel(): void
    {
        $leitura = (new AcordosDetalhadosAdapter())->ler($this->planilhaCompleta());
        $acordo = $leitura->acordos[1];

        self::assertSame([], $leitura->rejeitadas, 'o desconto não pode derrubar a parcela');

        // 170,00 + 48,66 + 30,20 + 20,40 - 3,04 + 134,46 = 400,68
        self::assertSame(40068, $acordo->parcelas[0]->valorCentavos);
    }

    #[TestDox('Competência da parcela vem da COLUNA Competência, não do sufixo da classe de conta')]
    public function testCompetenciaDaParcelaVemDaColuna(): void
    {
        // A classe diz "1.1 - Taxa de condomínio - 01/2026" (o mês da taxa renegociada) e a coluna diz
        // 06/2026 (o mês do boleto da parcela). É a coluna que casa com a inadimplência — usar o sufixo
        // faria o próximo import de inadimplência criar uma SEGUNDA parcela para o mesmo boleto.
        $parcela = $this->primeiroAcordo()->parcelas[0];

        self::assertSame('06/2026', $parcela->competencia);
        self::assertSame('2026-06-18', $parcela->vencimento->format('Y-m-d'));
    }

    #[TestDox('Classe de conta perde o sufixo: vira o mesmo rótulo que a inadimplência grava')]
    public function testClasseNormalizada(): void
    {
        $parcela = $this->primeiroAcordo()->parcelas[0];

        self::assertSame(['1.1 - Taxa de condomínio', '1.15 - Honorário advocatício'], array_values(array_unique($parcela->classes)));
        self::assertStringContainsString('1.1 - Taxa de condomínio + 1.15 - Honorário advocatício', $parcela->descricao());
        self::assertStringContainsString('competência 06/2026', $parcela->descricao());
        self::assertStringContainsString('Acordo 28 - Parc. 1/1', $parcela->descricao());
    }

    #[TestDox('Parcela guarda p/t da coluna Parcela')]
    public function testNumeroETotalDaParcela(): void
    {
        $parcelas = (new AcordosDetalhadosAdapter())->ler($this->planilhaCompleta())->acordos[1]->parcelas;

        self::assertCount(2, $parcelas);
        self::assertSame([1, 3], [$parcelas[0]->numero, $parcelas[0]->total]);
        self::assertSame([2, 3], [$parcelas[1]->numero, $parcelas[1]->total]);
    }

    #[TestDox('Rodapé (Filtros / endereço / Emissão) é ignorado, não vira dado')]
    public function testRodapeIgnorado(): void
    {
        $leitura = (new AcordosDetalhadosAdapter())->ler($this->planilhaCompleta());

        self::assertSame([], $leitura->rejeitadas);
        self::assertGreaterThan(0, $leitura->linhasIgnoradas);
        foreach ($leitura->acordos as $acordo) {
            foreach ($acordo->contasOriginais as $conta) {
                self::assertMatchesRegularExpression('/^\d+$/', $conta->nn);
            }
        }
    }

    #[TestDox('Aba sem seção de parcelas ainda vale: o acordo entra com contas originais e zero parcelas')]
    public function testAbaSemSecaoDeParcelas(): void
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Acordo n40');
        $this->escreverCabecalho($aba, 40, 'QUADRA 09 CHACARA 01/01', 'SEM PARCELAS', '170,00', '170,00');
        $this->escreverContasOriginais($aba, 12, [['60001', '1.1 - Taxa de condomínio', '05/2026', '13/05/2026', '170,00']]);
        $this->escreverRodape($aba, 15);

        $leitura = (new AcordosDetalhadosAdapter())->ler($this->salvar($planilha));

        self::assertCount(1, $leitura->acordos);
        self::assertCount(1, $leitura->acordos[0]->contasOriginais);
        self::assertSame([], $leitura->acordos[0]->parcelas);
    }

    #[TestDox('Aba sem "Acordo de número" é rejeitada inteira, com o nome da aba como referência')]
    public function testAbaSemNumeroDeAcordoEhRejeitada(): void
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Planilha1');
        $aba->setCellValueExplicit('A1', 'Relatório qualquer', DataType::TYPE_STRING);

        $leitura = (new AcordosDetalhadosAdapter())->ler($this->salvar($planilha));

        self::assertSame([], $leitura->acordos);
        self::assertCount(1, $leitura->rejeitadas);
        self::assertSame('Planilha1', $leitura->rejeitadas[0]->referencia);
    }

    #[TestDox('Conta original sem competência válida é REJEITADA — sem ela não há como casar com segurança')]
    public function testContaOriginalSemCompetenciaEhRejeitada(): void
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Acordo n41');
        $this->escreverCabecalho($aba, 41, 'QUADRA 09 CHACARA 02/02', 'SEM COMPETENCIA', '340,00', '340,00');
        $this->escreverContasOriginais($aba, 12, [
            ['60002', '1.1 - Taxa de condomínio', '-', '13/05/2026', '170,00'],
            ['60003', '1.1 - Taxa de condomínio', '06/2026', '13/06/2026', '170,00'],
        ]);

        $leitura = (new AcordosDetalhadosAdapter())->ler($this->salvar($planilha));

        self::assertCount(1, $leitura->acordos[0]->contasOriginais, 'só a conta com competência entra');
        self::assertSame('60003', $leitura->acordos[0]->contasOriginais[0]->nn);
        self::assertCount(1, $leitura->rejeitadas);
        self::assertSame('60002', $leitura->rejeitadas[0]->referencia);
        self::assertStringContainsString('competência', $leitura->rejeitadas[0]->motivo);
    }

    #[TestDox('Valor não numérico rejeita a linha em vez de virar zero silencioso')]
    public function testValorNaoNumericoEhRejeitado(): void
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Acordo n42');
        $this->escreverCabecalho($aba, 42, 'QUADRA 09 CHACARA 03/03', 'VALOR RUIM', '170,00', '170,00');
        $this->escreverContasOriginais($aba, 12, [['60004', '1.1 - Taxa de condomínio', '05/2026', '13/05/2026', 'a combinar']]);

        $leitura = (new AcordosDetalhadosAdapter())->ler($this->salvar($planilha));

        self::assertSame([], $leitura->acordos[0]->contasOriginais);
        self::assertCount(1, $leitura->rejeitadas);
        self::assertStringContainsString('numérico', $leitura->rejeitadas[0]->motivo);
    }

    #[TestDox('Conta original de valor zero é rejeitada — reconstruir dívida zerada seria inventar passivo')]
    public function testContaOriginalComValorZeroEhRejeitada(): void
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Acordo n44');
        $this->escreverCabecalho($aba, 44, 'QUADRA 09 CHACARA 05/05', 'VALOR ZERO', '170,00', '170,00');
        $this->escreverContasOriginais($aba, 12, [
            ['60005', '1.1 - Taxa de condomínio', '05/2026', '13/05/2026', '0,00'],
            ['60006', '1.1 - Taxa de condomínio', '06/2026', '13/06/2026', '170,00'],
        ]);

        $leitura = (new AcordosDetalhadosAdapter())->ler($this->salvar($planilha));

        self::assertCount(1, $leitura->acordos[0]->contasOriginais);
        self::assertSame('60006', $leitura->acordos[0]->contasOriginais[0]->nn);
        self::assertCount(1, $leitura->rejeitadas);
        self::assertSame('60005', $leitura->rejeitadas[0]->referencia);
    }

    /**
     * A seção de contas originais tinha o defeito espelhado do que a de parcelas já resolvia: uma linha
     * da planilha virava um objeto. Se a fonte quebrar UMA conta em principal + juros + multa (é o que
     * ela faz com as parcelas), o UseCase processaria a mesma dívida N vezes — e na reconstrução do
     * §3.2.1 tentaria criar N obrigações com o mesmo NN+competência, batendo no índice único e
     * derrubando o LOTE inteiro por causa de uma aba.
     *
     * O agrupamento é por NN **+ competência**: o mesmo NN em competências diferentes são contas
     * diferentes — é a regra que orienta a frente toda.
     */
    #[TestDox('Uma conta original em várias linhas vira UMA conta com a SOMA (e NN repetido em outra competência não funde)')]
    public function testContaOriginalEmVariasLinhasSoma(): void
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Acordo n45');
        $this->escreverCabecalho($aba, 45, 'QUADRA 09 CHACARA 06/06', 'CONTA EM PARTES', '420,00', '420,00');
        $this->escreverContasOriginais($aba, 12, [
            ['60007', '1.1 - Taxa de condomínio', '05/2026', '13/05/2026', '170,00'],
            ['60007', '1.2 - Juros', '05/2026', '13/05/2026', '12,50'],
            ['60007', '1.3 - Multa', '05/2026', '13/05/2026', '3,40'],
            // MESMO NN, outra competência: é outra dívida e continua separada.
            ['60007', '1.1 - Taxa de condomínio', '06/2026', '13/06/2026', '170,00'],
        ]);

        $contas = (new AcordosDetalhadosAdapter())->ler($this->salvar($planilha))->acordos[0]->contasOriginais;

        self::assertCount(2, $contas, '3 linhas de 05/2026 viram UMA conta; a de 06/2026 é outra');
        self::assertSame('05/2026', $contas[0]->competencia);
        self::assertSame(18590, $contas[0]->valorCentavos, '170,00 + 12,50 + 3,40');
        self::assertSame('06/2026', $contas[1]->competencia);
        self::assertSame(17000, $contas[1]->valorCentavos);
        // A classe é a da PRIMEIRA linha do grupo — a conta reconstruída tem de parecer uma taxa, não um juro.
        self::assertStringContainsString('1.1 - Taxa de condomínio', $contas[0]->descricao());
    }

    #[TestDox('Parcela com liquidação informada é sinalizada (a baixa de pagamento está fora de escopo)')]
    public function testParcelaComLiquidacaoEhSinalizada(): void
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Acordo n43');
        $this->escreverCabecalho($aba, 43, 'QUADRA 09 CHACARA 04/04', 'JA PAGOU', '170,00', '170,00');
        $this->escreverParcelas($aba, 12, [
            ['61700', '1.1 - Taxa de condomínio - 05/2026', '1/1', '06/2026', '10/06/2026', '10/06/2026', '170,00', '170,00'],
        ]);

        $acordo = (new AcordosDetalhadosAdapter())->ler($this->salvar($planilha))->acordos[0];

        self::assertTrue($acordo->parcelas[0]->constaLiquidada);
    }

    private function primeiroAcordo(): AcordoDetalhadoImportavel
    {
        return (new AcordosDetalhadosAdapter())->ler($this->planilhaCompleta())->acordos[0];
    }

    /**
     * Duas abas reproduzindo o layout real: "Acordo n28" (1 parcela 1/1 em 7 linhas) e "Acordo n31"
     * (3 parcelas, com desconto negativo).
     */
    private function planilhaCompleta(): string
    {
        $planilha = new Spreadsheet();

        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Acordo n28');
        $this->escreverCabecalho($aba, 28, 'QUADRA 01 CHACARA 01/09', 'FRANCISCO DE CARVALHO COUTINHO', '1.020,00', '1.147,50');
        $linha = $this->escreverContasOriginais($aba, 12, [
            ['60394', '1.1 - Taxa de condomínio', '02/2026', '13/02/2026', '170,00'],
            ['60396', '1.1 - Taxa de condomínio', '01/2026', '13/02/2026', '170,00'],
            ['60490', '1.1 - Taxa de condomínio', '03/2026', '13/03/2026', '170,00'],
            ['60747', '1.1 - Taxa de condomínio', '04/2026', '13/04/2026', '170,00'],
            ['61032', '1.1 - Taxa de condomínio', '05/2026', '13/05/2026', '170,00'],
            ['61263', '1.1 - Taxa de condomínio', '06/2026', '13/06/2026', '170,00'],
        ]);
        $linha = $this->escreverParcelas($aba, $linha + 1, [
            ['61366', '1.1 - Taxa de condomínio - 01/2026', '1/1', '06/2026', '18/06/2026', '-', '170,00', '-'],
            ['61366', '1.1 - Taxa de condomínio - 02/2026', '1/1', '06/2026', '18/06/2026', '-', '170,00', '-'],
            ['61366', '1.1 - Taxa de condomínio - 03/2026', '1/1', '06/2026', '18/06/2026', '-', '170,00', '-'],
            ['61366', '1.1 - Taxa de condomínio - 04/2026', '1/1', '06/2026', '18/06/2026', '-', '170,00', '-'],
            ['61366', '1.1 - Taxa de condomínio - 05/2026', '1/1', '06/2026', '18/06/2026', '-', '170,00', '-'],
            ['61366', '1.1 - Taxa de condomínio - 06/2026', '1/1', '06/2026', '18/06/2026', '-', '170,00', '-'],
            ['61366', '1.15 - Honorário advocatício - 15,00% com reajuste - 06/2026', '1/1', '06/2026', '18/06/2026', '-', '127,50', '-'],
        ]);
        $this->escreverRodape($aba, $linha + 2);

        $aba = $planilha->createSheet();
        $aba->setTitle('Acordo n31');
        $this->escreverCabecalho($aba, 31, 'QUADRA 04 CHACARA 02/23', 'ALILSON PEREIRA DE SOUSA', '1.020,00', '1.202,02');
        $linha = $this->escreverContasOriginais($aba, 12, [
            ['60049', '1.1 - Taxa de condomínio', '01/2026', '13/01/2026', '170,00'],
            ['60240', '1.1 - Taxa de condomínio', '02/2026', '13/02/2026', '170,00'],
        ]);
        $linha = $this->escreverParcelas($aba, $linha + 1, [
            ['61372', '1.1 - Taxa de condomínio - 01/2026', '1/3', '07/2026', '01/07/2026', '-', '170,00', '-'],
            ['61372', '1.1 - Taxa de condomínio - 02/2026', '1/3', '07/2026', '01/07/2026', '-', '48,66', '-'],
            ['61372', '1.4 - Juros - 30,20 ao mês - 07/2026', '1/3', '07/2026', '01/07/2026', '-', '30,20', '-'],
            ['61372', '1.5 - Multas - 20,40 - 07/2026', '1/3', '07/2026', '01/07/2026', '-', '20,40', '-'],
            // A armadilha: sinal separado do número por espaço NÃO-QUEBRÁVEL, exatamente como na fonte.
            ['61372', '1.6 - Descontos - 3,04 - 07/2026', '1/3', '07/2026', '01/07/2026', '-', "-\u{00A0}3,04", '-'],
            ['61372', '1.15 - Honorário advocatício - 134,46 com reajuste - 07/2026', '1/3', '07/2026', '01/07/2026', '-', '134,46', '-'],
            ['61373', '1.1 - Taxa de condomínio - 02/2026', '2/3', '08/2026', '28/08/2026', '-', '121,34', '-'],
            ['61373', '1.1 - Taxa de condomínio - 03/2026', '2/3', '08/2026', '28/08/2026', '-', '170,00', '-'],
        ]);
        $this->escreverRodape($aba, $linha + 2);

        return $this->salvar($planilha);
    }

    private function escreverCabecalho(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $aba,
        int $numero,
        string $unidade,
        string $sacado,
        string $totalOriginais,
        string $valorFinal,
    ): void {
        $this->texto($aba, 'A1', 'L. G Soluções Contábeis Eireli');
        $this->texto($aba, 'A2', 'APLC - TOP LIFE 2');
        $this->texto($aba, 'A4', 'ACORDOS DETALHADOS');
        $this->texto($aba, 'A6', sprintf('Acordo de número %d', $numero));
        $this->texto($aba, 'A7', sprintf('Unidade: %s', $unidade));
        $this->texto($aba, 'B7', 'Data base: 18/06/2026');
        $this->texto($aba, 'A8', sprintf('Sacado: %s', $sacado));
        $this->texto($aba, 'B8', sprintf('Valor total das contas originais: %s', $totalOriginais));
        $this->texto($aba, 'A9', 'Criado em: 18/06/2026');
        $this->texto($aba, 'B9', sprintf('Valor final acordado: %s', $valorFinal));
        $this->texto($aba, 'A10', 'Situação: Em andamento');
    }

    /**
     * @param list<list<string>> $contas
     *
     * @return int última linha escrita
     */
    private function escreverContasOriginais(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $aba, int $inicio, array $contas): int
    {
        $this->texto($aba, 'A' . $inicio, 'Relação das contas originais');
        $cabecalho = ['Nosso Número', 'Classe de Conta', 'Competência', 'Vencimento', 'Valor original (R$)', 'Detalhamento'];
        foreach ($cabecalho as $c => $titulo) {
            $this->texto($aba, $this->coluna($c) . ($inicio + 1), $titulo);
        }

        $linha = $inicio + 2;
        foreach ($contas as $conta) {
            foreach ([...$conta, '-'] as $c => $valor) {
                $this->texto($aba, $this->coluna($c) . $linha, $valor);
            }
            $linha += 2; // a fonte real intercala uma linha vazia entre as contas
        }

        return $linha - 2;
    }

    /**
     * @param list<list<string>> $parcelas
     *
     * @return int última linha escrita
     */
    private function escreverParcelas(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $aba, int $inicio, array $parcelas): int
    {
        $this->texto($aba, 'A' . $inicio, 'Parcelas das contas geradas pelo acordo');
        $cabecalho = ['Nosso Número', 'Classe de Conta', 'Parcela', 'Competência', 'Vencimento', 'Liquidação', 'Valor acordado (R$)', 'Valor liquidado (R$)'];
        foreach ($cabecalho as $c => $titulo) {
            $this->texto($aba, $this->coluna($c) . ($inicio + 1), $titulo);
        }

        $linha = $inicio + 2;
        foreach ($parcelas as $parcela) {
            foreach ($parcela as $c => $valor) {
                $this->texto($aba, $this->coluna($c) . $linha, $valor);
            }
            ++$linha;
        }

        return $linha - 1;
    }

    private function escreverRodape(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $aba, int $inicio): void
    {
        $this->texto($aba, 'A' . $inicio, 'Filtros: Situação do acordo: Em andamento; Unidade/Cliente: Todos; Sacado: Todos');
        $this->texto($aba, 'A' . ($inicio + 2), 'L. G Soluções Contábeis Eireli - SSC Projeção, 9, Setor Central/Gama, Brasília, DF - +55 (61) 35756440');
        $this->texto($aba, 'A' . ($inicio + 3), 'Emissão: 29/07/2026 16:07');
    }

    private function texto(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $aba, string $celula, string $valor): void
    {
        // TYPE_STRING de propósito: na fonte real TODA célula é texto, inclusive as de dinheiro e data.
        $aba->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
    }

    private function coluna(int $indice): string
    {
        return chr(ord('A') + $indice);
    }

    private function salvar(Spreadsheet $planilha): string
    {
        $caminho = sprintf('%s/acordos_%s.xlsx', sys_get_temp_dir(), uniqid('', true));
        (new Xlsx($planilha))->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }
}
