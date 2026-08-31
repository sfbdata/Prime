<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * As setas ‹ › do cabeçalho da pasta na TELA: onde ficam e para onde levam.
 *
 * O combinador de FILHO DIRETO é o que distingue "está na linha da trilha" de
 * "existe em algum lugar da página" — a diferença que uma suíte verde já deixou
 * passar neste projeto com o layout visivelmente quebrado.
 */
#[CoversClass(PastaController::class)]
final class PastaNavegacaoSetasTelaTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Setas ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_setas_' . uniqid() . '@test.com');
        $user->setFullName('Admin Setas');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $nup): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    #[TestDox('as setas ficam na linha da trilha, não perdidas na página')]
    public function testSetasFicamNaLinhaDaTrilha(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->criarPasta($tenant, '2001');
        $meio = $this->criarPasta($tenant, '2002');
        $this->criarPasta($tenant, '2003');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$meio->getId()}");
        self::assertResponseIsSuccessful();

        self::assertSame(
            1,
            $crawler->filter('.ps-cab-linha1 > .ps-cab-nav')->count(),
            'a navegação entre pastas saiu da linha da trilha'
        );
        self::assertSame(
            2,
            $crawler->filter('.ps-cab-linha1 > .ps-cab-nav > [data-nav]')->count(),
            'a linha da trilha não tem as duas setas'
        );
    }

    #[TestDox('no meio do acervo as duas setas são links para as pastas vizinhas')]
    public function testSetasApontamParaAsVizinhas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $antes = $this->criarPasta($tenant, '2001');
        $meio  = $this->criarPasta($tenant, '2002');
        $apos  = $this->criarPasta($tenant, '2003');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$meio->getId()}");

        $anterior = $crawler->filter('.ps-cab-nav a[data-nav="anterior"]');
        $proxima  = $crawler->filter('.ps-cab-nav a[data-nav="proxima"]');

        self::assertSame("/pasta/{$apos->getId()}", $anterior->attr('href'), '‹ deve subir para o número maior');
        self::assertSame("/pasta/{$antes->getId()}", $proxima->attr('href'), '› deve descer para o número menor');
    }

    /**
     * Seta que não diz para onde leva obriga a clicar para descobrir — e "anterior", num
     * acervo ordenado do maior para o menor, é ambíguo sem o número ao lado.
     */
    #[TestDox('o rótulo da seta diz o número da pasta de destino')]
    public function testRotuloDizONumeroDeDestino(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->criarPasta($tenant, '2001');
        $meio = $this->criarPasta($tenant, '2002');
        $this->criarPasta($tenant, '2003');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$meio->getId()}");

        self::assertSame(
            'Pasta anterior na lista: 2003',
            $crawler->filter('.ps-cab-nav [data-nav="anterior"]')->attr('title')
        );
        self::assertSame(
            'Próxima pasta na lista: 2001',
            $crawler->filter('.ps-cab-nav [data-nav="proxima"]')->attr('title')
        );
    }

    #[TestDox('na ponta do acervo a seta continua visível, inerte e sem link')]
    public function testPontaDoAcervoTemSetaInerte(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->criarPasta($tenant, '2001');
        $topo = $this->criarPasta($tenant, '2002');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$topo->getId()}");

        // Continua no lugar: sumir faria o par de botões mudar de largura de pasta para pasta.
        self::assertSame(
            2,
            $crawler->filter('.ps-cab-nav > [data-nav]')->count(),
            'a seta da ponta sumiu em vez de ficar inerte'
        );
        self::assertSame(
            0,
            $crawler->filter('.ps-cab-nav a[data-nav="anterior"]')->count(),
            'a pasta de número maior não pode ter link para uma anterior'
        );

        $inerte = $crawler->filter('.ps-cab-nav span[data-nav="anterior"]');
        self::assertSame(1, $inerte->count());
        self::assertSame('true', $inerte->attr('aria-disabled'));
        self::assertSame('Esta é a primeira pasta do acervo', $inerte->attr('title'));
    }

    #[TestDox('pasta sozinha no escritório mostra as duas setas inertes')]
    public function testPastaSozinhaTemAsDuasSetasInertes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $unica = $this->criarPasta($tenant, '2001');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$unica->getId()}");

        self::assertSame(0, $crawler->filter('.ps-cab-nav a')->count(), 'não há para onde navegar');
        self::assertSame(2, $crawler->filter('.ps-cab-nav span[data-nav]')->count());
    }

    /**
     * Isolamento na tela: a pasta 2002 do escritório vizinho está exatamente entre as duas
     * deste, e é o destino que uma consulta sem filtro de tenant escolheria.
     */
    #[TestDox('a seta nunca aponta para pasta de outro escritório')]
    public function testSetaNaoAtravessaEscritorios(): void
    {
        $client            = static::createClient();
        [$user, $tenant]   = $this->criarUsuarioAdmin();
        [, $tenantVizinho] = $this->criarUsuarioAdmin();

        $minha2001 = $this->criarPasta($tenant, '2001');
        $minha2003 = $this->criarPasta($tenant, '2003');
        $alheia    = $this->criarPasta($tenantVizinho, '2002');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$minha2001->getId()}");

        $anterior = $crawler->filter('.ps-cab-nav a[data-nav="anterior"]');
        self::assertSame("/pasta/{$minha2003->getId()}", $anterior->attr('href'));
        self::assertStringNotContainsString(
            "/pasta/{$alheia->getId()}",
            $crawler->filter('.ps-cab-nav')->html(),
            'a pasta do escritório vizinho vazou para a navegação'
        );
    }
}
