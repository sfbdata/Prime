<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Entity\RelatorioLinha;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\Espelho\CalibracaoDoEspelho;
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A calibração: a nossa fórmula de encargo × a da contabilidade (SPEC espelho §6).
 *
 * O caso de referência é o mesmo já pinado em `CalculadoraEncargosTest` — TOP LIFE I, 240 dias de
 * atraso — só que percorrendo o caminho novo inteiro: planilha → espelho → calibração.
 */
#[CoversClass(CalibracaoDoEspelho::class)]
final class CalibracaoDoEspelhoTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    private EntityManagerInterface $em;
    private CalibracaoDoEspelho $calibracao;
    private GravarEspelhoRelatorioUseCase $gravar;
    private CalculadoraEncargos $calculadora;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var RelatorioImportadoRepository $relatorios */
        $relatorios = $this->em->getRepository(RelatorioImportado::class);
        /** @var RelatorioLinhaRepository $linhas */
        $linhas = $this->em->getRepository(RelatorioLinha::class);
        /** @var ObrigacaoRepository $obrigacoes */
        $obrigacoes = $this->em->getRepository(Obrigacao::class);

        $this->calculadora = new CalculadoraEncargos();
        $this->gravar = new GravarEspelhoRelatorioUseCase(new LeitorEspelhoRelatorio(), $relatorios, $this->em);
        $this->calibracao = new CalibracaoDoEspelho(
            $linhas,
            $obrigacoes,
            $this->calculadora,
            new ResolvedorConfigEncargos(),
        );
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('Quando a planilha traz o mesmo encargo que a nossa conta, o veredito é "bate"')]
    public function testEncargoIdenticoDaBate(): void
    {
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $this->obrigacao($caso, '74608', '12/2025', 19000, $vencimento);

        // O que a NOSSA fórmula diz na data do relatório — é isso que a planilha de teste vai trazer.
        $nosso = $this->calculadora->calcular(
            19000,
            $vencimento,
            (new ResolvedorConfigEncargos())->resolverDaCarteira($carteira),
            new \DateTimeImmutable('2026-08-12'),
        );

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 190.00,
                juros: $nosso['juros'] / 100,
                multa: $nosso['multa'] / 100,
                correcao: $nosso['correcao'] / 100,
                honorarios: $nosso['honorarios'] / 100,
            ),
        ]);

        self::assertSame(1, $resultado->comparadas);
        self::assertSame(1, $resultado->exatas());
        self::assertSame('bate', $resultado->veredito());
    }

    #[TestDox('Diferença de um centavo é "bate quase" — vale a reancoragem, não é defeito')]
    public function testUmCentavoDeDiferencaEhBateQuase(): void
    {
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $this->obrigacao($caso, '74608', '12/2025', 19000, $vencimento);

        $nosso = $this->calculadora->calcular(
            19000,
            $vencimento,
            (new ResolvedorConfigEncargos())->resolverDaCarteira($carteira),
            new \DateTimeImmutable('2026-08-12'),
        );

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 190.00,
                juros: ($nosso['juros'] + 1) / 100, // um centavo a mais na planilha
                multa: $nosso['multa'] / 100,
                correcao: $nosso['correcao'] / 100,
                honorarios: $nosso['honorarios'] / 100,
            ),
        ]);

        self::assertSame(0, $resultado->exatas());
        self::assertSame(1, $resultado->faixas['ate 1 centavo']);
        self::assertSame('bate quase', $resultado->veredito());
    }

    #[TestDox('Diferença grande é "nao bate" — achado para levar à contabilidade')]
    public function testDiferencaGrandeNaoBate(): void
    {
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $this->obrigacao($caso, '74608', '12/2025', 19000, new \DateTimeImmutable('2025-12-15'));

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 190.00, juros: 999.00, multa: 0.0, correcao: 0.0, honorarios: 0.0,
            ),
        ]);

        self::assertSame('nao bate', $resultado->veredito());
        self::assertNotSame([], $resultado->piores);
        self::assertSame('juros', $resultado->piores[0]['campo']);
    }

    #[TestDox('INV-CB2: linha sem par no sistema fica FORA da calibração, não vira divergência')]
    public function testLinhaSemParFicaForaDaCalibracao(): void
    {
        $carteira = $this->carteiraTopLife(2000);
        $this->caso($carteira, '01-01');

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '99999', competencia: '12/2025', vencimento: '15/12/2025'),
        ]);

        self::assertSame(0, $resultado->comparadas, 'não entra na conta');
        self::assertSame(1, $resultado->foraDaCalibracao);
        // Ela é matéria da CONFERÊNCIA (balde "falta no sistema"); misturar as duas perguntas
        // produziria um percentual sem significado.
        self::assertSame([], $resultado->piores);
    }

    #[TestDox('Dívida ainda não vencida na data do relatório não entra na calibração')]
    public function testSemAtrasoNaoEntra(): void
    {
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $this->obrigacao($caso, '74608', '08/2026', 19000, new \DateTimeImmutable('2026-08-12'));

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '08/2026', vencimento: '12/08/2026'),
        ]);

        self::assertSame(0, $resultado->comparadas);
        self::assertSame(1, $resultado->foraDaCalibracao);
    }

    #[TestDox('INV-CB1: a config sai da cascata completa, não do preset da carteira')]
    public function testUsaOAjusteProprioDaObrigacao(): void
    {
        // 58 obrigações em produção têm ajuste próprio de encargo. Calibrar contra o preset da
        // carteira as reportaria como divergência falsa.
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $obrigacao = $this->obrigacao($caso, '74608', '12/2025', 19000, $vencimento);
        $obrigacao->setTaxaJurosMensalBp(0); // esta dívida, e só ela, não corre juros
        $this->em->flush();

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 190.00, juros: 0.0, multa: 3.80, correcao: 0.0, honorarios: 38.76,
            ),
        ]);

        // Se a calibração usasse o preset da carteira (1% ao mês), acharia juros > 0 e acusaria
        // divergência onde não há.
        self::assertSame(1, $resultado->comparadas);
        self::assertSame(0, $resultado->faixas['acima de 1 real'], sprintf(
            'piores: %s',
            json_encode($resultado->piores)
        ));
    }

    /**
     * @param list<list<mixed>> $linhas
     */
    private function calibrar(Carteira $carteira, array $linhas): \App\Cobranca\DTO\ResultadoCalibracao
    {
        $arquivo = $this->montarPlanilha($linhas);
        $saida = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        $lote = $this->em->getRepository(RelatorioImportado::class)->find($saida->relatorioId);
        self::assertNotNull($lote);

        return $this->calibracao->calibrar($lote);
    }

    /** A carteira com a configuração real das carteiras TOPLIFE, medida em produção. */
    private function carteiraTopLife(int $honorariosBp): Carteira
    {
        return CarteiraFactory::createOne([
            'tenant' => TenantFactory::createOne(),
            'taxaJurosMensalBp' => 100,
            'regimeJuros' => RegimeJuros::Simples,
            'taxaMultaBp' => 200,
            'baseMulta' => BaseEncargo::Principal,
            'taxaCorrecaoBp' => 0,
            'baseCorrecao' => BaseEncargo::Principal,
            'baseHonorarios' => BaseEncargo::Composta,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => $honorariosBp / 100,
            'carenciaHonorariosDias' => 30,
            'toleranciaJurosMultaDias' => 0,
        ])->_real();
    }

    private function caso(Carteira $carteira, string $identificacao): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'carteira' => $carteira,
            'identificacao' => $identificacao,
        ])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();
    }

    private function obrigacao(
        CasoCobranca $caso,
        string $referencia,
        string $competencia,
        int $valorOriginal,
        \DateTimeImmutable $vencimento,
    ): Obrigacao {
        return ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => $referencia,
            'competencia' => $competencia,
            'valorOriginal' => $valorOriginal,
            'vencimentoOriginal' => $vencimento,
        ])->_real();
    }
}
