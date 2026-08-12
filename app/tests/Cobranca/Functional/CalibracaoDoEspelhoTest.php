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
use App\Cobranca\Service\Espelho\AgrupadorDeBoletos;
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
            new AgrupadorDeBoletos($linhas),
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

    #[TestDox('§9.13 — reproduz o caso REAL pinado: TOP LIFE I, 240 dias, pelo caminho novo')]
    public function testReproduzOCasoRealPinado(): void
    {
        // Números LITERAIS, copiados de `CalculadoraEncargosTest::provaRealToplifeIComAtrasoDe240Dias`:
        // P=170,00 · 240 dias · honorários 20% → juros 13,60 · multa 3,40 · correção 0 · honorários 37,40.
        //
        // A versão anterior deste teste preenchia a planilha com a SAÍDA da mesma calculadora que a
        // calibração usa — qualquer fórmula deixaria os dois lados verdes. Provava que a calibração
        // compara, não que a conta bate.
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $dadosAte = $vencimento->modify('+240 days'); // 12/08/2026

        $this->obrigacao($caso, '74608', '12/2025', 17000, $vencimento);

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 170.00, juros: 13.60, multa: 3.40, correcao: 0.0, honorarios: 37.40,
            ),
        ], $dadosAte->format('d/m/Y'));

        self::assertSame(1, $resultado->comparadas);
        self::assertSame(1, $resultado->exatas(), sprintf('piores: %s', json_encode($resultado->piores)));
        self::assertSame('bate', $resultado->veredito());
    }

    #[TestDox('Diferença de um centavo é "bate quase" — vale a reancoragem, não é defeito')]
    public function testUmCentavoDeDiferencaEhBateQuase(): void
    {
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $dadosAte = $vencimento->modify('+240 days');

        $this->obrigacao($caso, '74608', '12/2025', 17000, $vencimento);

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 170.00, juros: 13.61, multa: 3.40, correcao: 0.0, honorarios: 37.40,
            ),
        ], $dadosAte->format('d/m/Y'));

        self::assertSame(0, $resultado->exatas());
        self::assertSame(1, $resultado->faixas['ate 1 centavo']);
        self::assertSame('bate quase', $resultado->veredito());
    }

    #[TestDox('INV-CB4 — a conta é LINHA A LINHA, como a da contabilidade (caso real, TL1 12/08)')]
    public function testContaLinhaALinhaComoAContabilidade(): void
    {
        // O boleto REAL que a medição de produção usou para provar a régua deles (espelho §16.2):
        // 4 linhas, 1.454 dias de atraso, honorários 20%. A contabilidade calcula e ARREDONDA por
        // linha; somando as linhas e rodando a fórmula uma vez sobre o total, os três encargos erram.
        //
        // Por linha (é o que a planilha traz):
        //   1.1  100,00 → juros 48,47 · multa 2,00 · hon 30,09
        //   1.14  45,00 → juros 21,81 · multa 0,90 · hon 13,54
        //   1.4    3,38 → juros  1,64 · multa 0,07 · hon  1,02
        //   1.5    2,90 → juros  1,41 · multa 0,06 · hon  0,87
        //   soma        → juros 73,33 · multa 3,03 · hon 45,52   ← o que a planilha soma
        //
        // Pela régua ANTIGA (por boleto), o principal do ramo "boleto comum" seria 145,00 — as linhas
        // 1.4/1.5 ficam fora — e daria juros 70,28 · multa 2,90 · hon 43,64: R$ 3,05 de diferença,
        // faixa "acima de 1 real". Este teste fica VERMELHO se alguém voltar a agregar.
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '09-04C');
        $vencimento = new \DateTimeImmutable('2022-08-19');
        $dadosAte = $vencimento->modify('+1454 days');

        // `valorOriginal` do SISTEMA é 145,00 (só 1.1/1.14) — e de propósito não é o que a calibração
        // usa: ela pergunta se a NOSSA FÓRMULA reproduz a DELES sobre os dados DELES (§6.1).
        $this->obrigacao($caso, '61687', '07/2022', 14500, $vencimento);

        $comum = [
            'unidade' => '09-04C', 'nn' => '61687', 'competencia' => '07/2022',
            'vencimento' => $vencimento->format('d/m/Y'), 'correcao' => 0.0,
        ];

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 100.00, juros: 48.47, multa: 2.00, honorarios: 30.09),
            $this->linhaDeDado(...$comum, classe: '1.14 - Energia', valor: 45.00, juros: 21.81, multa: 0.90, honorarios: 13.54),
            $this->linhaDeDado(...$comum, classe: '1.4 - Juros', valor: 3.38, juros: 1.64, multa: 0.07, honorarios: 1.02),
            $this->linhaDeDado(...$comum, classe: '1.5 - Multas', valor: 2.90, juros: 1.41, multa: 0.06, honorarios: 0.87),
        ], $dadosAte->format('d/m/Y'));

        self::assertSame(4, $resultado->comparadas, 'a unidade da medição é a LINHA, não o boleto');
        self::assertSame(4, $resultado->exatas(), sprintf('piores: %s', json_encode($resultado->piores)));
        self::assertSame('bate', $resultado->veredito());
    }

    #[TestDox('INV-CB3 — linha de desconto (valor negativo) sai da calibração, não vira divergência')]
    public function testLinhaDeDescontoFicaForaDaCalibracao(): void
    {
        // Medido em produção (12/08): 3 linhas de valor negativo nas três carteiras, e a
        // contabilidade lança encargo NEGATIVO nelas. A `CalculadoraEncargos` degrada para zero em
        // base não positiva — de propósito. Comparar os dois acusaria divergência que não é de
        // fórmula: a maior delas vale R$ 3,92 de honorário.
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $dadosAte = $vencimento->modify('+240 days');

        $this->obrigacao($caso, '74608', '12/2025', 17000, $vencimento);

        $comum = [
            'unidade' => '01-01', 'nn' => '74608', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0,
        ];

        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 170.00, juros: 13.60, multa: 3.40, honorarios: 37.40),
            $this->linhaDeDado(...$comum, classe: '1.6 - Desconto', valor: -20.00, juros: -1.60, multa: -0.40, honorarios: -4.40),
        ], $dadosAte->format('d/m/Y'));

        self::assertSame(1, $resultado->comparadas, 'só a linha de base positiva entra');
        self::assertSame(1, $resultado->foraDaCalibracao, 'a de desconto sai — e sai VISÍVEL');
        self::assertSame(1, $resultado->exatas());
        self::assertSame([], $resultado->piores, 'desconto não pode virar divergência falsa');
    }

    #[TestDox('"bate quase" é ATÉ UM CENTAVO — R$ 0,99 por linha não é arredondamento')]
    public function testDiferencaDeQuaseUmRealNaoEhBateQuase(): void
    {
        $carteira = $this->carteiraTopLife(2000);
        $caso = $this->caso($carteira, '01-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $dadosAte = $vencimento->modify('+240 days');

        $this->obrigacao($caso, '74608', '12/2025', 17000, $vencimento);

        // juros certo é 13,60; 14,59 são 99 centavos a mais — cai na faixa "até 1 real".
        $resultado = $this->calibrar($carteira, [
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 170.00, juros: 14.59, multa: 3.40, correcao: 0.0, honorarios: 37.40,
            ),
        ], $dadosAte->format('d/m/Y'));

        self::assertSame(1, $resultado->faixas['ate 1 real']);
        // A versão anterior somava esta faixa ao "bate quase" e imprimia SUCESSO verde.
        self::assertSame('nao bate', $resultado->veredito());
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
    private function calibrar(
        Carteira $carteira,
        array $linhas,
        string $dadosAte = '12/08/2026',
    ): \App\Cobranca\DTO\ResultadoCalibracao {
        $arquivo = $this->montarPlanilha($linhas, dadosAte: $dadosAte);
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
