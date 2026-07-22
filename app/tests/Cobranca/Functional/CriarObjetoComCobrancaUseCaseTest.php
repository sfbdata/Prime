<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\CriarObjetoComCobrancaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Fatia 3 do ajuste 2: criar um objeto já cria a cobrança. Contra o BANCO REAL, prova que
 * `CriarObjetoComCobrancaUseCase` — recebendo só o NOME do cobrado — orquestra Pessoa (enxuta) + Objeto +
 * Caso âncora (honorários herdados da carteira) + Vínculo numa passada, e que carteira de outro
 * escritório é rejeitada. Integração porque o UseCase compõe outros UseCases `final`.
 */
#[CoversClass(CriarObjetoComCobrancaUseCase::class)]
final class CriarObjetoComCobrancaUseCaseTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private CriarObjetoComCobrancaUseCase $sut;
    private CasoCobrancaRepository $casoRepo;
    private VinculoPessoaObjetoRepository $vinculoRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->sut = static::getContainer()->get(CriarObjetoComCobrancaUseCase::class);
        $this->casoRepo = $this->em->getRepository(CasoCobranca::class);
        $this->vinculoRepo = $this->em->getRepository(VinculoPessoaObjeto::class);
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant CO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function user(): User
    {
        $user = new User();
        $user->setEmail('co_' . uniqid() . '@test.com');
        $user->setFullName('User CO');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    #[TestDox('criar objeto com o nome do cobrado cria Pessoa + Caso âncora + Vínculo')]
    public function testCriaObjetoComCasoEVinculoAPartirDoNome(): void
    {
        $tenant = $this->tenant();
        $user = $this->user();
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '10.00',
            'tipoVinculoPreferido' => TipoVinculo::Proprietario,
        ])->_real();

        $input = new CriarObjetoInput();
        $input->carteiraId = $carteira->getId();
        $input->identificacao = 'Apto 402';
        $input->nomeCobrado = 'Fulano da Silva';

        $objeto = $this->sut->executar($input, $tenant, $user);

        self::assertSame('Apto 402', $objeto->getIdentificacao());
        self::assertSame($carteira->getId(), $objeto->getCarteira()?->getId());

        $caso = $this->casoRepo->casoAncoraDoObjeto($objeto);
        self::assertNotNull($caso);
        self::assertSame(StatusCaso::Ativo, $caso->getStatus());
        self::assertSame('Fulano da Silva', $caso->getPessoaCobradaAtual()?->getNome());
        // #9-T2: o caso NÃO fotografa mais a config da carteira — a coluna nasce no default morto.
        self::assertSame(FormaHonorarios::SemPercentual, $caso->getFormaHonorarios(), 'snapshot morto: o caso nasce sem cópia da config da carteira');
        self::assertNull($caso->getPercentualHonorarios());
        // A alíquota efetiva cascateia AO VIVO via objeto/carteira (T1), sem UPDATE nenhum no caso.
        self::assertSame(1000, (new ResolvedorConfigEncargos())->resolverDoCaso($caso)->taxaHonorariosBp, 'honorários herdados da carteira via objeto (ao vivo)');

        $vinculos = $this->vinculoRepo->todosDoObjetoComPessoa($objeto);
        self::assertCount(1, $vinculos);
        self::assertSame('Fulano da Silva', $vinculos[0]->getPessoa()?->getNome());
        self::assertSame(TipoVinculo::Proprietario, $vinculos[0]->getTipoVinculo());
    }

    #[TestDox('carteira de outro escritório é rejeitada, nada é criado')]
    public function testRejeitaCarteiraInexistenteNoTenant(): void
    {
        $tenant = $this->tenant();
        $user = $this->user();

        $input = new CriarObjetoInput();
        $input->carteiraId = 999999;
        $input->identificacao = 'Apto 402';
        $input->nomeCobrado = 'Fulano';

        $this->expectException(CarteiraNaoEncontradaException::class);
        $this->sut->executar($input, $tenant, $user);
    }
}
