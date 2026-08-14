<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\RelatorioImportado;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * As duas RECUSAS do comando de carga (SPEC espelho §4.4, §9 teste 6).
 *
 * Elas existem porque atribuir a carteira errada, ou aceitar um relatório emitido com recorte
 * parcial, envenena o espelho **em silêncio** — e o espelho é a fonte da Fase 1. Sem teste de
 * comando, as duas travas dependiam de prova manual e a próxima refatoração as derrubaria sem
 * ninguém notar.
 */
final class CarregarEspelhoRelatorioCommandTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    /** @var list<string> */
    private array $diretoriosTemporarios = [];

    private EntityManagerInterface $em;
    private CommandTester $comando;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $aplicacao = new Application(self::$kernel);
        $this->comando = new CommandTester($aplicacao->find('app:cobranca:espelho:carregar'));
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();

        foreach ($this->diretoriosTemporarios as $diretorio) {
            foreach (glob($diretorio . '/*') ?: [] as $arquivo) {
                unlink($arquivo);
            }

            if (is_dir($diretorio)) {
                rmdir($diretorio);
            }
        }

        $this->diretoriosTemporarios = [];
        parent::tearDown();
    }

    #[TestDox('§9.6 — --carteira-id de outro escritório é RECUSADO, não adivinhado')]
    public function testCarteiraDeOutroEscritorioEhRecusada(): void
    {
        $daCasa = $this->carteira();
        $deOutro = $this->carteira();

        $arquivo = $this->montarPlanilha([$this->linhaDeDado()]);
        $lotesAntes = $this->contarLotes();

        $saida = $this->comando->execute([
            '--tenant-id' => (string) $daCasa->getTenant()?->getId(),
            '--carteira-id' => (string) $deOutro->getId(),
            '--arquivo' => $arquivo,
        ]);

        self::assertSame(Command::FAILURE, $saida);
        self::assertStringContainsString('não existe neste escritório', $this->comando->getDisplay());
        self::assertSame($lotesAntes, $this->contarLotes(), 'nada foi lido');
    }

    #[TestDox('§9.6 — arquivo com recorte parcial é RECUSADO e nada entra no espelho')]
    public function testRecorteParcialEhRecusado(): void
    {
        // Um relatório emitido para UMA unidade passa na reconciliação interna — ela fecha contra o
        // totalizador do próprio arquivo filtrado — e entraria como "a verdade absoluta", produzindo
        // falta em massa na conferência.
        $carteira = $this->carteira();
        $arquivo = $this->planilhaComRecorteParcial();
        $lotesAntes = $this->contarLotes();

        $saida = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--carteira-id' => (string) $carteira->getId(),
            '--arquivo' => $arquivo,
        ]);

        self::assertSame(Command::FAILURE, $saida);
        self::assertStringContainsString('RECUSADO', $this->comando->getDisplay());
        self::assertSame($lotesAntes, $this->contarLotes(), 'nada entrou no espelho');
    }

    #[TestDox('Arquivo íntegro entra, e reexecutar não duplica')]
    public function testArquivoIntegroEntraEEhReexecutavel(): void
    {
        $carteira = $this->carteira();
        $arquivo = $this->montarPlanilha([$this->linhaDeDado(), $this->linhaDeDado(nn: '74609')]);

        $entrada = [
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--carteira-id' => (string) $carteira->getId(),
            '--arquivo' => $arquivo,
        ];

        self::assertSame(Command::SUCCESS, $this->comando->execute($entrada));
        self::assertStringContainsString('gravado', $this->comando->getDisplay());
        $lotes = $this->contarLotes();

        self::assertSame(Command::SUCCESS, $this->comando->execute($entrada));
        self::assertStringContainsString('já estava', $this->comando->getDisplay());
        self::assertSame($lotes, $this->contarLotes(), 'a segunda execução não duplicou');
    }

    /**
     * Uma planilha válida em tudo, EXCETO o recorte: o rodapé declara filtro por unidade.
     */
    private function planilhaComRecorteParcial(): string
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();

        $aba->fromArray([
            ['L. G Soluções Contábeis Eireli'],
            ['INADIMPLÊNCIA DETALHADA'],
            ['Número de unidades: 1'],
            ['Juros: 1,00% ao mês; Multa: 2,00%; Honorários: 20,00%.'],
            [null],
        ], null, 'A1', true);

        $aba->fromArray([self::CABECALHO_ESPELHO], null, 'A6', true);
        $aba->fromArray([$this->linhaDeDado()], null, 'A7', true);

        $aba->fromArray([[
            'Total inadimplência das unidades', null, null, null, null, null, null,
            190.00, 11.59, 3.80, 0, 41.08, 246.47,
        ]], null, 'A8', true);

        $aba->fromArray([
            ['Classe de conta', 'Valor (R$)', 'Juros (R$)', 'Multa (R$)', 'Correção (R$)', 'Honorários (R$)', 'Total (R$)'],
            ['Total de inadimplência', 190.00, 11.59, 3.80, 0, 41.08, 246.47],
        ], null, 'A10', true);

        // Aqui está o defeito: "Unidade: 01-01" em vez de "Unidade: Todas".
        $aba->fromArray([
            ['Filtros:  Inadimplência até:12/08/2026; Competência: Todas; Período de vencimento: Todos; Unidade: 01-01; Sacado: Todos'],
            [null],
            ['L. G Soluções Contábeis Eireli - Brasília, DF'],
            ['Emissão: 12/08/2026 09:42'],
        ], null, 'A13', true);

        $caminho = sys_get_temp_dir() . '/Inadimplencias_detalhadas_recorte_' . uniqid('', true) . '.xlsx';
        (new Xlsx($planilha))->save($caminho);
        $planilha->disconnectWorksheets();

        $this->planilhasTemporarias[] = $caminho;

        return $caminho;
    }


    /**
     * 🔴 **INV-Q6 no lugar onde o defeito morava** — achado 3 da revisão.
     *
     * A causa-raiz desta frente inteira era `reunirArquivos()` filtrando por `stripos($nome,
     * 'nadimpl')` e **descartando calado** todo o resto: três relatórios ficaram semanas fora do
     * espelho enquanto os comandos imprimiam números com cara de totais.
     *
     * A primeira versão do teste exercitava só o `ClassificadorDeRelatorio` — a classe, não a
     * decisão. E os testes de `--arquivo` tinham ganhado `--tipo=inadimplencia`, que **curto-circuita
     * `classificar()`**: trocar o `return null` por `continue` não deixava nada vermelho.
     *
     * Este teste usa `--diretorio`, sem `--tipo`, que é o caminho de produção.
     *
     * **Reintrodução provada:** trocando o `return null` de `reunirArquivos()` por `continue` (o
     * descarte silencioso original), o comando volta a sair `SUCCESS` gravando só o arquivo bom, e
     * este teste fica vermelho nas três asserções.
     */
    #[TestDox('🔴 INV-Q6 — .xlsx não reconhecido no diretório DERRUBA a carga; não some')]
    public function testArquivoNaoReconhecidoNoDiretorioDerrubaACarga(): void
    {
        $carteira = $this->carteira();
        $diretorio = $this->diretorioComLote();
        $lotesAntes = $this->contarLotes();

        $saida = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--carteira-id' => (string) $carteira->getId(),
            '--diretorio' => $diretorio,
        ]);

        self::assertSame(Command::FAILURE, $saida, 'planilha não reconhecida não pode ser pulada em silêncio');

        $tela = $this->comando->getDisplay();

        self::assertStringContainsString('nao_sei_o_que_e.xlsx', $tela, 'o arquivo recusado é NOMEADO na tela');
        self::assertSame(
            $lotesAntes,
            $this->contarLotes(),
            'nada foi gravado — nem o arquivo bom: lote pela metade é espelho incompleto com cara de completo',
        );
    }

    #[TestDox('Diretório só com relatórios reconhecidos entra pela classificação, sem --tipo')]
    public function testDiretorioSoComReconhecidosEntra(): void
    {
        $carteira = $this->carteira();
        $diretorio = $this->diretorioComLote(comIntruso: false);

        $saida = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--carteira-id' => (string) $carteira->getId(),
            '--diretorio' => $diretorio,
        ]);

        self::assertSame(Command::SUCCESS, $saida);
        self::assertStringContainsString('(inadimplencia)', $this->comando->getDisplay(), 'o tipo saiu da classificação');
    }

    /**
     * 🔴 Achado 7 — `--tipo` é override de UM arquivo, não interruptor do INV-Q6 para um lote.
     *
     * Aceito no `--diretorio`, ele mandaria todos os arquivos da pasta para o mesmo leitor e
     * desligaria a classificação do lote inteiro.
     */
    #[TestDox('🔴 --tipo com --diretorio é RECUSADO')]
    public function testTipoForcadoNaoValeParaDiretorio(): void
    {
        $carteira = $this->carteira();

        $saida = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--carteira-id' => (string) $carteira->getId(),
            '--diretorio' => $this->diretorioComLote(comIntruso: false),
            '--tipo' => 'inadimplencia',
        ]);

        self::assertSame(Command::FAILURE, $saida);
        self::assertStringContainsString('--tipo', $this->comando->getDisplay());
    }

    /** Um diretório com um relatório legítimo e, opcionalmente, um .xlsx que não é nenhum dos quatro. */
    private function diretorioComLote(bool $comIntruso = true): string
    {
        $diretorio = sys_get_temp_dir() . '/espelho_lote_' . uniqid('', true);
        mkdir($diretorio);
        $this->diretoriosTemporarios[] = $diretorio;

        $bom = $this->montarPlanilha([$this->linhaDeDado()]);
        copy($bom, $diretorio . '/' . basename($bom));

        if ($comIntruso) {
            // Um .xlsx de verdade, só que com nome que não identifica relatório nenhum. É o caso real:
            // arquivo renomeado à mão, ou uma quinta emissão que a contábil passe a mandar.
            copy($bom, $diretorio . '/nao_sei_o_que_e.xlsx');
        }

        return $diretorio;
    }

    private function carteira(): Carteira
    {
        return CarteiraFactory::createOne(['tenant' => TenantFactory::createOne()])->_real();
    }

    private function contarLotes(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cobranca_relatorio_importado');
    }
}
