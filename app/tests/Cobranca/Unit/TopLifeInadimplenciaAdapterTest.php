<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\TopLifeInadimplenciaAdapter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
}
