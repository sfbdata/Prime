<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Repository\AcordoRepository;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Dedup de `findOnePorNumeroExternoNaCarteira` contra o BANCO REAL (correção pós-taxa #7, item
 * Importante #1): o índice `idx_cobranca_acordo_tenant_numero_externo` (`tenant_id, numero_externo`)
 * NÃO é único — a carteira do acordo é derivada via `caso → objeto → carteira`, não desnormalizada,
 * então não dá pra declarar unicidade de (carteira + número) direto no banco. Dado sujo pode produzir
 * 2 acordos com o mesmo (carteira + número) no mesmo tenant; a leitura tem de ser TOLERANTE (nunca
 * lançar `NonUniqueResultException`, que abortaria o lote de import inteiro — dinheiro) e
 * DETERMINÍSTICA (sempre o mesmo resultado: o acordo mais recente).
 */
#[CoversClass(AcordoRepository::class)]
final class AcordoRepositoryDedupTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private AcordoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var AcordoRepository $repo */
        $repo = $this->em->getRepository(Acordo::class);
        $this->repo = $repo;
    }

    #[Test]
    #[TestDox('2 acordos com o mesmo (carteira+número) por dado sujo: retorna o MAIS RECENTE sem lançar')]
    public function duasLinhasComMesmoNumeroNaCarteiraRetornaAMaisRecenteSemLancar(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira);

        $antigo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'numeroExterno' => 77])->_real();
        $recente = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'numeroExterno' => 77])->_real();
        self::assertGreaterThan((int) $antigo->getId(), (int) $recente->getId(), 'sanity: o 2º criado tem id maior');

        $achado = $this->repo->findOnePorNumeroExternoNaCarteira(77, $carteira, $tenant);

        self::assertNotNull($achado, 'não lança NonUniqueResultException nem devolve null com dado sujo');
        self::assertSame($recente->getId(), $achado->getId(), 'pega o mais recente (id DESC)');
    }

    #[Test]
    #[TestDox('Sem duplicata, o dedup normal continua funcionando (regressão)')]
    public function semDuplicataRetornaOAcordoUnico(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira);

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'numeroExterno' => 42])->_real();

        $achado = $this->repo->findOnePorNumeroExternoNaCarteira(42, $carteira, $tenant);

        self::assertNotNull($achado);
        self::assertSame($acordo->getId(), $achado->getId());
    }

    #[Test]
    #[TestDox('Número inexistente na carteira retorna null')]
    public function numeroInexistenteRetornaNull(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        self::assertNull($this->repo->findOnePorNumeroExternoNaCarteira(999, $carteira, $tenant));
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant AcordoDedup ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function carteira(Tenant $tenant): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        return CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();
    }

    private function caso(Tenant $tenant, Carteira $carteira): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
        ])->_real();
    }
}
