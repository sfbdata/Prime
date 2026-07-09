<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\EncerrarVinculoInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\VinculoNaoEncontradoException;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\UseCase\EncerrarVinculoUseCase;
use App\Cobranca\UseCase\SugerirPessoasDuplicadasUseCase;
use App\Cobranca\UseCase\VincularPessoaAObjetoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Isolamento multi-tenant do núcleo de cadastro de Cobranças, exercitado contra o BANCO REAL
 * (não mocks). Roda em KernelTestCase → o TenantFilter fica DESLIGADO, igual ao CLI; portanto
 * estes testes provam a guarda EXPLÍCITA dos UseCases (`findOneByIdDoTenant`), não a rede de
 * segurança do filtro. As entidades são semeadas com o MESMO tenant nas três pontas
 * (pessoa/objeto/vínculo) e depois uma delas é divergida para outro escritório, provando a
 * rejeição — sem confiar nos defaults das factories (invariável 1/24; achado I1 do andaime).
 */
#[CoversClass(VincularPessoaAObjetoUseCase::class)]
#[CoversClass(EncerrarVinculoUseCase::class)]
#[CoversClass(SugerirPessoasDuplicadasUseCase::class)]
final class CobrancaIsolamentoTenantTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private VincularPessoaAObjetoUseCase $vincular;
    private EncerrarVinculoUseCase $encerrar;
    private SugerirPessoasDuplicadasUseCase $sugerir;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);

        // Os UseCases ainda não têm controller que os referencie, então o container os inlina
        // (serviço privado). Instancio-os direto com os repositórios REAIS do EM — mesmo banco,
        // mesmas queries tenant-safe, sem precisar torná-los públicos.
        /** @var VinculoPessoaObjetoRepository $vinculoRepo */
        $vinculoRepo = $this->em->getRepository(VinculoPessoaObjeto::class);
        /** @var PessoaRepository $pessoaRepo */
        $pessoaRepo = $this->em->getRepository(Pessoa::class);
        /** @var ObjetoCobrancaRepository $objetoRepo */
        $objetoRepo = $this->em->getRepository(ObjetoCobranca::class);

        $this->vincular = new VincularPessoaAObjetoUseCase($vinculoRepo, $pessoaRepo, $objetoRepo);
        $this->encerrar = new EncerrarVinculoUseCase($vinculoRepo);
        $this->sugerir = new SugerirPessoasDuplicadasUseCase($pessoaRepo);
    }

    #[TestDox('Vincula quando pessoa e objeto são do mesmo escritório (nasce aberto)')]
    public function testVinculaQuandoMesmoTenant(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $this->criarCarteira($tenant)]);

        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = (int) $pessoa->getId();
        $input->objetoId = (int) $objeto->getId();
        $input->tipoVinculo = TipoVinculo::Proprietario;

        $vinculo = $this->vincular->executar($input, $tenant, $user);

        self::assertNotNull($vinculo->getId(), 'o vínculo deve ter sido persistido');
        self::assertTrue($vinculo->estaAberto(), 'vínculo novo nasce sem data de encerramento');
        self::assertSame($pessoa->getId(), $vinculo->getPessoa()?->getId());
        self::assertSame($objeto->getId(), $vinculo->getObjeto()?->getId());
        self::assertSame($tenant->getId(), $vinculo->getTenant()?->getId());
    }

    #[TestDox('Rejeita vincular OBJETO de outro escritório (guarda same-tenant do objeto)')]
    public function testRejeitaObjetoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();

        // Pessoa no A, objeto no B: com o tenant do usuário = A, o objeto de B não é encontrado.
        $pessoaA = PessoaFactory::createOne(['tenant' => $tenantA]);
        $objetoB = ObjetoCobrancaFactory::createOne(['tenant' => $tenantB, 'carteira' => $this->criarCarteira($tenantB)]);

        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = (int) $pessoaA->getId();
        $input->objetoId = (int) $objetoB->getId();
        $input->tipoVinculo = TipoVinculo::Inquilino;

        $this->expectException(ObjetoNaoEncontradoException::class);

        $this->vincular->executar($input, $tenantA, $user);
    }

    #[TestDox('Rejeita vincular PESSOA de outro escritório (guarda same-tenant da pessoa)')]
    public function testRejeitaPessoaDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();

        // Objeto no A, pessoa no B: com o tenant do usuário = A, a pessoa de B não é encontrada.
        $objetoA = ObjetoCobrancaFactory::createOne(['tenant' => $tenantA, 'carteira' => $this->criarCarteira($tenantA)]);
        $pessoaB = PessoaFactory::createOne(['tenant' => $tenantB]);

        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = (int) $pessoaB->getId();
        $input->objetoId = (int) $objetoA->getId();
        $input->tipoVinculo = TipoVinculo::Proprietario;

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->vincular->executar($input, $tenantA, $user);
    }

    #[TestDox('Encerrar não alcança vínculo de outro escritório (IDOR fechado por tenant)')]
    public function testEncerrarNaoAlcancaVinculoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        // Vínculo pertence ao A; tentativa de encerrá-lo com o tenant B deve falhar (não encontrado).
        $pessoaA = PessoaFactory::createOne(['tenant' => $tenantA]);
        $objetoA = ObjetoCobrancaFactory::createOne(['tenant' => $tenantA, 'carteira' => $this->criarCarteira($tenantA)]);
        $vinculoA = VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenantA,
            'pessoa' => $pessoaA,
            'objeto' => $objetoA,
        ]);

        $input = new EncerrarVinculoInput();
        $input->vinculoId = (int) $vinculoA->getId();
        $input->motivoEncerramento = 'Tentativa cross-tenant';

        $this->expectException(VinculoNaoEncontradoException::class);

        $this->encerrar->executar($input, $tenantB);
    }

    #[TestDox('Sugestão de duplicadas nunca atravessa tenant e casa por dígitos (CPF formatado x cru)')]
    public function testSugestaoDeDuplicadasNaoAtravessaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        // Mesmo CPF (dígitos iguais, formatação diferente) em dois escritórios distintos.
        $pessoaA = PessoaFactory::createOne(['tenant' => $tenantA, 'cpf' => '111.222.333-44']);
        PessoaFactory::createOne(['tenant' => $tenantB, 'cpf' => '11122233344']);

        // Busca no A, informando o CPF cru: só a pessoa do A pode ser sugerida.
        $sugeridasA = $this->sugerir->executar($tenantA, '11122233344', null);

        self::assertCount(1, $sugeridasA, 'a dedup vazou entre escritórios ou não casou por dígitos');
        self::assertSame($pessoaA->getId(), $sugeridasA[0]->getId());

        // Busca no B, com o CPF formatado: casa a pessoa do B (normalização simétrica) e não a do A.
        $sugeridasB = $this->sugerir->executar($tenantB, '111.222.333-44', null);

        self::assertCount(1, $sugeridasB);
        self::assertSame($tenantB->getId(), $sugeridasB[0]->getTenant()?->getId());
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant COBRANCA ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('cobranca_' . uniqid() . '@test.com');
        $user->setFullName('User Cobrança');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Carteira consistente com o tenant (cliente credor no MESMO escritório). */
    private function criarCarteira(Tenant $tenant): object
    {
        return CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
        ]);
    }
}
