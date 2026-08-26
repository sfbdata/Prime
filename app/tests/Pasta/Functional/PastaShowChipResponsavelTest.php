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
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A pasta_show escolhe o responsável pelo MESMO chip do Expediente (avatar + nome
 * + menu com fotos), não mais pelo <select> cru que ela tinha.
 *
 * Os asserts usam seletor CSS do crawler, não assertStringContainsString: substring
 * passa com valor truncado — já aconteceu neste projeto e escondeu o defeito.
 */
#[CoversClass(PastaController::class)]
final class PastaShowChipResponsavelTest extends JusPrimeWebTestCase
{
    /** @return array{0: User, 1: Tenant, 2: Pasta, 3: User} */
    private function criarCenario(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Chip ' . uniqid());
        $em->persist($tenant);

        $admin = new User();
        $admin->setEmail('chip_admin_' . uniqid() . '@test.com');
        $admin->setFullName('Admin Do Chip');
        $admin->setRoles(['ROLE_SUPER_ADMIN']);
        $admin->setIsActive(true);
        $admin->setPassword($hasher->hashPassword($admin, 'senha123'));
        $em->persist($admin);
        $em->persist(new UserTenant($admin, $tenant));

        // Um segundo colaborador: o menu tem de oferecer mais de uma opção.
        $colega = new User();
        $colega->setEmail('chip_colega_' . uniqid() . '@test.com');
        $colega->setFullName('Colega Sem Foto');
        $colega->setIsActive(true);
        $colega->setPassword($hasher->hashPassword($colega, 'senha123'));
        $em->persist($colega);
        $em->persist(new UserTenant($colega, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('NUP-CHIP-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($admin);
        $pasta->setResponsavel($admin);
        $em->persist($pasta);

        $em->flush();

        return [$admin, $tenant, $pasta, $colega];
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    #[TestDox('pasta_show renderiza o chip de responsável dentro da aba Dados, com url, csrf e responsável atual')]
    public function testChipRenderizaNaAbaDadosComOsDadosCertos(): void
    {
        $client                          = static::createClient();
        [$admin, $tenant, $pasta, $_col] = $this->criarCenario();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // Filho da aba Dados — "existe na página" seria verdade mesmo com o chip
        // caído em qualquer outro canto do HTML.
        $chip = $crawler->filter('#dados .pasta-resp-chip');
        self::assertCount(1, $chip, 'o chip tem de estar dentro da aba Dados');

        self::assertSame(
            '/pasta/' . $pasta->getId() . '/responsavel',
            $chip->attr('data-url'),
            'o chip salva no mesmo endpoint de sempre'
        );
        // O valor do token não se afirma por igualdade: o Symfony randomiza cada
        // emissão, então comparar a string seria testar o framework, não o chip.
        // Que o token PRESTA é provado pelo round-trip em testChipSalvaComOTokenQueEleMesmoCarrega.
        self::assertNotEmpty($chip->attr('data-csrf'), 'o chip tem de levar um token CSRF');
        self::assertSame(
            (string) $admin->getId(),
            $chip->attr('data-resp-id'),
            'o chip nasce marcando quem é o responsável atual'
        );
        self::assertSame(
            $admin->getFullName(),
            trim($chip->filter('.pasta-resp-nome')->text()),
            'o chip mostra o nome do responsável atual'
        );
    }

    #[TestDox('pasta_show renderiza o menu compartilhado com uma opção por colaborador mais "Sem responsável"')]
    public function testMenuTemUmaOpcaoPorColaboradorMaisOVazio(): void
    {
        $client                            = static::createClient();
        [$admin, $tenant, $pasta, $colega] = $this->criarCenario();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $menu = $crawler->filter('#pastaRespMenu');
        self::assertCount(1, $menu, 'o menu compartilhado tem de existir na página');

        $opcoes = $menu->filter('.pasta-resp-opcao');

        // 2 colaboradores do tenant + a opção "Sem responsável".
        self::assertCount(3, $opcoes, 'uma opção por colaborador, mais a de esvaziar');

        $ids = $opcoes->each(static fn ($op) => (string) $op->attr('data-user-id'));
        self::assertContains('', $ids, 'tem de existir a opção de deixar sem responsável');
        self::assertContains((string) $admin->getId(), $ids);
        self::assertContains((string) $colega->getId(), $ids);

        $nomes = $opcoes->each(static fn ($op) => trim($op->filter('.pasta-resp-nome')->text()));
        self::assertContains($colega->getFullName(), $nomes, 'o colega aparece pelo nome no menu');

        // O menu tem de FECHAR a própria <div>. Quando ela fica aberta, o parser
        // enfia dentro dela tudo o que vem depois — o <script> do comportamento
        // inclusive — e nada disso aparece como erro: a página só fica errada.
        self::assertCount(
            0,
            $menu->filter('script'),
            'nada além das opções pode cair dentro do menu; script aqui significa <div> não fechada'
        );
        self::assertCount(
            3,
            $menu->children(),
            'o menu tem exatamente as opções como filhos diretos, nada mais'
        );
    }

    #[TestDox('o token e a url que o chip carrega salvam de verdade: trocar responsável grava no banco')]
    public function testChipSalvaComOTokenQueEleMesmoCarrega(): void
    {
        $client                            = static::createClient();
        [$admin, $tenant, $pasta, $colega] = $this->criarCenario();

        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $chip = $crawler->filter('#dados .pasta-resp-chip');
        self::assertCount(1, $chip);

        // Usa EXATAMENTE o que o chip publicou na página — é o que o JS faria.
        $client->request('PATCH', (string) $chip->attr('data-url'), [
            '_token'         => (string) $chip->attr('data-csrf'),
            'responsavel_id' => (string) $colega->getId(),
        ]);

        self::assertResponseIsSuccessful('a url + token do chip têm de ser aceitos pelo servidor');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Pasta::class, $pasta->getId());

        self::assertNotNull($recarregada);
        self::assertNotNull($recarregada->getResponsavel());
        self::assertSame(
            $colega->getId(),
            $recarregada->getResponsavel()->getId(),
            'a troca feita pelo chip tem de chegar ao banco'
        );
    }

    #[TestDox('o <select> cru de responsável não sobrevive na pasta_show')]
    public function testSelectAntigoSumiu(): void
    {
        $client                          = static::createClient();
        [$admin, $tenant, $pasta, $_col] = $this->criarCenario();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $crawler->filter('.pasta-responsavel-select'),
            'o <select> antigo foi substituído pelo chip; deixá-lo é ter dois controles para a mesma ação'
        );
    }
}
