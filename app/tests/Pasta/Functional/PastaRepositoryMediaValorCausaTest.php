<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A "Média por CPF" da aba Financeiro: a média do valor da causa de todas as
 * pastas daquele cliente. É o ticket médio do cliente, não uma divisão do valor
 * de uma pasta.
 */
#[CoversClass(PastaRepository::class)]
#[Group('pasta')]
final class PastaRepositoryMediaValorCausaTest extends KernelTestCase
{
    use Factories;

    private PastaRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(PastaRepository::class);
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<int, ?string> $valores um valor por pasta; null = pasta sem valor preenchido
     */
    private function criarPastasDoCliente(
        object $cliente,
        object $tenant,
        array $valores,
        string $situacao = Pasta::SITUACAO_ATIVA,
    ): void {
        foreach ($valores as $valor) {
            $pasta = PastaFactory::createOne([
                'tenant'     => $tenant,
                'situacao'   => $situacao,
                'valorCausa' => $valor,
            ])->_real();
            $pasta->addCliente($cliente);
        }

        $this->em->flush();
    }

    #[TestDox('média soma as pastas do cliente e divide pela quantidade delas')]
    public function testMediaDasPastasDoCliente(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();

        $this->criarPastasDoCliente($cliente, $tenant, ['10000.00', '20000.00', '30000.00']);

        self::assertSame('20000.00', $this->repo->mediaValorCausaPorCliente($cliente, $tenant));
    }

    #[TestDox('pasta sem valor preenchido fica fora da conta e não puxa a média para baixo')]
    public function testPastaSemValorNaoEntraNaMedia(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();

        $this->criarPastasDoCliente($cliente, $tenant, ['10000.00', '20000.00', null]);

        self::assertSame(
            '15000.00',
            $this->repo->mediaValorCausaPorCliente($cliente, $tenant),
            'a pasta sem valor não pode contar como zero — seriam R$ 10.000,00'
        );
    }

    #[TestDox('pasta arquivada continua contando: arquivar não apaga o histórico do cliente')]
    public function testPastaArquivadaEntraNaMedia(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();

        $this->criarPastasDoCliente($cliente, $tenant, ['10000.00']);
        $this->criarPastasDoCliente($cliente, $tenant, ['30000.00'], Pasta::SITUACAO_ARQUIVADA);

        self::assertSame('20000.00', $this->repo->mediaValorCausaPorCliente($cliente, $tenant));
    }

    #[TestDox('pasta de outro cliente não entra na média deste')]
    public function testPastaDeOutroClienteNaoEntra(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();
        $outro   = ClientePFFactory::createOne(['tenant' => $tenant])->_real();

        $this->criarPastasDoCliente($cliente, $tenant, ['10000.00']);
        $this->criarPastasDoCliente($outro, $tenant, ['90000.00']);

        self::assertSame('10000.00', $this->repo->mediaValorCausaPorCliente($cliente, $tenant));
    }

    #[TestDox('pasta de outro escritório não entra na média — isolamento multi-tenant')]
    public function testNaoVazaEntreTenants(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenantA])->_real();

        $this->criarPastasDoCliente($cliente, $tenantA, ['10000.00']);
        $this->criarPastasDoCliente($cliente, $tenantB, ['90000.00']);

        self::assertSame(
            '10000.00',
            $this->repo->mediaValorCausaPorCliente($cliente, $tenantA),
            'a média do escritório A não pode enxergar pasta do escritório B'
        );
    }

    #[TestDox('cliente sem nenhuma pasta com valor devolve nulo, não zero')]
    public function testSemValorPreenchidoDevolveNulo(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();

        $this->criarPastasDoCliente($cliente, $tenant, [null, null]);

        self::assertNull(
            $this->repo->mediaValorCausaPorCliente($cliente, $tenant),
            'sem dado a tela mostra travessão; R$ 0,00 seria um número inventado'
        );
    }

    #[TestDox('cliente sem pasta nenhuma devolve nulo')]
    public function testClienteSemPastaDevolveNulo(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();

        self::assertNull($this->repo->mediaValorCausaPorCliente($cliente, $tenant));
    }

    #[TestDox('a média arredonda para duas casas em vez de devolver dízima')]
    public function testMediaArredondaParaDuasCasas(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();

        $this->criarPastasDoCliente($cliente, $tenant, ['10000.00', '10000.00', '10000.01']);

        self::assertSame('10000.00', $this->repo->mediaValorCausaPorCliente($cliente, $tenant));
    }
}
