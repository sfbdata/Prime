<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Cobranca\Service\Espelho\AgrupadorDeBoletos;
use App\Cobranca\Service\Espelho\ConferenciaDoEspelho;
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Cobranca\AcordoFactory;
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
 * A conferência sistema × contabilidade (SPEC espelho §5, testes §9 itens 10 a 12).
 *
 * O teste mais importante desta classe é o do RAMO (§5.3): boleto comum e parcela de acordo somam o
 * principal de formas diferentes, e igualar os dois reintroduz no caminho normal a dupla contagem
 * que a Fase 1 existe para matar.
 */
#[CoversClass(ConferenciaDoEspelho::class)]
final class ConferenciaDoEspelhoTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    private EntityManagerInterface $em;
    private ConferenciaDoEspelho $conferencia;
    private GravarEspelhoRelatorioUseCase $gravar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var RelatorioImportadoRepository $relatorios */
        $relatorios = $this->em->getRepository(RelatorioImportado::class);
        /** @var RelatorioLinhaRepository $linhas */
        $linhas = $this->em->getRepository(\App\Cobranca\Entity\RelatorioLinha::class);
        /** @var ObrigacaoRepository $obrigacoes */
        $obrigacoes = $this->em->getRepository(Obrigacao::class);

        $this->gravar = new GravarEspelhoRelatorioUseCase(new LeitorEspelhoRelatorio(), $relatorios, $this->em);
        $this->conferencia = new ConferenciaDoEspelho(
            new AgrupadorDeBoletos($linhas),
            $obrigacoes,
            $this->em->getRepository(\App\Cobranca\Entity\ObjetoCobranca::class),
        );
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('Dívida idêntica dos dois lados: confere, e os baldes somam')]
    public function testDividaIdenticaConfere(): void
    {
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');
        $this->obrigacao($caso, '74608', '02/2026', 19000);

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 190.00),
        ]);

        self::assertSame(1, $resultado->confere);
        self::assertTrue($resultado->alinhado());
        self::assertTrue($resultado->baldesFecham());
    }

    #[TestDox('🔑 §5.3 — parcela de acordo soma TODAS as classes; boleto comum, só as de principal')]
    public function testRamoDoAcordoSomaTodasAsClasses(): void
    {
        $carteira = $this->carteira();

        // Parcela de acordo composta só de encargo: o principal negociado é a soma de TUDO (445,45).
        $casoAcordo = $this->caso($carteira, '01-09');
        $this->obrigacao($casoAcordo, '67611', '08/2026', 44545);

        // Boleto comum com linha de encargo junto: o principal é SÓ a taxa (190,00). Se a conferência
        // somasse tudo aqui também, acharia 210,00 e acusaria divergência — que é o defeito que a
        // Fase 1 vai matar, aplicado ao caminho normal.
        $casoComum = $this->caso($carteira, '01-01');
        $this->obrigacao($casoComum, '74608', '02/2026', 19000);

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-09', nn: '67611', classe: '1.4 - Juros', competencia: '08/2026',
                valor: 445.45, acordo: 'Acordo 426 - Parc. 1/6'),
            $this->linhaDeDado(unidade: '01-01', nn: '74608', classe: '1.1 - Taxa de condomínio',
                competencia: '02/2026', valor: 190.00),
            $this->linhaDeDado(unidade: '01-01', nn: '74608', classe: '1.4 - Juros',
                competencia: '02/2026', valor: 20.00),
        ]);

        self::assertSame(2, $resultado->confere, 'os dois ramos conferem com a regra certa');
        self::assertSame([], $resultado->principalDiferente);
    }

    #[TestDox('A classe 1.1 não pode casar com 1.14 — o código é comparado inteiro')]
    public function testClasseComparaCodigoInteiro(): void
    {
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');
        // 1.1 (190) + 1.14 (45) = 235,00 são principal. A linha 1.15 (honorário, 60,00) NÃO é —
        // e é ela que faz o teste valer: com casamento por prefixo, `1.15` também começa com `1.1`
        // e entraria, dando 295,00. Sem essa linha o teste passaria com o defeito presente.
        $this->obrigacao($caso, '74608', '02/2026', 23500);

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', classe: '1.1 - Taxa de condomínio',
                competencia: '02/2026', valor: 190.00),
            $this->linhaDeDado(unidade: '01-01', nn: '74608', classe: '1.14 - Energia',
                competencia: '02/2026', valor: 45.00),
            $this->linhaDeDado(unidade: '01-01', nn: '74608', classe: '1.15 - Honorário advocatício',
                competencia: '02/2026', valor: 60.00),
        ]);

        self::assertSame(1, $resultado->confere, 'a classe 1.15 não pode ter entrado no principal');
        self::assertSame([], $resultado->principalDiferente);
    }

    #[TestDox('Dívida que a contabilidade cobra e o sistema não tem cai em "falta"')]
    public function testDividaAusenteCaiEmFalta(): void
    {
        $carteira = $this->carteira();
        $this->caso($carteira, '01-01');

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '99999', competencia: '02/2026', valor: 190.00),
        ]);

        self::assertCount(1, $resultado->faltaNoSistema);
        self::assertSame('99999', $resultado->faltaNoSistema[0]['nn']);
        self::assertTrue($resultado->baldesFecham());
    }

    #[TestDox('Dívida que o sistema tem e a contabilidade não cobra mais cai em "sobra"')]
    public function testDividaAMaisCaiEmSobra(): void
    {
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');
        $this->obrigacao($caso, '74608', '02/2026', 19000);
        $this->obrigacao($caso, '11111', '01/2026', 5000);

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 190.00),
        ]);

        self::assertCount(1, $resultado->sobraNoSistema);
        self::assertSame('11111', $resultado->sobraNoSistema[0]['nn']);
    }

    #[TestDox('§5.1 — obrigação substituída por acordo VIGENTE não vira "sobra" falsa')]
    public function testSubstituidaPorAcordoVigenteNaoEhSobra(): void
    {
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');

        $original = $this->obrigacao($caso, '11111', '01/2026', 5000);
        $acordo = AcordoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'status' => StatusAcordo::Ativo,
        ])->_real();
        $original->setAcordoSubstituto($acordo);
        $this->em->flush();

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 190.00),
        ]);

        // Ela saiu do relatório de inadimplência COM RAZÃO: virou parcela de acordo.
        self::assertSame([], $resultado->sobraNoSistema);
    }

    #[TestDox('§5.1 — obrigação RESTAURADA por acordo rompido volta ao universo')]
    public function testRestauradaPorAcordoRompidoEntraNoUniverso(): void
    {
        // O vínculo `acordoSubstituto` é PRESERVADO de propósito quando o acordo rompe. Uma régua que
        // olhasse só "tem substituto?" descartaria esta dívida e ela viraria "falta" falsa.
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');

        $original = $this->obrigacao($caso, '74608', '02/2026', 19000);
        $rompido = AcordoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'status' => StatusAcordo::Rompido,
        ])->_real();
        $original->setAcordoSubstituto($rompido);
        $this->em->flush();

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 190.00),
        ]);

        self::assertSame(1, $resultado->confere, 'a original voltou a ser exigível e casa com o relatório');
        self::assertSame([], $resultado->faltaNoSistema);
    }

    #[TestDox('§5.1 — parcela de acordo ROMPIDO não entra no universo (seria sobra falsa)')]
    public function testParcelaDeAcordoRompidoNaoEntra(): void
    {
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');

        $rompido = AcordoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'status' => StatusAcordo::Rompido,
        ])->_real();

        $parcela = $this->obrigacao($caso, '22222', '03/2026', 10000);
        $parcela->setAcordoOrigem($rompido);
        $this->em->flush();

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 190.00),
        ]);

        // Quem voltou a valer foi a dívida original, não a parcela do acordo morto.
        self::assertSame([], $resultado->sobraNoSistema);
    }

    #[TestDox('§5.1 — dívida já liquidada não entra no universo')]
    public function testLiquidadaNaoEntraNoUniverso(): void
    {
        // "Exigível" NÃO é "em aberto": o predicado do repositório inclui a quitada. Sem o filtro,
        // medido em produção, a TOP LIFE I saltaria de 3.099 para 10.576 obrigações.
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');

        $paga = $this->obrigacao($caso, '33333', '01/2026', 5000);
        $paga->liquidar(0, 0, 0, 0, new \DateTimeImmutable('2026-08-01'));
        $this->em->flush();

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 190.00),
        ]);

        self::assertSame([], $resultado->sobraNoSistema, 'dívida paga saiu do relatório com razão');
    }

    #[TestDox('§5.1 — parcela que vence depois do corte do relatório não é "sobra"')]
    public function testVencimentoAposCorteNaoEhSobra(): void
    {
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');
        $this->obrigacao($caso, '44444', '12/2026', 5000, new \DateTimeImmutable('2026-12-10'));

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 190.00),
        ]);

        self::assertSame([], $resultado->sobraNoSistema);
    }

    #[TestDox('Principal diferente é reportado com os dois números e a diferença')]
    public function testPrincipalDiferenteMostraOsDoisLados(): void
    {
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');
        $this->obrigacao($caso, '74608', '02/2026', 12910);

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 445.45),
        ]);

        self::assertCount(1, $resultado->principalDiferente);
        self::assertSame(44545, $resultado->principalDiferente[0]['relatorio']);
        self::assertSame(12910, $resultado->principalDiferente[0]['sistema']);
        self::assertSame(31635, $resultado->principalDiferente[0]['diferenca']);
        self::assertTrue($resultado->baldesFecham());
    }

    /**
     * @param list<list<mixed>> $linhas
     */
    private function conferir(Carteira $carteira, array $linhas): \App\Cobranca\DTO\ResultadoConferencia
    {
        $arquivo = $this->montarPlanilha($linhas);
        $saida = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        $lote = $this->em->getRepository(RelatorioImportado::class)->find($saida->relatorioId);
        self::assertNotNull($lote);

        return $this->conferencia->conferir($lote);
    }

    private function carteira(): Carteira
    {
        return CarteiraFactory::createOne(['tenant' => TenantFactory::createOne()])->_real();
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
        ?\DateTimeImmutable $vencimento = null,
    ): Obrigacao {
        return ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => $referencia,
            'competencia' => $competencia,
            'valorOriginal' => $valorOriginal,
            'vencimentoOriginal' => $vencimento ?? new \DateTimeImmutable('2026-02-10'),
        ])->_real();
    }
}
