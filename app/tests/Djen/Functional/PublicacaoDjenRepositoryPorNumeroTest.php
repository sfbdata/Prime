<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A consulta que alimenta a aba Push Processual da pasta: as publicações do escritório cujos
 * números CNJ estão na lista dos processos daquela pasta.
 *
 * Casa por NÚMERO, e não pela FK `processo`, de propósito. A FK só é gravada durante a
 * sincronização; publicação captada ANTES de o processo existir no cadastro fica com
 * `processo_id` nulo para sempre. Em produção, 8 das 59 publicações que pertencem a uma pasta
 * estavam nesse estado — todas com o processo criado depois da captura.
 *
 * O TenantFilter global NÃO está ligado aqui (quem o liga é um listener de kernel.request, e isto
 * é um KernelTestCase sem request): o isolamento que estes testes provam é o `andWhere` explícito
 * do repositório.
 */
#[CoversClass(PublicacaoDjenRepository::class)]
#[Group('djen')]
final class PublicacaoDjenRepositoryPorNumeroTest extends KernelTestCase
{
    use Factories;

    private PublicacaoDjenRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(PublicacaoDjenRepository::class);
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Lista as publicações dos números pedidos, da mais recente para a mais antiga')]
    public function testListaOrdenadaDaMaisRecente(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $this->criarPublicacao($tenant, '10000001', '07011111111111111111', '2026-07-10');
        $this->criarPublicacao($tenant, '10000002', '07011111111111111111', '2026-08-20');
        $this->criarPublicacao($tenant, '10000003', '07022222222222222222', '2026-08-01');

        $itens = $this->repo->listarItensPorNumerosDoTenant($tenant, ['07011111111111111111', '07022222222222222222']);

        self::assertCount(3, $itens);
        self::assertSame(['20/08/2026', '01/08/2026', '10/07/2026'], array_map(
            static fn ($i): ?string => $i->dataDisponibilizacao,
            $itens,
        ));
    }

    #[TestDox('Número com máscara na entrada casa com o só-dígitos gravado na publicação')]
    public function testNumeroComMascaraCasa(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $this->criarPublicacao($tenant, '10000010', '07172226820248070020', '2026-08-28');

        $itens = $this->repo->listarItensPorNumerosDoTenant($tenant, ['0717222-68.2024.8.07.0020']);

        self::assertCount(1, $itens);
    }

    #[TestDox('Publicação AVULSA (sem FK de processo) com o número da pasta aparece — é o caso dos 8 de produção')]
    public function testAvulsaComNumeroDaPastaAparece(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $numero = '07269836520248070007';
        $avulsa = $this->criarPublicacao($tenant, '10000020', $numero, '2026-07-21');
        self::assertNull($avulsa->getProcesso(), 'a publicação precisa nascer sem FK para o teste valer');

        $itens = $this->repo->listarItensPorNumerosDoTenant($tenant, [$numero]);

        self::assertCount(1, $itens);
        self::assertSame((int) $avulsa->getId(), $itens[0]->id);
    }

    #[TestDox('Lista de números vazia devolve lista vazia (pasta sem processo vinculado)')]
    public function testNumerosVaziosDevolveVazio(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $this->criarPublicacao($tenant, '10000030', '07011111111111111111', '2026-08-20');

        self::assertSame([], $this->repo->listarItensPorNumerosDoTenant($tenant, []));
        self::assertSame([], $this->repo->listarItensPorNumerosDoTenant($tenant, ['', '   ', 'abc']));
    }

    #[TestDox('Publicação de outro processo do mesmo escritório não entra na lista')]
    public function testNaoDevolveNumeroForaDaLista(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $this->criarPublicacao($tenant, '10000040', '07011111111111111111', '2026-08-20');
        $this->criarPublicacao($tenant, '10000041', '07099999999999999999', '2026-08-21');

        $itens = $this->repo->listarItensPorNumerosDoTenant($tenant, ['07011111111111111111']);

        self::assertCount(1, $itens);
    }

