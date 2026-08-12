<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * O que a tela da calibração PROMETE mostrar (SPEC espelho §6.4 e §16.2).
 *
 * Existe por um motivo estreito e específico: o INV-CB3 descarta da calibração as linhas de valor
 * não positivo, e a spec aceita esse buraco **em troca de ele nunca ser invisível**. A visibilidade
 * mora só na saída do comando — apagar o `if` do aviso não derrubava nenhum teste, e o buraco voltaria
 * a ser silencioso sem ninguém notar. Um invariante que só existe em texto impresso precisa de um
 * teste que leia o texto impresso.
 */
final class CalibrarEspelhoCommandTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    private EntityManagerInterface $em;
    private CommandTester $comando;
    private GravarEspelhoRelatorioUseCase $gravar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->gravar = static::getContainer()->get(GravarEspelhoRelatorioUseCase::class);

        $aplicacao = new Application(self::$kernel);
        $this->comando = new CommandTester($aplicacao->find('app:cobranca:espelho:calibrar'));
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('INV-CB3 — a linha descartada por falta de base aparece na tela, com o número')]
    public function testLinhaSemBaseApareceNaTela(): void
    {
        $carteira = $this->carteiraComLote([
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', classe: '1.1 - Taxa de condomínio', competencia: '12/2025',
                vencimento: '15/12/2025', valor: 170.00, juros: 13.60, multa: 3.40, correcao: 0.0,
                honorarios: 37.40,
            ),
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', classe: '1.6 - Descontos', competencia: '12/2025',
                vencimento: '15/12/2025', valor: -20.00, juros: -1.60, multa: -0.40, correcao: 0.0,
                honorarios: -4.40,
            ),
        ]);

        $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);

        $saida = $this->comando->getDisplay();

        self::assertStringContainsString('sem base: 1', $saida, 'o motivo é NOMEADO, não some no balde genérico');
        self::assertStringContainsString('INV-CB3', $saida, 'e o aviso aponta para o invariante que o explica');
        self::assertStringContainsString('não positivo', $saida);
    }

    #[TestDox('Sem linha descartada, o aviso do INV-CB3 NÃO aparece — não é ruído fixo')]
    public function testSemLinhaDescartadaNaoImprimeOAviso(): void
    {
        $carteira = $this->carteiraComLote([
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', classe: '1.1 - Taxa de condomínio', competencia: '12/2025',
                vencimento: '15/12/2025', valor: 170.00, juros: 13.60, multa: 3.40, correcao: 0.0,
                honorarios: 37.40,
            ),
        ]);

        $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);

        $saida = $this->comando->getDisplay();

        self::assertStringContainsString('sem base: 0', $saida);
        self::assertStringNotContainsString('INV-CB3', $saida, 'aviso que aparece sempre deixa de ser aviso');
    }

    /**
     * Uma carteira TOPLIFE com um lote já espelhado e a obrigação que casa com ele — o mínimo para o
     * comando ter o que calibrar.
     *
     * @param list<list<mixed>> $linhas
     */
    private function carteiraComLote(array $linhas): Carteira
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => TenantFactory::createOne(),
            'taxaJurosMensalBp' => 100,
            'regimeJuros' => RegimeJuros::Simples,
            'taxaMultaBp' => 200,
            'baseMulta' => BaseEncargo::Principal,
            'taxaCorrecaoBp' => 0,
            'baseCorrecao' => BaseEncargo::Principal,
            'baseHonorarios' => BaseEncargo::Composta,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
            'carenciaHonorariosDias' => 30,
            'toleranciaJurosMultaDias' => 0,
        ])->_real();

        $caso = $this->caso($carteira, '01-01');

        ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => '74608',
            'competencia' => '12/2025',
            'valorOriginal' => 17000,
            'vencimentoOriginal' => new \DateTimeImmutable('2025-12-15'),
        ]);

        $arquivo = $this->montarPlanilha($linhas, dadosAte: '12/08/2026');
        $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        return $carteira;
    }

    private function caso(Carteira $carteira, string $identificacao): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'carteira' => $carteira,
            'identificacao' => $identificacao,
        ]);

        return CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();
    }
}
