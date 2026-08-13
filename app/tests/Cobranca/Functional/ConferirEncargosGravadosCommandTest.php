<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Command\ConferirEncargosGravadosCommand;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * O que a régua do encargo gravado PROMETE ao mundo de fora — a tela e o **código de saída**.
 *
 * Existe por um motivo estreito, e a revisão o encontrou: o balde `injulgáveis` (INV-CE6) só serve
 * para alguma coisa se chegar a quem lê. Com o veredito e o exit code cegos para ele, o comando
 * imprimia a caixa verde *"Todo encargo gravado é um número que a nossa fórmula produz"* e saía com
 * `0` numa carteira com **2 dívidas conferidas e 528 jamais examinadas**. Nenhum teste quebrava.
 *
 * O exit code é o que um cron enxerga: avisar o humano na tela e dizer "tudo certo" para a máquina é
 * ter metade do aviso.
 */
final class ConferirEncargosGravadosCommandTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    private const EMISSAO_DO_LOTE = '12/08/2026';

    private EntityManagerInterface $em;
    private CommandTester $comando;
    private GravarEspelhoRelatorioUseCase $gravar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->gravar = static::getContainer()->get(GravarEspelhoRelatorioUseCase::class);

        $aplicacao = new Application(self::$kernel);
        $this->comando = new CommandTester($aplicacao->find('app:cobranca:espelho:encargos'));
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('🔴 A2 — dívida injulgável NÃO sai com código 0; sai com COBERTURA_INCOMPLETA')]
    public function testCoberturaIncompletaNaoSaiComSucesso(): void
    {
        // A dívida vence DEPOIS do próprio snapshot, então a fórmula em 30/07 produz zeros — que é o
        // que está gravado nela. Coerente por construção, e ainda assim injulgável: é o único jeito de
        // provar que o código de saída reage à COBERTURA e não à divergência, que sai com o mesmo 2.
        $carteira = $this->carteiraComLote(
            snapshot: new \DateTimeImmutable('2026-07-30'),
            vencimento: new \DateTimeImmutable('2026-08-01'),
            encargos: [0, 0, 0, 0],
        );

        $codigo = $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);

        self::assertSame(
            ConferirEncargosGravadosCommand::COBERTURA_INCOMPLETA,
            $codigo,
            'sair 0 aqui diria à máquina "está limpo" logo depois de avisar o humano do contrário',
        );

        $saida = $this->comando->getDisplay();

        self::assertStringContainsString('INJULGÁVEIS', $saida);
        self::assertStringNotContainsString(
            'Todo encargo gravado é um número que a nossa fórmula produz',
            $saida,
            'a caixa verde não pode aparecer com dívida por conferir',
        );
        // Prende o veredito: sem isto o teste passaria também se a dívida caísse em "divergente",
        // que sai com o mesmo código — e aí não estaria provando a cobertura incompleta.
        self::assertStringContainsString(
            'Isto NÃO é "está tudo certo": é "não deu para conferir tudo"',
            $this->semQuebras($saida),
        );
        self::assertStringContainsString('assinatura avaliada: 0', $saida);
    }

    #[TestDox('Cobertura total e nada divergente: aí sim sai 0 e imprime a caixa verde')]
    public function testCoberturaTotalSaiComSucesso(): void
    {
        // Os números pinados de `CalculadoraEncargosTest`: P=170,00 · 240 dias · 20%.
        $carteira = $this->carteiraComLote(
            snapshot: new \DateTimeImmutable('2026-08-12'),
            vencimento: new \DateTimeImmutable('2025-12-15'),
            encargos: [1360, 340, 0, 3740],
        );

        $codigo = $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);

        self::assertSame(Command::SUCCESS, $codigo);

        $saida = $this->comando->getDisplay();

        self::assertStringContainsString('Todo encargo gravado é um número que a nossa fórmula produz', $saida);
        self::assertStringNotContainsString('INJULGÁVEIS', $saida, 'aviso que aparece sempre deixa de ser aviso');
    }

    /**
     * O `SymfonyStyle` quebra as caixas de aviso em várias linhas, com padding — asserir a frase
     * literal falharia por formatação, não por comportamento.
     */
    private function semQuebras(string $saida): string
    {
        return (string) preg_replace('/\s+/u', ' ', $saida);
    }

    /**
     * Uma carteira com UM lote emitido em 12/08 e UMA obrigação COERENTE com a fórmula, carimbada na
     * data que o teste pedir. Os dois casos são coerentes de propósito: o que muda entre eles é só
     * haver ou não lote na data do snapshot, que é o fator sob teste.
     *
     * @param array{int, int, int, int} $encargos juros, multa, correção, honorários
     */
    private function carteiraComLote(
        \DateTimeImmutable $snapshot,
        \DateTimeImmutable $vencimento,
        array $encargos,
    ): Carteira {
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

        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'carteira' => $carteira,
            'identificacao' => '01-01',
        ]);

        /** @var CasoCobranca $caso */
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();

        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => '74608',
            'competencia' => '12/2025',
            'valorOriginal' => 17000,
            'vencimentoOriginal' => $vencimento,
        ])->_real();

        [$juros, $multa, $correcao, $honorarios] = $encargos;
        $obrigacao->definirEncargos($juros, $multa, $correcao, $honorarios, $snapshot);
        $this->em->flush();

        $arquivo = $this->montarPlanilha([
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', classe: '1.1 - Taxa de condomínio', competencia: '12/2025',
                vencimento: '15/12/2025', valor: 170.00, juros: 13.60, multa: 3.40, correcao: 0.0,
                honorarios: 37.40,
            ),
        ], dadosAte: self::EMISSAO_DO_LOTE, emissao: self::EMISSAO_DO_LOTE . ' 09:42');

        $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        return $carteira;
    }
}