    #[TestDox('Mesmo número CNJ em outro escritório não vaza para a lista deste')]
    public function testNaoVazaEntreEscritorios(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $numero  = '07011111111111111111';
        $this->criarPublicacao($tenantA, '10000050', $numero, '2026-08-20');
        $this->criarPublicacao($tenantB, '10000051', $numero, '2026-08-21');

        $itens = $this->repo->listarItensPorNumerosDoTenant($tenantA, [$numero]);

        self::assertCount(1, $itens);
        self::assertSame('20/08/2026', $itens[0]->dataDisponibilizacao);
    }

    #[TestDox('O limite corta as mais antigas, nunca as recentes')]
    public function testLimiteCortaAsMaisAntigas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $numero = '07011111111111111111';
        $this->criarPublicacao($tenant, '10000060', $numero, '2026-07-01');
        $this->criarPublicacao($tenant, '10000061', $numero, '2026-08-01');

        $itens = $this->repo->listarItensPorNumerosDoTenant($tenant, [$numero], 1);

        self::assertCount(1, $itens);
        self::assertSame('01/08/2026', $itens[0]->dataDisponibilizacao);
    }

    #[TestDox('findOneByIdENumerosDoTenant devolve a publicação quando o número está na lista da pasta')]
    public function testAchaPorIdQuandoONumeroPertenceAPasta(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $numero = '07011111111111111111';
        $pub    = $this->criarPublicacao($tenant, '10000070', $numero, '2026-08-20');

        $achada = $this->repo->findOneByIdENumerosDoTenant((int) $pub->getId(), $tenant, [$numero]);

        self::assertNotNull($achada);
        self::assertSame((int) $pub->getId(), (int) $achada->getId());
    }

    #[TestDox('findOneByIdENumerosDoTenant recusa publicação de processo que não é da pasta (IDOR)')]
    public function testNaoAchaPorIdQuandoONumeroNaoEDaPasta(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pub    = $this->criarPublicacao($tenant, '10000080', '07099999999999999999', '2026-08-20');

        $achada = $this->repo->findOneByIdENumerosDoTenant((int) $pub->getId(), $tenant, ['07011111111111111111']);

        self::assertNull($achada);
    }

    #[TestDox('findOneByIdENumerosDoTenant recusa publicação de outro escritório mesmo com o número certo')]
    public function testNaoAchaPorIdCrossTenant(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $numero  = '07011111111111111111';
        $pubB    = $this->criarPublicacao($tenantB, '10000090', $numero, '2026-08-20');

        $achada = $this->repo->findOneByIdENumerosDoTenant((int) $pubB->getId(), $tenantA, [$numero]);

        self::assertNull($achada);
    }

    #[TestDox('A lista sai com o número mascarado quando a publicação o trouxe do CNJ')]
    public function testExibeNumeroMascaradoQuandoExiste(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pub = $this->criarPublicacao($tenant, '10000100', '07172226820248070020', '2026-08-28');
        $pub->setNumeroProcessoComMascara('0717222-68.2024.8.07.0020');
        $this->em->flush();

        $itens = $this->repo->listarItensPorNumerosDoTenant($tenant, ['07172226820248070020']);

        self::assertSame('0717222-68.2024.8.07.0020', $itens[0]->numeroProcessoExibicao);
    }

    private function criarPublicacao(Tenant $tenant, string $djenId, string $numero, string $data, ?Processo $processo = null): PublicacaoDjen
    {
        $pub = new PublicacaoDjen();
        $pub->setTenant($tenant);
        $pub->setDjenId($djenId);
        $pub->setNumeroProcesso($numero);
        $pub->setSiglaTribunal('TJDFT');
        $pub->setTipoComunicacao('Intimação');
        $pub->setNomeOrgao('1ª Vara Cível');
        $pub->setDataDisponibilizacao(new \DateTimeImmutable($data));
        $pub->setTexto('Teor da publicação ' . $djenId);
        if ($processo !== null) {
            $pub->setProcesso($processo);
        }
        $this->em->persist($pub);
        $this->em->flush();

        return $pub;
    }
}
