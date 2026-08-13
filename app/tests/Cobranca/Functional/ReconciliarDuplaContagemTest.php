<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Cobranca\UseCase\ReconciliarDuplaContagemUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A ÚNICA peça desta frente que escreve dinheiro (SPEC espelho §17.11).
 *
 * O teste que mais importa aqui é o do **honorário**: o valor a gravar não é `gravado − duplicado`, e
 * a diferença é a coluna L da própria linha `1.15`, que o adapter descarta. Um teste que só conferisse
 * a subtração ficaria verde com o defeito — foi assim que a assimetria escapou duas vezes nesta frente.
 */
#[CoversClass(ReconciliarDuplaContagemUseCase::class)]
final class ReconciliarDuplaContagemTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    private const EMISSAO_DO_LOTE = '12/08/2026';

    private EntityManagerInterface $em;
    private ReconciliarDuplaContagemUseCase $reconciliar;
    private GravarEspelhoRelatorioUseCase $gravar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->gravar = static::getContainer()->get(GravarEspelhoRelatorioUseCase::class);
        $this->reconciliar = static::getContainer()->get(ReconciliarDuplaContagemUseCase::class);
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('🔴 simula por padrão: a prévia NÃO altera um centavo no banco')]
    public function testPreverNaoGravaNada(): void
    {
        [$carteira, $obrigacao] = $this->cenarioDaMulta();

        $r = $this->reconciliar->prever([$carteira], $this->tenantDe($carteira));

        self::assertSame(1, $r->candidatas);
        self::assertCount(1, $r->corrigidas);
        self::assertFalse($r->aplicou);
        self::assertSame(4545, $r->removidoDoSaldoEmCentavos(), 'é o que SAIRIA do saldo');

        // 🔑 A prova precisa de um FLUSH no meio, e isso não é zelo: um dry-run que mexe na entidade
        // managed e só "não dá flush" **não é** dry-run — a UnitOfWork fica suja e QUALQUER flush
        // posterior no mesmo processo transforma a simulação em escrita. Sem esta linha o teste passa
        // verde com a versão que grava na prévia (conferido reintroduzindo o defeito).
        $this->em->flush();
        $this->em->refresh($obrigacao);

        self::assertSame(5436, $obrigacao->getMulta(), 'a prévia não pode ter sujado a UnitOfWork');
        self::assertSame(0, $this->eventosDoCaso($obrigacao), 'nem registrado histórico');
    }

    #[TestDox('🔴 --aplicar grava a MULTA das colunas e o saldo do devedor cai')]
    public function testAplicarGravaOEncargoDasColunas(): void
    {
        [$carteira, $obrigacao] = $this->cenarioDaMulta();
        $exigivelAntes = $obrigacao->valorExigivel();

        $r = $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $this->usuario($carteira));

        self::assertTrue($r->aplicou);
        self::assertSame(4545, $r->removidoDoSaldoEmCentavos());
        self::assertSame(0, $r->removidoForaDoSaldoEmCentavos());

        $this->em->refresh($obrigacao);

        // Σ coluna J = 8,00 + 0,91 = 8,91 — o encargo das colunas, sem o H da linha 1.5 somado por cima.
        self::assertSame(891, $obrigacao->getMulta());
        self::assertSame(3560, $obrigacao->getJuros(), 'campo não marcado não pode se mover');
        self::assertSame(0, $obrigacao->getCorrecao(), 'correção NUNCA é tocada');
        self::assertSame($exigivelAntes - 4545, $obrigacao->valorExigivel());
    }

    #[TestDox('🔴 INV-CE9 — no HONORÁRIO grava Σ da coluna L de TODAS as linhas, não gravado − duplicado')]
    public function testHonorarioGravaAColunaEhNaoASubtracao(): void
    {
        // A armadilha desta frente, do lado da escrita. O adapter, na linha `1.15`, TROCA a coluna L
        // pelo Valor em vez de somar — então:
        //   1.1   → valor 400,00 · honorário 80,00
        //   1.15  → valor  45,45 · honorário 10,00   ← este 10,00 é o que o adapter descartou
        //   gravado    = 80,00 + 45,45 = 125,45
        //   duplicado  = 45,45  (o Valor da linha 1.15)
        //   SUBTRAIR   → 80,00   ❌ perde a coluna L da própria 1.15
        //   O CERTO    → Σ_todas L = 80,00 + 10,00 = 90,00   ✅
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '02-02');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $obrigacao = $this->obrigacao($caso, '74791', 44545, $vencimento, 3560, 891, 0, 12545);

        $comum = [
            'unidade' => '02-02', 'nn' => '74791', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $this->lote($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 32.00, multa: 8.00, honorarios: 80.00),
            $this->linhaDeDado(...$comum, classe: '1.15 - Honorário', valor: 45.45, juros: 3.60, multa: 0.91, honorarios: 10.00),
        ]);

        $r = $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $this->usuario($carteira));

        $this->em->refresh($obrigacao);

        self::assertSame(9000, $obrigacao->getHonorarios(), 'Σ coluna L das DUAS linhas — e não 8000');
        self::assertNotSame(8000, $obrigacao->getHonorarios(), 'a subtração daria 8000 e perderia a coluna L da 1.15');

        // E o efeito reportado é o REAL (125,45 → 90,00 = 35,45), não o "duplicado" da régua (45,45).
        self::assertSame(3545, $r->removidoForaDoSaldoEmCentavos());
        self::assertSame(0, $r->removidoDoSaldoEmCentavos(), 'honorário não move o saldo de ninguém');
    }

    #[TestDox('🔴 INV-R1 — obrigação CONGELADA é pulada, e o valor inflado dela sai no relatório')]
    public function testCongeladaEhPuladaComOValorReportado(): void
    {
        [$carteira, $obrigacao] = $this->cenarioDaMulta();
        $obrigacao->congelarEncargos(new \DateTimeImmutable('2026-08-12'));
        $this->em->flush();

        $r = $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $this->usuario($carteira));

        self::assertCount(0, $r->corrigidas);
        self::assertCount(1, $r->puladas);
        self::assertStringContainsString('CONGELADOS', $r->puladas[0]['motivo']);
        // Pular não é no-op: o número tem de aparecer, senão a linha parece rotina.
        self::assertSame(4545, $r->inflacaoQueFicouEmCentavos());
        self::assertTrue($r->contasFecham());

        $this->em->refresh($obrigacao);
        self::assertSame(5436, $obrigacao->getMulta(), 'a congelada não pode ter sido tocada');
    }

    #[TestDox('🔴 INV-R2 — preserva encargosAtualizadosEm: a correção remove soma, não recalcula')]
    public function testPreservaADataDoSnapshot(): void
    {
        [$carteira, $obrigacao] = $this->cenarioDaMulta();
        $snapshotAntes = $obrigacao->getEncargosAtualizadosEm();

        $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $this->usuario($carteira));

        $this->em->refresh($obrigacao);

        self::assertEquals(
            $snapshotAntes,
            $obrigacao->getEncargosAtualizadosEm(),
            'recarimbar mentiria sobre a procedência do número e quebraria a própria régua',
        );
    }

    #[TestDox('🔴 INV-R3 — o histórico do caso registra QUAL LOTE foi usado')]
    public function testRegistraOLoteNoHistorico(): void
    {
        [$carteira, $obrigacao] = $this->cenarioDaMulta();

        $r = $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $this->usuario($carteira));

        self::assertSame(1, $r->casosComEvento);

        $evento = $this->em->getRepository(EventoHistorico::class)
            ->findOneBy(['caso' => $obrigacao->getCaso()], ['id' => 'DESC']);

        self::assertNotNull($evento);

        $dados = $evento->getDados() ?? [];

        // Sem o lote, um erro de reconciliação não tem como ser achado nem desfeito depois.
        self::assertSame('reconciliacao_dupla_contagem', $dados['origem'] ?? null);
        self::assertSame('2026-08-12', $dados['loteEmitidoEm'] ?? null);
        self::assertNotNull($dados['loteId'] ?? null);
        self::assertSame(4545, $dados['removidoDoSaldoCentavos'] ?? null);
        // E o antes/depois de cada obrigação, que é o que permite desfazer.
        self::assertSame(5436, $dados['obrigacoes'][0]['antes']['multa'] ?? null);
        self::assertSame(891, $dados['obrigacoes'][0]['depois']['multa'] ?? null);
    }

    #[TestDox('Idempotente: rodar de novo não acha mais nada — o corrigido não casa a assinatura')]
    public function testRodarDeNovoNaoAchaNada(): void
    {
        [$carteira, $obrigacao] = $this->cenarioDaMulta();
        $tenant = $this->tenantDe($carteira);

        $this->reconciliar->confirmar([$carteira], $tenant, $this->usuario($carteira));
        $segunda = $this->reconciliar->prever([$carteira], $tenant);

        self::assertSame(0, $segunda->candidatas, 'a assinatura deixou de casar; não há o que reconciliar');
    }

    /**
     * O cenário em que o defeito apareceu em produção: parcela de acordo com a assinatura na MULTA.
     *   1.1 → valor 400,00 · multa 8,00 · 1.5 → valor 45,45 · multa 0,91
     *   Σ J = 8,91 · multa gravada = 8,91 + 45,45 = 54,36  ← os 45,45 duplicados
     *
     * @return array{Carteira, Obrigacao}
     */
    private function cenarioDaMulta(): array
    {
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '02-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $obrigacao = $this->obrigacao($caso, '74790', 44545, $vencimento, 3560, 5436, 0, 0);

        $comum = [
            'unidade' => '02-01', 'nn' => '74790', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $this->lote($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 32.00, multa: 8.00, honorarios: 0.0),
            $this->linhaDeDado(...$comum, classe: '1.5 - Multas', valor: 45.45, juros: 3.60, multa: 0.91, honorarios: 0.0),
        ]);

        return [$carteira, $obrigacao];
    }

    /** @param list<list<mixed>> $linhas */
    private function lote(Carteira $carteira, array $linhas): void
    {
        $arquivo = $this->montarPlanilha(
            $linhas,
            dadosAte: self::EMISSAO_DO_LOTE,
            emissao: self::EMISSAO_DO_LOTE . ' 09:42',
        );

        $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));
    }

    private function obrigacao(
        CasoCobranca $caso,
        string $referencia,
        int $valorOriginal,
        \DateTimeImmutable $vencimento,
        int $juros,
        int $multa,
        int $correcao,
        int $honorarios,
    ): Obrigacao {
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => $referencia,
            'competencia' => '12/2025',
            'valorOriginal' => $valorOriginal,
            'vencimentoOriginal' => $vencimento,
        ])->_real();

        // O snapshot na data de emissão do lote — é o que faz a régua achar o lote que a escreveu.
        $obrigacao->definirEncargos($juros, $multa, $correcao, $honorarios, new \DateTimeImmutable('2026-08-12'));
        $this->em->flush();

        return $obrigacao;
    }

    private function carteiraTopLife(): Carteira
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

    private function tenantDe(Carteira $carteira): \App\Entity\Tenant\Tenant
    {
        $tenant = $carteira->getTenant();
        self::assertNotNull($tenant);

        return $tenant;
    }

    private function usuario(Carteira $carteira): User
    {
        /** @var User $user */
        $user = UserFactory::createOne()->_real();

        // O vínculo usuário↔escritório mora em `UserTenant`; o `User` não carrega tenant.
        $this->em->persist(new UserTenant($user, $this->tenantDe($carteira)));
        $this->em->flush();

        return $user;
    }

    private function eventosDoCaso(Obrigacao $obrigacao): int
    {
        return (int) $this->em->getRepository(EventoHistorico::class)
            ->count(['caso' => $obrigacao->getCaso()]);
    }
}
