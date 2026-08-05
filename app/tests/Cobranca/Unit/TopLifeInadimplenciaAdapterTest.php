<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Importacao\AcordoDoRelatorio;
use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\ReferenciaSubstituta;
use App\Cobranca\Service\Importacao\TopLifeInadimplenciaAdapter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TopLifeInadimplenciaAdapter::class)]
final class TopLifeInadimplenciaAdapterTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Cobranca/importacao/toplife_amostra.xlsx';

    /** Cabeçalho A–O do relatório (a col. A com "Unidade" é o que marca o início dos dados). */
    private const CABECALHO = [
        'Unidade', 'Sacado', 'NN', 'Classe de conta', 'Competência', 'Vencimento', 'Atraso',
        'Valor (R$)', 'Juros (R$)', 'Multa (R$)', 'Correção (R$)', 'Honorários (R$)', 'Total (R$)',
        'Informações do acordo', 'Recebimento',
    ];

    private TopLifeInadimplenciaAdapter $adapter;

    /** @var list<string> planilhas sintéticas geradas em disco, apagadas no tearDown */
    private array $arquivosTemporarios = [];

    protected function setUp(): void
    {
        $this->adapter = new TopLifeInadimplenciaAdapter();
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

    /**
     * @return array<string, BoletoImportavel>
     */
    private function porNn(): array
    {
        $r = $this->adapter->ler(self::FIXTURE);
        $mapa = [];
        foreach ($r->importaveis as $b) {
            $mapa[$b->nn] = $b;
        }

        return $mapa;
    }

    /**
     * Gera em disco uma planilha mínima no layout da fonte (cabeçalho + as linhas dadas) e devolve o
     * caminho. Existe porque a fixture real, anonimizada de dados verdadeiros, tem a coluna K sempre
     * zerada — ela não consegue provar cenários que a operação TOPLIFE não usa.
     *
     * @param list<list<string|int|float>> $linhas
     */
    private function planilha(array $linhas): string
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->fromArray([self::CABECALHO], null, 'A1');
        $aba->fromArray($linhas, null, 'A2');

        $caminho = sys_get_temp_dir() . '/toplife_teste_' . uniqid('', true) . '.xlsx';
        $this->arquivosTemporarios[] = $caminho; // registrado ANTES de gravar: falha no meio também limpa
        (new Xlsx($planilha))->save($caminho);
        $planilha->disconnectWorksheets();

        return $caminho;
    }

    /**
     * @param list<list<string|int|float>> $linhas
     *
     * @return array<string, BoletoImportavel>
     */
    private function lerPlanilha(array $linhas): array
    {
        $mapa = [];
        foreach ($this->adapter->ler($this->planilha($linhas))->importaveis as $b) {
            $mapa[$b->nn] = $b;
        }

        return $mapa;
    }

    // ------------------------------------------------------------------------------------------
    // Dívida antiga SEM Nosso Número — spec `docs/specs/cobranca-divida-sem-numero-de-boleto.md`.
    // Medido em 04/08/2026: 73 linhas, R$ 10.694,66, em 2 unidades da TOP LIFE 1 (20-03C e 17-01/1-2).
    // Os valores abaixo são de uma dessas linhas reais (20-03C, competência 09/2021).
    // ------------------------------------------------------------------------------------------

    /** Duas linhas reais do mesmo mês: a taxa e a energia, que vinham no mesmo boleto. */
    private const LINHAS_SEM_NN = [
        ['20-03C', 'DEVEDOR SEM BOLETO', '', '1.1 - Taxa de condomínio', '09/2021', '10/09/2021', 1000, 100.00, 59.63, 2.00, 0, 32.33, 193.96, '-', '-'],
        ['20-03C', 'DEVEDOR SEM BOLETO', '', '1.14 - Energia', '09/2021', '10/09/2021', 1000, 45.00, 26.83, 0.90, 0, 14.55, 87.28, '-', '-'],
    ];

    #[Test]
    #[TestDox('Linha sem NN não é mais rejeitada: entra com a referência substituta SNN:<vencimento>')]
    public function linhaSemNnEntraComReferenciaSubstituta(): void
    {
        $r = $this->adapter->ler($this->planilha(self::LINHAS_SEM_NN));

        self::assertCount(1, $r->importaveis, 'taxa e energia do mesmo mês formam UM boleto');
        self::assertSame([], $r->rejeitadas, 'sem NN deixou de ser motivo de rejeição');
        self::assertSame('SNN:2021-09-10', $r->importaveis[0]->nn);
    }

    #[Test]
    #[TestDox('A referência substituta NUNCA é dígito puro — é isso que a impede de colidir com um NN real')]
    public function referenciaSubstitutaNuncaColideComNn(): void
    {
        $b = $this->adapter->ler($this->planilha(self::LINHAS_SEM_NN))->importaveis[0];

        self::assertSame(0, preg_match('/^\d+$/', $b->nn), "referência {$b->nn} passaria por um NN de verdade");
    }

    #[Test]
    #[TestDox('Taxa e energia do mesmo mês somam num boleto só, principal e encargos')]
    public function linhasDoMesmoMesSomamNumBoletoSo(): void
    {
        $b = $this->adapter->ler($this->planilha(self::LINHAS_SEM_NN))->importaveis[0];

        self::assertSame(14500, $b->principalCentavos, 'taxa 100,00 + energia 45,00');
        self::assertSame(8646, $b->jurosCentavos, 'juros 59,63 + 26,83');
        self::assertSame(290, $b->multaCentavos, 'multa 2,00 + 0,90');
        self::assertSame(4688, $b->honorariosInformadosCentavos, 'honorários 32,33 + 14,55');
        self::assertSame('09/2021', $b->competencia);
    }

    /**
     * A referência substituta NÃO é única no arquivo — duas unidades com o mesmo vencimento produzem a
     * mesma string. Quem separa é o `caso_id`, que compõe o índice único junto com a referência e a
     * competência. Se o agrupamento do adapter deixasse a unidade de fora, o dinheiro de dois devedores
     * cairia num boleto só.
     */
    #[Test]
    #[TestDox('Mesmo vencimento em duas unidades: dois boletos, nenhum dinheiro fundido')]
    public function unidadesDiferentesNaoSeFundem(): void
    {
        $r = $this->adapter->ler($this->planilha([
            ['20-03C', 'DEVEDOR A', '', '1.1 - Taxa de condomínio', '09/2021', '10/09/2021', 1000, 100.00, 0, 0, 0, 0, 100.00, '-', '-'],
            ['17-01', 'DEVEDOR B', '', '1.1 - Taxa de condomínio', '09/2021', '10/09/2021', 1000, 200.00, 0, 0, 0, 0, 200.00, '-', '-'],
        ]));

        self::assertCount(2, $r->importaveis, 'unidades distintas, boletos distintos');
        self::assertSame(['20-03C', '17-01'], array_map(static fn ($b): string => $b->objetoIdentificacao, $r->importaveis));
        self::assertSame([10000, 20000], array_map(static fn ($b): int => $b->principalCentavos, $r->importaveis));
    }

    /**
     * O vencimento continua sendo o discriminador entre dado e rodapé — e é ele que impede uma linha de
     * totais de virar dívida agora que o NN deixou de ser exigido. O assert está preso ao número de
     * boletos importáveis, não a texto de mensagem.
     */
    #[Test]
    #[TestDox('Linha de rodapé (sem vencimento) continua IGNORADA e não vira dívida sem NN')]
    public function rodapeNaoViraDividaSemNn(): void
    {
        $r = $this->adapter->ler($this->planilha([
            ...self::LINHAS_SEM_NN,
            ['Total de inadimplência', '', '', '', '0', '', '', 145.00, 0, 0, 0, 0, 145.00, '', ''],
            ['Filtros: Inadimplência até:04/08/2026', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ]));

        self::assertCount(1, $r->importaveis, 'só o boleto de verdade');
        self::assertSame([], $r->rejeitadas);
        self::assertSame(2, $r->linhasIgnoradas, 'as duas linhas de rodapé');
    }

    #[Test]
    public function classificaImportaveisRejeitadasEIgnoradas(): void
    {
        $r = $this->adapter->ler(self::FIXTURE);

        self::assertCount(6, $r->importaveis, 'esperados 6 boletos importáveis');
        self::assertCount(4, $r->rejeitadas, 'esperados 4 boletos rejeitados');
        self::assertSame(8, $r->linhasIgnoradas, 'esperadas 8 linhas ignoradas (rodapé/totais/metadados)');
    }

    #[Test]
    public function agregaBoletoDeUmaSoLinha(): void
    {
        $b = $this->porNn()['1001'];

        self::assertSame('01-01', $b->objetoIdentificacao);
        self::assertSame('DEVEDOR UM EXEMPLO', $b->sacadoNome);
        self::assertSame('02/2026', $b->competencia);
        self::assertSame('10/02/2026', $b->vencimento->format('d/m/Y'));
        self::assertSame(19000, $b->principalCentavos);
        self::assertSame(1317, $b->encargosCentavos(), 'juros 9,37 + multa 3,80');
        self::assertSame(4063, $b->honorariosInformadosCentavos);
        self::assertNull($b->unidadeMetadata);
        self::assertNull($b->acordoTexto);
        self::assertNull($b->acordo, 'coluna "Informações do acordo" vazia — obrigação comum');
    }

    #[Test]
    public function agregaComponentesDoMesmoNnNumUnicoBoleto(): void
    {
        // NN=1002: Taxa (100) + Energia (45) = principal 145; encargos (5+2)+(2+0,90); honorários 21+10.
        $b = $this->porNn()['1002'];

        self::assertSame(14500, $b->principalCentavos);
        self::assertSame(990, $b->encargosCentavos());
        self::assertSame(3100, $b->honorariosInformadosCentavos);
        self::assertCount(2, $b->linhas, 'detalhamento das 2 linhas-componente preservado');
    }

    #[Test]
    public function separaUnidadePrincipalEGuardaParentesesEAcordoComoObservacao(): void
    {
        $b = $this->porNn()['1003'];

        self::assertSame('01-03A', $b->objetoIdentificacao);
        self::assertSame('05-03,06-01', $b->unidadeMetadata);
        self::assertSame('Acordo 396 - Parc. 2/11', $b->acordoTexto);
        self::assertStringContainsString('Unidades associadas: 05-03,06-01', (string) $b->observacao());
        self::assertStringContainsString('Acordo 396', (string) $b->observacao());

        // O mesmo texto também é RECONHECIDO como acordo estruturado (spec §3.1) — a fixture real
        // prova o reconhecimento sem depender só de planilhas sintéticas.
        self::assertInstanceOf(AcordoDoRelatorio::class, $b->acordo);
        self::assertSame(396, $b->acordo->numero);
        self::assertSame(2, $b->acordo->parcelaIndice);
        self::assertSame(11, $b->acordo->parcelaTotal);
    }

    #[Test]
    public function encargosSomamLinhasJurosMultaEHonorarioClasse115NaoEntraNoPrincipal(): void
    {
        // NN=1004: Taxa 170 (principal); linhas 1.4 Juros 12,50 + 1.5 Multas 3,40 → encargos; 1.15 → honorário.
        $b = $this->porNn()['1004'];

        self::assertSame(17000, $b->principalCentavos, 'só a Taxa entra no principal');
        self::assertSame(1409, $b->jurosCentavos, 'coluna I 1,59 + linha 1.4 de 12,50');
        self::assertSame(680, $b->multaCentavos, 'coluna J 3,40 + linha 1.5 de 3,40');
        self::assertSame(0, $b->correcaoCentavos, 'coluna K zerada nesta fonte');
        // INV-E1: a separação REDISTRIBUI as mesmas parcelas — o agregado é idêntico ao de antes
        // ((1,59+3,40) + (12,50+3,40) = 20,89), então nenhum saldo existente muda.
        self::assertSame(2089, $b->encargosCentavos(), '(1,59+3,40) + (12,50+3,40)');
        self::assertSame(
            $b->encargosCentavos(),
            $b->jurosCentavos + $b->multaCentavos + $b->correcaoCentavos,
            'INV-E1: o agregado é exatamente a soma dos três',
        );
        self::assertSame(5000, $b->honorariosInformadosCentavos, 'linha 1.15 (50,00), sem dupla contagem com coluna L=0');
    }

    #[Test]
    public function classeUmQuatroVaiParaJurosEClasseUmCincoVaiParaMulta(): void
    {
        // O que se perdia antes: 1.4 (Juros) e 1.5 (Multas) caíam no MESMO balde agregado. Aqui cada
        // boleto tem UMA classe especial isolada, com colunas I/J zeradas — se os baldes fossem
        // trocados ou fundidos, um dos dois blocos abaixo quebraria.
        $mapa = $this->lerPlanilha([
            ['07-01', 'DEVEDOR JUROS', '9001', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 100.00, 0, 0, 0, 0, 100.00, '-', '-'],
            ['07-01', 'DEVEDOR JUROS', '9001', '1.4 - Juros', '02/2026', '10/02/2026', 148, 12.50, 0, 0, 0, 0, 12.50, '-', '-'],
            ['07-02', 'DEVEDOR MULTA', '9002', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 100.00, 0, 0, 0, 0, 100.00, '-', '-'],
            ['07-02', 'DEVEDOR MULTA', '9002', '1.5 - Multas', '02/2026', '10/02/2026', 148, 3.40, 0, 0, 0, 0, 3.40, '-', '-'],
        ]);

        self::assertSame(1250, $mapa['9001']->jurosCentavos, 'a linha 1.4 (12,50) entra em JUROS');
        self::assertSame(0, $mapa['9001']->multaCentavos, 'e não em multa');
        self::assertSame(10000, $mapa['9001']->principalCentavos, 'classe especial não vira principal');

        self::assertSame(340, $mapa['9002']->multaCentavos, 'a linha 1.5 (3,40) entra em MULTA');
        self::assertSame(0, $mapa['9002']->jurosCentavos, 'e não em juros');

        // Boleto SEM linhas de classe especial: juros/multa vêm só das colunas I/J.
        $semClasseEspecial = $this->porNn()['1001'];
        self::assertSame(937, $semClasseEspecial->jurosCentavos, 'coluna I 9,37');
        self::assertSame(380, $semClasseEspecial->multaCentavos, 'coluna J 3,80');
        self::assertSame(0, $semClasseEspecial->correcaoCentavos);
    }

    #[Test]
    public function leACorrecaoDaColunaK(): void
    {
        // A fixture real tem K=0 em 100% das linhas (a operação TOPLIFE não usa correção), então ela
        // NÃO prova a leitura da coluna. Esta planilha sintética prova: K ≠ 0, somada por linha, sem
        // contaminar juros/multa/principal.
        $mapa = $this->lerPlanilha([
            ['08-01', 'DEVEDOR CORRECAO', '9101', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 200.00, 10.00, 4.00, 7.35, 45.00, 266.35, '-', '-'],
            ['08-01', 'DEVEDOR CORRECAO', '9101', '1.4 - Juros', '02/2026', '10/02/2026', 148, 5.00, 0, 0, 1.15, 0, 6.15, '-', '-'],
            ['08-01', 'DEVEDOR CORRECAO', '9101', '1.5 - Multas', '02/2026', '10/02/2026', 148, 3.00, 0, 0, 0, 0, 3.00, '-', '-'],
        ]);
        $b = $mapa['9101'];

        self::assertSame(20000, $b->principalCentavos, 'só a Taxa (200,00)');
        self::assertSame(1500, $b->jurosCentavos, 'coluna I 10,00 + linha 1.4 de 5,00');
        self::assertSame(700, $b->multaCentavos, 'coluna J 4,00 + linha 1.5 de 3,00');
        self::assertSame(850, $b->correcaoCentavos, 'coluna K 7,35 + 1,15 — antes DESCARTADA');
        self::assertSame(3050, $b->encargosCentavos(), 'agregado = 15,00 + 7,00 + 8,50');
        self::assertSame(4500, $b->honorariosInformadosCentavos, 'coluna L da linha de taxa');
        self::assertSame(735, $b->linhas[0]['correcao'], 'detalhamento por linha preserva a correção');
    }

    #[Test]
    public function correcaoNaoNumericaRejeitaOBoletoComoAsDemaisColunas(): void
    {
        $resultado = $this->adapter->ler($this->planilha([
            ['09-01', 'DEVEDOR K INVALIDO', '9201', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 200.00, 0, 0, 'abc', 0, 200.00, '-', '-'],
        ]));

        self::assertCount(0, $resultado->importaveis);
        self::assertCount(1, $resultado->rejeitadas);
        self::assertStringContainsString('não numérico', $resultado->rejeitadas[0]->motivo);
    }

    #[Test]
    public function agregaJurosEMultaDeLinhasDiferentesDoMesmoBoleto(): void
    {
        // NN=1002: duas linhas (Taxa e Energia). Juros = 5,00+2,00; Multa = 2,00+0,90.
        $b = $this->porNn()['1002'];

        self::assertSame(700, $b->jurosCentavos);
        self::assertSame(290, $b->multaCentavos);
        self::assertSame(0, $b->correcaoCentavos);
        self::assertSame(990, $b->encargosCentavos(), 'INV-E1: mesmo agregado de antes da separação');
    }

    #[Test]
    public function detalhamentoDaLinhaExpoeACorrecao(): void
    {
        $b = $this->porNn()['1001'];

        self::assertArrayHasKey('correcao', $b->linhas[0], 'a coluna K passa a constar no detalhamento');
        self::assertSame(0, $b->linhas[0]['correcao']);
    }

    #[Test]
    public function descontoReduzOPrincipal(): void
    {
        // NN=1005: Taxa 170 + Desconto -10 → principal 160.
        $b = $this->porNn()['1005'];

        self::assertSame(16000, $b->principalCentavos);
        self::assertSame(500, $b->encargosCentavos());
        self::assertSame(2751, $b->honorariosInformadosCentavos);
    }

    #[Test]
    public function rejeitaComMotivoClaro(): void
    {
        $r = $this->adapter->ler(self::FIXTURE);
        $motivos = [];
        foreach ($r->rejeitadas as $rej) {
            $motivos[$rej->referencia] = $rej->motivo;
        }

        self::assertArrayHasKey('1006', $motivos);
        self::assertStringContainsString('Sacado', $motivos['1006']);
        self::assertArrayHasKey('1007', $motivos);
        self::assertStringContainsString('Competência', $motivos['1007']);
        self::assertArrayHasKey('1008', $motivos);
        self::assertStringContainsString('não numérico', $motivos['1008']);
        self::assertArrayHasKey('1010', $motivos);
        self::assertStringContainsString('sem principal', $motivos['1010']);
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    // Reconhecimento do acordo na coluna N (tarefa #7-A, spec cobranca-importar-linhas-acordo.md)
    // ────────────────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function reconheceAcordoDoRelatorioComParcelaUnica(): void
    {
        $mapa = $this->lerPlanilha([
            ['10-01', 'DEVEDOR ACORDO 28', '9301', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 100.00, 0, 0, 0, 0, 100.00, 'Acordo 28 - Parc. 1/1', '-'],
        ]);
        $b = $mapa['9301'];

        self::assertInstanceOf(AcordoDoRelatorio::class, $b->acordo);
        self::assertSame(28, $b->acordo->numero);
        self::assertSame(1, $b->acordo->parcelaIndice);
        self::assertSame(1, $b->acordo->parcelaTotal);
    }

    #[Test]
    public function reconheceAcordoDoRelatorioMultiParcela(): void
    {
        $mapa = $this->lerPlanilha([
            ['10-02', 'DEVEDOR ACORDO 31', '9302', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 100.00, 0, 0, 0, 0, 100.00, 'Acordo 31 - Parc. 1/3', '-'],
        ]);
        $b = $mapa['9302'];

        self::assertInstanceOf(AcordoDoRelatorio::class, $b->acordo);
        self::assertSame(31, $b->acordo->numero);
        self::assertSame(1, $b->acordo->parcelaIndice);
        self::assertSame(3, $b->acordo->parcelaTotal);
    }

    #[Test]
    public function colunaDeAcordoVaziaNaoReconheceNada(): void
    {
        // NN=1001 na fixture real: coluna "Informações do acordo" vazia ("-").
        $b = $this->porNn()['1001'];

        self::assertNull($b->acordo);
    }

    #[Test]
    public function textoDeAcordoQueNaoCasaARegexNaoReconheceMasContinuaNaObservacao(): void
    {
        $mapa = $this->lerPlanilha([
            ['10-03', 'DEVEDOR TEXTO LIVRE', '9303', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 100.00, 0, 0, 0, 0, 100.00, 'Negociação em análise', '-'],
        ]);
        $b = $mapa['9303'];

        self::assertNull($b->acordo, 'texto livre não casa a regex do acordo');
        self::assertSame('Negociação em análise', $b->acordoTexto, 'o texto cru continua indo para a observação');
        self::assertStringContainsString('Negociação em análise', (string) $b->observacao());
    }

    #[Test]
    public function reconhecimentoDeAcordoToleraEspacosEECaseInsensitive(): void
    {
        $mapa = $this->lerPlanilha([
            ['10-05', 'DEVEDOR ACORDO CASE', '9305', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 100.00, 0, 0, 0, 0, 100.00, 'acordo  12 -  Parc.  3/5', '-'],
        ]);
        $b = $mapa['9305'];

        self::assertInstanceOf(AcordoDoRelatorio::class, $b->acordo);
        self::assertSame(12, $b->acordo->numero);
        self::assertSame(3, $b->acordo->parcelaIndice);
        self::assertSame(5, $b->acordo->parcelaTotal);
    }

    #[Test]
    public function somaColunaValorCentavosSomaTodasAsLinhasDoNnIndependenteDaClasse(): void
    {
        // NN=9304: Taxa 100,00 (classe 1.1, entra no principal) + Juros 12,50 (classe 1.4, NÃO
        // entra no principal). somaColunaValorCentavos abrange as DUAS linhas — diferente de
        // principalCentavos, que só soma classes específicas (spec §3.2).
        $mapa = $this->lerPlanilha([
            ['10-04', 'DEVEDOR SOMA VALOR', '9304', '1.1 - Taxa de condomínio', '02/2026', '10/02/2026', 148, 100.00, 0, 0, 0, 0, 100.00, 'Acordo 40 - Parc. 1/1', '-'],
            ['10-04', 'DEVEDOR SOMA VALOR', '9304', '1.4 - Juros', '02/2026', '10/02/2026', 148, 12.50, 0, 0, 0, 0, 12.50, 'Acordo 40 - Parc. 1/1', '-'],
        ]);
        $b = $mapa['9304'];

        self::assertSame(10000, $b->principalCentavos, 'só a classe 1.1 entra no principal');
        self::assertSame(11250, $b->somaColunaValorCentavos, 'soma da coluna Valor das DUAS linhas (100,00 + 12,50)');
    }

    #[Test]
    public function somaColunaValorCentavosDeBoletoSemAcordoTambemSomaTodasAsLinhas(): void
    {
        // Regressão: a soma da coluna Valor não depende de haver acordo — NN=1002 (sem acordo)
        // tem 2 linhas (Taxa 100 + Energia 45), soma 145,00.
        $b = $this->porNn()['1002'];

        self::assertNull($b->acordo);
        self::assertSame(14500, $b->somaColunaValorCentavos);
    }
}
