<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\UseCase\ListarCasosUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\LiquidacaoFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Lista de Casos (Etapa 8, agora batch — P3) contra o BANCO REAL. Prova que os saldos por item da PÁGINA
 * batem com os métodos por-caso (`CalculadoraSaldo::saldosDosCasos` só troca a fonte) e que paginação,
 * faceta de status e total seguem íntegros após o fetch-join do `findByFilters`. Escopo por tenant.
 */
#[CoversClass(ListarCasosUseCase::class)]
#[CoversClass(CasoCobrancaRepository::class)]
final class ListarCasosUseCaseTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ListarCasosUseCase $sut;
    private CalculadoraSaldo $calcSaldo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var ObrigacaoRepository $obrigacaoRepo */
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        /** @var CasoCobrancaRepository $casoRepo */
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        /** @var AlocacaoPagamentoRepository $alocacaoRepo */
        $alocacaoRepo = $this->em->getRepository(AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(Liquidacao::class);

        $this->calcSaldo = new CalculadoraSaldo($obrigacaoRepo, $casoRepo, $alocacaoRepo, $liquidacaoRepo, new \App\Cobranca\Service\EncargosVivos(new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('2026-07-20')), new \App\Cobranca\Service\CalculadoraEncargos()), new \App\Cobranca\Service\ResolvedorConfigEncargos());
        $this->sut = new ListarCasosUseCase($casoRepo, $this->calcSaldo);
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant LC ' . uniqid());
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
        ])->_real();
    }

    private function obrigacao(Tenant $tenant, CasoCobranca $caso, int $valor, string $vencimento): Obrigacao
    {
        return ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'valorOriginal' => $valor, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable($vencimento),
        ])->_real();
    }

    #[TestDox('Saldos por item da página batem com o per-caso; paginação e total íntegros')]
    public function testSaldosDaPaginaEBatemComPorCaso(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        // Caso com alocação + liquidação (exercita o abatimento no lote).
        $c1 = $this->caso($tenant, $carteira);
        $o1 = $this->obrigacao($tenant, $c1, 100000, '2026-06-01');
        $pag = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $c1,
            'valorDivida' => 20000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
            'data' => new \DateTimeImmutable('2026-07-10'),
        ])->_real();
        AlocacaoPagamentoFactory::createOne(['tenant' => $tenant, 'pagamento' => $pag, 'obrigacao' => $o1, 'valor' => 20000]);
        LiquidacaoFactory::createOne(['tenant' => $tenant, 'caso' => $c1, 'valorReconhecido' => 10000, 'data' => new \DateTimeImmutable('2026-07-10')]);

        // Mais casos para forçar paginação.
        for ($i = 0; $i < 4; ++$i) {
            $c = $this->caso($tenant, $carteira);
            $this->obrigacao($tenant, $c, 50000, '2026-12-01');
        }

        $this->em->clear();

        // Página de 3 (de 5 casos) — total deve ser 5.
        $resultado = $this->sut->executar($tenant, [], 1, 3, 'criacao', 'asc');
        self::assertSame(5, $resultado['total']);
        self::assertCount(3, $resultado['itens']);

        // Cada item da página bate com o saldo per-caso.
        $repo = $this->em->getRepository(CasoCobranca::class);
        foreach ($resultado['itens'] as $item) {
            $caso = $repo->findOneByIdDoTenant($item->id, $tenant);
            self::assertNotNull($caso);
            self::assertSame($this->calcSaldo->saldoExigivel($caso), $item->saldoExigivel, sprintf('exigivel caso %d', $item->id));
            self::assertSame($this->calcSaldo->saldoVencido($caso), $item->saldoVencido, sprintf('vencido caso %d', $item->id));
        }
    }

    #[TestDox('Faceta de status e escopo por tenant íntegros após o fetch-join')]
    public function testFacetaStatusETenant(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $ativo = $this->caso($tenant, $carteira, StatusCaso::Ativo);
        $this->obrigacao($tenant, $ativo, 100000, '2026-06-01');
        $encerrado = $this->caso($tenant, $carteira, StatusCaso::Encerrado);
        $this->obrigacao($tenant, $encerrado, 40000, '2026-06-01');

        // Outro tenant não deve aparecer.
        $outro = $this->tenant();
        $carteiraOutro = $this->carteira($outro);
        $this->obrigacao($outro, $this->caso($outro, $carteiraOutro), 900000, '2026-06-01');

        $this->em->clear();

        $todos = $this->sut->executar($tenant, [], 1, 20, 'criacao', 'asc');
        self::assertSame(2, $todos['total']);

        $soAtivos = $this->sut->executar($tenant, ['status' => StatusCaso::Ativo->value], 1, 20, 'criacao', 'asc');
        self::assertSame(1, $soAtivos['total']);
        self::assertCount(1, $soAtivos['itens']);
        self::assertSame($ativo->getId(), $soAtivos['itens'][0]->id);
    }
}
