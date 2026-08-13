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
            'Nada divergente no que deu para ler — e nada a concluir sobre o resto',
            $this->semQuebras($saida),
        );
        // N4 — a cobertura sai em CAIXA, não em texto solto que o veredito abafa.
        self::assertStringContainsString(
            'COBERTURA INCOMPLETA: a assinatura foi lida em 0 de 1 dívida(s) (0.00%)',
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

    #[TestDox('🔴 N6 — dupla contagem sai com FAILURE, e a lista da reconciliação sai completa')]
    public function testDuplaContagemSaiComFailureEListaNominal(): void
    {
        $carteira = $this->carteiraComDuplaContagem();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--duplicadas' => true,
        ]);

        self::assertSame(ConferirEncargosGravadosCommand::DUPLA_CONTAGEM, $codigo, 'o código que existe para gritar dinheiro duplicado');

        $saida = $this->semQuebras($this->comando->getDisplay());

        self::assertStringContainsString('DINHEIRO CONTADO DUAS VEZES', $saida);
        self::assertStringContainsString('Lista da reconciliação', $saida);
        // A linha traz o LOTE usado — é o que permite achar e desfazer um erro depois (§17.8).
        self::assertStringContainsString('(12/08/2026)', $saida);
        // E os dois totais SEPARADOS: a multa move o saldo do devedor, o honorário não.
        self::assertStringContainsString('sai do SALDO do devedor (juros + multa + correção): R$ 45,45', $saida);
        self::assertStringContainsString('sai FORA do saldo (honorário', $saida);
    }

    #[TestDox('N2 — tenant inexistente tem código próprio, fora dos códigos baixos')]
    public function testTenantInexistenteTemCodigoProprio(): void
    {
        $codigo = $this->comando->execute(['--tenant-id' => '999999']);

        self::assertSame(ConferirEncargosGravadosCommand::ERRO_DE_INVOCACAO, $codigo);
        // `1` é exceção não capturada e `2` é `Command::INVALID`, usado por 10 comandos irmãos no mesmo
        // diretório. Colidir com qualquer um dos dois faria um wrapper ler outra coisa.
        self::assertNotSame(Command::FAILURE, $codigo);
        self::assertNotSame(Command::INVALID, $codigo);
    }

    #[TestDox('🔴 achado 3 — sem carteira conferida NÃO sai 0; "não houve o que conferir" != "está limpo"')]
    public function testNenhumaCarteiraConferidaNaoSaiComSucesso(): void
    {
        // Medido pela revisão: `--carteira-id=9999` saía 0, ou seja, o comando dizia "limpo e completo"
        // sobre carteira nenhuma. É a mesma falha-aberto que a revisão anterior condenou no
        // repositório, sobrevivendo no comando.
        $carteira = $this->carteiraComLote(
            snapshot: new \DateTimeImmutable('2026-08-12'),
            vencimento: new \DateTimeImmutable('2025-12-15'),
            encargos: [1360, 340, 0, 3740],
        );

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--carteira-id' => '999999',
        ]);

        self::assertSame(ConferirEncargosGravadosCommand::NADA_CONFERIDO, $codigo);
        self::assertNotSame(Command::SUCCESS, $codigo, 'sair 0 aqui é dizer "pode seguir" sobre coisa nenhuma');
        self::assertStringContainsString(
            'Nenhuma carteira foi conferida',
            $this->semQuebras($this->comando->getDisplay()),
        );
    }

    /**
     * Uma carteira com UMA parcela de acordo que tem a assinatura exata da dupla contagem na MULTA —
     * a forma em que o defeito apareceu em produção.
     *
     *   1.1 → valor 400,00 · multa 8,00 · 1.5 → valor 45,45 · multa 0,91
     *   Σ J = 8,91 · multa gravada = 8,91 + 45,45 = 54,36  ← os 45,45 duplicados
     */
    private function carteiraComDuplaContagem(): Carteira
    {
        $carteira = $this->carteiraVazia();
        $caso = $this->caso($carteira, '02-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => '74790',
            'competencia' => '12/2025',
            'valorOriginal' => 44545,
            'vencimentoOriginal' => $vencimento,
        ])->_real();

        $obrigacao->definirEncargos(3560, 5436, 0, 0, new \DateTimeImmutable('2026-08-12'));
        $this->em->flush();

        $comum = [
            'unidade' => '02-01', 'nn' => '74790', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $arquivo = $this->montarPlanilha([
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 32.00, multa: 8.00, honorarios: 0.0),
            $this->linhaDeDado(...$comum, classe: '1.5 - Multas', valor: 45.45, juros: 3.60, multa: 0.91, honorarios: 0.0),
        ], dadosAte: self::EMISSAO_DO_LOTE, emissao: self::EMISSAO_DO_LOTE . ' 09:42');

        $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        return $carteira;
    }

    /**
     * O `SymfonyStyle` quebra as caixas de aviso em várias linhas, com padding — asserir a frase
     * literal falharia por formatação, não por comportamento.
     */
    private function semQuebras(string $saida): string
    {
        return (string) preg_replace('/\s+/u', ' ', $saida);
    }

    /** A carteira TOPLIFE padrão dos testes do espelho, sem objeto nem obrigação. */
    private function carteiraVazia(): Carteira
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
            'percentualHonorarios' => '20.00',
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
        ]);

        /** @var CasoCobranca $caso */
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();

        return $caso;
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
        $carteira = $this->carteiraVazia();
        $caso = $this->caso($carteira, '01-01');

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
