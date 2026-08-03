<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Importacao\ReceitaImportavel;
use App\Cobranca\Service\Importacao\TopLifeReceitasAdapter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Leitura da fonte "Receitas detalhadas por unidade/cliente" (spec `cobranca-importar-receitas.md`).
 *
 * A planilha é sintética: as reais são gitignored por PII (nome e unidade de centenas de pessoas), e
 * cada cenário aqui reproduz uma regra MEDIDA nos arquivos de 03/08 — a numeração dos comentários
 * aponta para o número medido, não para uma suposição.
 */
#[CoversClass(TopLifeReceitasAdapter::class)]
final class TopLifeReceitasAdapterTest extends TestCase
{
    /** Layout medido: A Unidade · B Sacado · C NN · D Classe · E Competência · F Vencimento · G Recebimento · H Valor · I Valor recebido · J Acordo. */
    private const CABECALHO = ['Unidade', 'Sacado', 'NN', 'Classe de Conta', 'Competência', 'Vencimento', 'Recebimento', 'Valor (R$)', 'Valor recebido (R$)', 'Informações do acordo'];

    /** @var list<string> */
    private array $arquivosTemporarios = [];
    private TopLifeReceitasAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new TopLifeReceitasAdapter();
    }

    protected function tearDown(): void
    {
        foreach ($this->arquivosTemporarios as $caminho) {
            if (is_file($caminho)) {
                unlink($caminho);
            }
        }
        $this->arquivosTemporarios = [];
    }

    #[Test]
    #[TestDox('🔑 Linha com Recebimento "-" é EM ABERTO: não entra como receita')]
    public function descartaLinhaEmAbertoEContaSeparado(): void
    {
        // A regra que separa receita de dívida. Medido em 03/08: 2.094 linhas assim, somando
        // R$ 2.045.780 na coluna nominal. O export de 01/08 não trazia nenhuma e o handoff registrou
        // isso como propriedade da fonte — não é. Sem este descarte, entram dois milhões que ninguém
        // recebeu.
        $resultado = $this->adapter->ler($this->planilha([
            ['CHACARA 01', 'Fulano', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 02', 'Beltrano', '9002', '1.1 - Taxa de condomínio', '06/2026', '10/06/2026', '-', '250,00', '0,00', '-'],
        ]));

        self::assertCount(1, $resultado->receitas, 'só o boleto efetivamente recebido vira receita');
        self::assertSame('9001', $resultado->receitas[0]->nn);
        self::assertSame(1, $resultado->emAberto, 'o em aberto é contado à parte, não some em silêncio');
        self::assertSame([], $resultado->rejeitadas, 'em aberto não é rejeição: é boleto que ainda não venceu o seu destino');
    }

    #[Test]
    #[TestDox('🔑 O dinheiro é a coluna I (Valor recebido), não a H')]
    public function usaAColunaValorRecebidoNaoAValorNominal(): void
    {
        // Medido: H e I divergem só em 1.4, 1.5 e 1.6. Nos juros o recebido é MAIOR (acumulou até o
        // pagamento). Se o adapter lesse H, o juro entraria por 10,00 em vez dos 18,00 que entraram.
        $receita = $this->umaReceita([
            ['CHACARA 01', 'Fulano', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 01', 'Fulano', '9001', '1.4 - Juros', '05/2026', '10/05/2026', '15/05/2026', '10,00', '18,00', '-'],
        ]);

        self::assertSame(10000, $receita->valorDividaCentavos);
        self::assertSame(1800, $receita->valorEncargosCentavos(), 'o juro que ENTROU foi 18,00, não os 10,00 nominais');
    }

    #[Test]
    #[TestDox('Cada classe de conta cai no seu balde do Pagamento')]
    public function distribuiAsClassesNosTresBaldes(): void
    {
        $receita = $this->umaReceita([
            ['CHACARA 01', 'Fulano', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 01', 'Fulano', '9001', '1.14 - Energia', '05/2026', '10/05/2026', '15/05/2026', '20,00', '20,00', '-'],
            ['CHACARA 01', 'Fulano', '9001', '1.4 - Juros', '05/2026', '10/05/2026', '15/05/2026', '5,00', '5,00', '-'],
            ['CHACARA 01', 'Fulano', '9001', '1.5 - Multas', '05/2026', '10/05/2026', '15/05/2026', '2,00', '2,00', '-'],
            ['CHACARA 01', 'Fulano', '9001', '1.15 - Honorário advocatício', '05/2026', '10/05/2026', '15/05/2026', '24,00', '24,00', '-'],
        ]);

        self::assertSame(12000, $receita->valorDividaCentavos, 'taxa + energia');
        self::assertSame(700, $receita->valorEncargosCentavos(), 'juros + multa');
        self::assertSame(500, $receita->valorJurosCentavos, 'juros e multa chegam separados: a obrigacao criada nasce liquidada e liquidar() os recebe um a um');
        self::assertSame(200, $receita->valorMultaCentavos);
        self::assertSame(2400, $receita->valorHonorariosCentavos);
        self::assertSame(15100, $receita->totalRecebidoCentavos(), 'bruto pago pelo devedor');
        self::assertSame(12700, $receita->recuperadoDividaCentavos(), 'o que abate o saldo do credor — sem honorários');
    }

    #[Test]
    #[TestDox('Desconto (1.6) vem negativo e ABATE o principal, sem tratamento próprio')]
    public function descontoAbateOPrincipal(): void
    {
        // Medido: 1.011 e 759 linhas de desconto, TODAS negativas; somando a coluna I por NN o líquido
        // nunca fica negativo nem zero. O abatimento já está embutido na decomposição da contábil.
        $receita = $this->umaReceita([
            ['CHACARA 01', 'Fulano', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 01', 'Fulano', '9001', '1.6 - Descontos', '05/2026', '10/05/2026', '15/05/2026', '-10,00', '-15,00', '-'],
        ]);

        self::assertSame(8500, $receita->valorDividaCentavos, 'o desconto de 15,00 (o CONCEDIDO, col. I) abate os 100,00');
        self::assertSame(8500, $receita->totalRecebidoCentavos());
    }

    #[Test]
    #[TestDox('Agrega por NN: as linhas de classe do mesmo boleto viram UM recebimento')]
    public function agregaPorNn(): void
    {
        $resultado = $this->adapter->ler($this->planilha([
            ['CHACARA 01', 'Fulano', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 01', 'Fulano', '9001', '1.4 - Juros', '05/2026', '10/05/2026', '15/05/2026', '5,00', '5,00', '-'],
            ['CHACARA 02', 'Beltrano', '9002', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '16/05/2026', '80,00', '80,00', '-'],
        ]));

        self::assertCount(2, $resultado->receitas, 'dois NNs, dois pagamentos — não três linhas soltas');
    }

    #[Test]
    #[TestDox('O mesmo NN em unidades diferentes NÃO funde dinheiro de unidades')]
    public function naoFundeMesmoNnDeUnidadesDiferentes(): void
    {
        $resultado = $this->adapter->ler($this->planilha([
            ['CHACARA 01', 'Fulano', '7777', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 02', 'Beltrano', '7777', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '80,00', '80,00', '-'],
        ]));

        self::assertCount(2, $resultado->receitas, 'o NN não é globalmente único: a chave inclui a unidade');
        self::assertSame(10000, $resultado->receitas[0]->valorDividaCentavos);
        self::assertSame(8000, $resultado->receitas[1]->valorDividaCentavos);
    }

    #[Test]
    #[TestDox('Parcela de acordo é reconhecida na coluna J, e texto livre não vira acordo')]
    public function reconheceAcordoEstruturado(): void
    {
        $resultado = $this->adapter->ler($this->planilha([
            ['CHACARA 01', 'Fulano', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', 'Acordo 377 - Parc. 1/40'],
            ['CHACARA 02', 'Beltrano', '9002', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '80,00', '80,00', 'Negociado em balcão'],
        ]));

        $porNn = [];
        foreach ($resultado->receitas as $r) {
            $porNn[$r->nn] = $r;
        }

        self::assertNotNull($porNn['9001']->acordo);
        self::assertSame(377, $porNn['9001']->acordo->numero);
        self::assertSame(1, $porNn['9001']->acordo->parcelaIndice);
        self::assertSame(40, $porNn['9001']->acordo->parcelaTotal);
        self::assertNull($porNn['9002']->acordo, 'texto livre não é acordo estruturado');
    }

    #[Test]
    #[TestDox('Rodapé e cabeçalho de relatório são ignorados, não rejeitados')]
    public function ignoraRodape(): void
    {
        // Medido: 11 e 8 linhas com NN mas sem classe — são os totais do relatório.
        $resultado = $this->adapter->ler($this->planilha([
            ['CHACARA 01', 'Fulano', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['', '', '', '', '', '', '', '', '', ''],
            ['Total geral', '', '9999', '', '', '', '', '1.000,00', '1.000,00', ''],
        ]));

        self::assertCount(1, $resultado->receitas);
        self::assertSame(2, $resultado->linhasIgnoradas);
        self::assertSame([], $resultado->rejeitadas, 'rodapé não é erro do dado — não polui o relatório de rejeições');
    }

    #[Test]
    #[TestDox('Recusa com motivo: sem sacado, competência inválida, data inválida, valor não numérico')]
    public function recusaComMotivo(): void
    {
        $resultado = $this->adapter->ler($this->planilha([
            ['CHACARA 01', '', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 02', 'Beltrano', '9002', '1.1 - Taxa de condomínio', 'maio', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 03', 'Cicrano', '9003', '1.1 - Taxa de condomínio', '05/2026', 'sem data', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 04', 'Deltrano', '9004', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', 'abc', '-'],
        ]));

        self::assertSame([], $resultado->receitas);
        self::assertCount(4, $resultado->rejeitadas);
        $motivos = array_map(static fn ($r) => $r->motivo, $resultado->rejeitadas);
        self::assertStringContainsString('Sacado', $motivos[0]);
        self::assertStringContainsString('Competência', $motivos[1]);
        self::assertStringContainsString('vencimento', $motivos[2]);
        self::assertStringContainsString('não numérico', $motivos[3]);
    }

    #[Test]
    #[TestDox('Unidade com metadado entre parênteses é separada')]
    public function separaMetadadoDaUnidade(): void
    {
        $receita = $this->umaReceita([
            ['QUADRA 11 CHACARA 02/11 (05-03,06-01)', 'Fulano', '9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
        ]);

        self::assertSame('QUADRA 11 CHACARA 02/11', $receita->objetoIdentificacao);
        self::assertSame('05-03,06-01', $receita->unidadeMetadata);
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<list<string|int|float>> $linhas */
    private function umaReceita(array $linhas): ReceitaImportavel
    {
        $resultado = $this->adapter->ler($this->planilha($linhas));
        self::assertCount(1, $resultado->receitas, 'cenário deveria produzir exatamente um recebimento');

        return $resultado->receitas[0];
    }

    /**
     * Gera em disco uma planilha no layout da fonte. O cabeçalho vai na linha 7 (como no arquivo real),
     * com seis linhas institucionais antes — assim o teste exercita também a localização do início dos
     * dados, e não só o parsing.
     *
     * @param list<list<string|int|float>> $linhas
     */
    private function planilha(array $linhas): string
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->fromArray([
            ['L. G Soluções Contábeis Eireli'],
            ['TOP LIFE'],
            ['Receitas detalhadas por unidade/cliente'],
            [''],
            [''],
            [''],
        ], null, 'A1');
        $aba->fromArray([self::CABECALHO], null, 'A7');
        $aba->fromArray($linhas, null, 'A8');

        $caminho = sys_get_temp_dir() . '/receitas_teste_' . uniqid('', true) . '.xlsx';
        $this->arquivosTemporarios[] = $caminho; // registrado ANTES de gravar: falha no meio também limpa
        (new Xlsx($planilha))->save($caminho);
        $planilha->disconnectWorksheets();

        return $caminho;
    }
}
