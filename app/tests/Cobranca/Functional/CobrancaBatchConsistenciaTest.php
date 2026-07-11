<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Entity\RevisaoPessoaCobrada;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Repository\RevisaoPessoaCobradaRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\LiquidacaoFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\ProximaAcaoFactory;
use App\Tests\Factory\Cobranca\RevisaoPessoaCobradaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Prova de EQUIVALÊNCIA das primitivas em LOTE (P0 da otimização de queries) contra o BANCO REAL: os saldos
 * de `CalculadoraSaldo::saldosDosCasos` e os alertas de `AlertasCobranca::alertasDosCasos` batem, caso a caso,
 * com os métodos por-caso `saldoExigivel`/`saldoVencido`/`alertasDoCaso` (que ficam intactos). O batch só
 * troca a FONTE dos dados (uma carga tenant-scoped em vez de N cargas por caso) — a regra é a mesma.
 *
 * O cenário cobre cada condição de alerta + um caso ativo com pagamento ALOCADO e liquidação (exercita a
 * fiação do mapa `alocadoPorObrigacao`/`liquidadoPorCaso` no lote, não só a aritmética pura) + um encerrado.
 */
#[CoversClass(CalculadoraSaldo::class)]
#[CoversClass(AlertasCobranca::class)]
final class CobrancaBatchConsistenciaTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private CasoCobrancaRepository $casoRepo;
    private CalculadoraSaldo $calcSaldo;
    private AlertasCobranca $alertas;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var ObrigacaoRepository $obrigacaoRepo */
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        /** @var CasoCobrancaRepository $casoRepo */
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        $this->casoRepo = $casoRepo;
        /** @var AlocacaoPagamentoRepository $alocacaoRepo */
        $alocacaoRepo = $this->em->getRepository(AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(Liquidacao::class);
        /** @var ProximaAcaoRepository $acaoRepo */
        $acaoRepo = $this->em->getRepository(ProximaAcao::class);
        /** @var RevisaoPessoaCobradaRepository $revisaoRepo */
        $revisaoRepo = $this->em->getRepository(RevisaoPessoaCobrada::class);

        $this->calcSaldo = new CalculadoraSaldo($obrigacaoRepo, $casoRepo, $alocacaoRepo, $liquidacaoRepo);
        $this->alertas = new AlertasCobranca($obrigacaoRepo, $acaoRepo, $revisaoRepo, $this->calcSaldo);

        $this->hoje = new \DateTimeImmutable('2026-07-20');
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Batch ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function carteira(Tenant $tenant): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        return CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();
    }

    private function caso(Tenant $tenant, Carteira $carteira, StatusCaso $status = StatusCaso::Ativo): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => $status,
            'formaHonorarios' => $carteira->getFormaHonorarios(),
            'percentualHonorarios' => $carteira->getPercentualHonorarios(),
        ])->_real();
    }

    private function obrigacao(Tenant $tenant, CasoCobranca $caso, int $valor, string $vencimento): Obrigacao
    {
        return ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorOriginal' => $valor,
            'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable($vencimento),
        ])->_real();
    }

    private function pagamentoAlocado(Tenant $tenant, CasoCobranca $caso, Obrigacao $obrigacao, int $valorDivida, string $data): void
    {
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorDivida' => $valorDivida,
            'valorEncargos' => 0,
            'valorHonorarios' => 0,
            'data' => new \DateTimeImmutable($data),
        ])->_real();

        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant,
            'pagamento' => $pagamento,
            'obrigacao' => $obrigacao,
            'valor' => $valorDivida,
        ]);
    }

    /**
     * Monta o mesmo grafo variado do teste de consistência do Dashboard: cada condição de alerta em um caso
     * distinto + um caso ativo com pagamento alocado e liquidação + um encerrado.
     *
     * @return array{Tenant, CasoCobranca[]}
     */
    private function cenarioVariado(): array
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        $casoVencida = $this->caso($tenant, $carteira);
        $obrVencida = $this->obrigacao($tenant, $casoVencida, 100000, '2026-06-01'); // vencida
        $this->pagamentoAlocado($tenant, $casoVencida, $obrVencida, 20000, '2026-07-10'); // alocado
        LiquidacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $casoVencida,
            'valorReconhecido' => 10000, 'data' => new \DateTimeImmutable('2026-07-10'),
        ]);

        $casoRevisao = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $casoRevisao, 50000, '2026-09-01'); // a vencer
        RevisaoPessoaCobradaFactory::createOne(['tenant' => $tenant, 'caso' => $casoRevisao]);

        $casoAcao = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $casoAcao, 70000, '2026-06-15'); // vencida
        ProximaAcaoFactory::createOne(['tenant' => $tenant, 'caso' => $casoAcao, 'prazo' => new \DateTimeImmutable('2026-07-01')]); // atrasada

        $casoParcela = $this->caso($tenant, $carteira);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $casoParcela, 'status' => StatusAcordo::Ativo])->_real();
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $casoParcela,
            'valorOriginal' => 30000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-06-20'), 'acordoOrigem' => $acordo,
        ]); // parcela de acordo vigente, vencida

        $casoProntoEncerrar = $this->caso($tenant, $carteira);
        $obrPronto = $this->obrigacao($tenant, $casoProntoEncerrar, 40000, '2026-06-01');
        $this->pagamentoAlocado($tenant, $casoProntoEncerrar, $obrPronto, 40000, '2026-07-10'); // saldo 0 → pronto p/ encerrar

        $casoEncerrado = $this->caso($tenant, $carteira, StatusCaso::Encerrado);
        $obrEnc = $this->obrigacao($tenant, $casoEncerrado, 40000, '2026-06-01');
        $this->pagamentoAlocado($tenant, $casoEncerrado, $obrEnc, 40000, '2026-07-10');

        $this->em->clear(); // força hidratação limpa (proxies), como num request real

        return [$tenant, $this->casoRepo->doTenant($tenant)];
    }

    #[TestDox('saldosDosCasos (lote) bate, caso a caso, com saldoExigivel/saldoVencido por-caso')]
    public function testSaldosEmLoteBatemComPorCaso(): void
    {
        [$tenant, $casos] = $this->cenarioVariado();

        $emLote = $this->calcSaldo->saldosDosCasos($casos, $tenant, $this->hoje);

        self::assertCount(\count($casos), $emLote);
        foreach ($casos as $caso) {
            $id = $caso->getId();
            self::assertNotNull($id);
            self::assertSame(
                $this->calcSaldo->saldoExigivel($caso),
                $emLote[$id]['exigivel'],
                sprintf('exigivel divergente no caso %d', $id),
            );
            self::assertSame(
                $this->calcSaldo->saldoVencido($caso, $this->hoje),
                $emLote[$id]['vencido'],
                sprintf('vencido divergente no caso %d', $id),
            );
        }
    }

    #[TestDox('alertasDosCasos (lote) bate, caso a caso, com alertasDoCaso por-caso (tipos, ordem e texto)')]
    public function testAlertasEmLoteBatemComPorCaso(): void
    {
        [$tenant, $casos] = $this->cenarioVariado();

        $emLote = $this->alertas->alertasDosCasos($casos, $tenant, $this->hoje);

        self::assertCount(\count($casos), $emLote);
        foreach ($casos as $caso) {
            $id = $caso->getId();
            self::assertNotNull($id);

            $porCaso = $this->alertas->alertasDoCaso($caso, $this->hoje);

            $tiposLote = array_map(static fn ($a) => $a->tipo->value, $emLote[$id]);
            $tiposPorCaso = array_map(static fn ($a) => $a->tipo->value, $porCaso);
            self::assertSame($tiposPorCaso, $tiposLote, sprintf('tipos/ordem divergentes no caso %d', $id));

            $textosLote = array_map(static fn ($a) => $a->descricao, $emLote[$id]);
            $textosPorCaso = array_map(static fn ($a) => $a->descricao, $porCaso);
            self::assertSame($textosPorCaso, $textosLote, sprintf('descrição divergente no caso %d', $id));
        }
    }

    #[TestDox('Escopo por tenant: o lote nunca puxa saldo/alerta de outro escritório')]
    public function testEscopoPorTenant(): void
    {
        [$tenantA, $casosA] = $this->cenarioVariado();
        [, $casosB] = $this->cenarioVariado(); // outro tenant

        $saldosA = $this->calcSaldo->saldosDosCasos($casosA, $tenantA, $this->hoje);
        $alertasA = $this->alertas->alertasDosCasos($casosA, $tenantA, $this->hoje);

        // As chaves do lote são exatamente os casos do tenant A — nenhum id do tenant B aparece.
        $idsA = array_map(static fn (CasoCobranca $c) => $c->getId(), $casosA);
        $idsB = array_map(static fn (CasoCobranca $c) => $c->getId(), $casosB);
        sort($idsA);
        $chavesSaldo = array_keys($saldosA);
        sort($chavesSaldo);
        self::assertSame($idsA, $chavesSaldo);
        foreach ($idsB as $idB) {
            self::assertArrayNotHasKey($idB, $saldosA);
            self::assertArrayNotHasKey($idB, $alertasA);
        }
    }
}
