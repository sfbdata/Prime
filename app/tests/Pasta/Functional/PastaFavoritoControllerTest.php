<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaFavoritoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaFavorita;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Fixar nos favoritos" (`pasta_favorito_alternar`, menu ⋮ da pasta, desenho 1.2.3).
 *
 * Guardas: pasta do escritório (404), CSRF (403), permissão de VER a pasta (403). O cross-tenant
 * usa um SUPER_ADMIN de propósito — ele passa por `canAccessResource` em qualquer pasta, então o
 * 404 só pode vir da conferência de dono; e o teste da permissão usa o par "sem nada" (403) ×
 * "só ver pasta" (200) no MESMO escritório, para o 403 não ser outra barreira.
 */
#[CoversClass(PastaFavoritoController::class)]
#[Group('pasta')]
final class PastaFavoritoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function alternar(KernelBrowser $client, Pasta $pasta, ?string $token = null): void
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/favorito', [
            '_token' => $token ?? 'TOKEN_pasta_favorito_' . $pasta->getId(),
        ]);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function favoritosNoBanco(Pasta $pasta, ?User $usuario = null): int
    {
        $sql    = 'SELECT COUNT(*) FROM pasta_favorita WHERE pasta_id = :pasta';
        $params = ['pasta' => $pasta->getId()];
        if ($usuario !== null) {
            $sql .= ' AND user_id = :usuario';
            $params['usuario'] = $usuario->getId();
        }

        return (int) $this->em()->getConnection()->fetchOne($sql, $params);
    }

    #[TestDox('O primeiro POST fixa a pasta nos favoritos (favorita: true); o segundo tira (favorita: false)')]
    public function testAlternaIdaEVolta(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->alternar($client, $pasta);
        self::assertResponseIsSuccessful();
        self::assertSame(['favorita' => true], $this->json($client));
        self::assertSame(1, $this->favoritosNoBanco($pasta, $user));

        $this->alternar($client, $pasta);
        self::assertResponseIsSuccessful();
        self::assertSame(['favorita' => false], $this->json($client));
        self::assertSame(0, $this->favoritosNoBanco($pasta, $user));
    }

    #[TestDox('O favorito grava o escritório da pasta e o usuário logado')]
    public function testGravaTenantEUsuario(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->alternar($client, $pasta);
        self::assertResponseIsSuccessful();

        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT tenant_id, user_id FROM pasta_favorita WHERE pasta_id = :pasta',
            ['pasta' => $pasta->getId()],
        );
        self::assertSame(
            ['tenant_id' => $tenant->getId(), 'user_id' => $user->getId()],
            ['tenant_id' => (int) $linha['tenant_id'], 'user_id' => (int) $linha['user_id']],
        );
    }

    #[TestDox('Token CSRF inválido dá 403 e não grava nada')]
    public function testCsrfInvalido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->alternar($client, $pasta, 'token_invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->favoritosNoBanco($pasta));
    }

    #[TestDox('Pasta de OUTRO escritório dá 404, mesmo para quem passa em qualquer permissão, e não grava nada')]
    public function testOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);
        $this->alternar($client, $pastaB);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->favoritosNoBanco($pastaB));
    }

    #[TestDox('Usuário do MESMO escritório sem permissão de ver a pasta leva 403 e não grava nada')]
    public function testSemPermissaoDeVerDa403(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $semNada    = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta      = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $semNada, $tenant);
        $this->alternar($client, $pasta);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->favoritosNoBanco($pasta));
    }

    #[TestDox('Usuário comum que só pode VER a pasta consegue fixá-la — o 403 acima é a permissão, não outra barreira')]
    public function testQuemSoVeConsegueFavoritar(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $leitor     = $this->criarUsuarioSemPermissaoDoModulo($tenant); // tem resources.pasta.view
        $pasta      = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $leitor, $tenant);
        $this->alternar($client, $pasta);

        self::assertResponseIsSuccessful();
        self::assertSame(['favorita' => true], $this->json($client));
        self::assertSame(1, $this->favoritosNoBanco($pasta, $leitor));
    }

    #[TestDox('O favorito de um colega não liga nem desliga o meu: cada usuário tem a sua linha')]
    public function testFavoritoEhPorUsuario(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $this->em()->persist(new PastaFavorita($tenant, $colega, $pasta));
        $this->em()->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->alternar($client, $pasta);

        self::assertResponseIsSuccessful();
        self::assertSame(['favorita' => true], $this->json($client), 'a linha do colega não conta como minha');
        self::assertSame(2, $this->favoritosNoBanco($pasta));
    }

    #[TestDox('Menu ⋮ da pasta: "Fixar nos favoritos" mostra o interruptor ligado só para quem fixou')]
    public function testMenuMostraOEstadoDoUsuario(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $minha           = $this->criarPasta($tenant);
        $doColega        = $this->criarPasta($tenant);
        $this->em()->persist(new PastaFavorita($tenant, $user, $minha));
        $this->em()->persist(new PastaFavorita($tenant, $colega, $doColega));
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $minha->getId());
        self::assertResponseIsSuccessful();
        $item = $crawler->filter('#psMenuAcoes > button.js-pasta-favorito');
        self::assertCount(1, $item);
        self::assertSame('Fixar nos favoritos', trim($item->filter('span')->first()->text()));
        self::assertSame('true', $item->attr('aria-checked'));
        self::assertCount(1, $item->filter('.ps-pop-sw.is-ligado'));
        self::assertStringEndsWith('/pasta/' . $minha->getId() . '/favorito', (string) $item->attr('data-url'));

        $crawler = $client->request('GET', '/pasta/' . $doColega->getId());
        self::assertResponseIsSuccessful();
        $item = $crawler->filter('#psMenuAcoes > button.js-pasta-favorito');
        self::assertSame('false', $item->attr('aria-checked'), 'o favorito do colega não liga o meu interruptor');
        self::assertCount(0, $item->filter('.ps-pop-sw.is-ligado'));
    }

    #[TestDox('Acervo do Expediente: a favorita do usuário vem no topo e com a estrela; a do colega, não')]
    public function testAcervoPoeAFavoritaNoTopoComEstrela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $antiga          = $this->criarPastaComNumero($tenant, '91001');
        $this->criarPastaComNumero($tenant, '91002');
        $doColega        = $this->criarPastaComNumero($tenant, '91003');
        $this->em()->persist(new PastaFavorita($tenant, $user, $antiga));
        $this->em()->persist(new PastaFavorita($tenant, $colega, $doColega));
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/expediente/painel/acervo-geral', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $linhas = $crawler->filter('#tabelaPastas > tbody > tr');
        $nups   = $linhas->each(fn ($tr) => trim($tr->filter('td')->first()->text()));
        self::assertSame([$antiga->getNup(), '91003', '91002'], $nups, 'favorita no topo; o resto na ordem de sempre (número decrescente)');

        self::assertCount(1, $linhas->eq(0)->filter('td .pasta-favorita-estrela'), 'a favorita tem a estrela');
        self::assertCount(0, $linhas->eq(1)->filter('.pasta-favorita-estrela'), 'a favorita do COLEGA não tem estrela para mim');
        self::assertCount(0, $linhas->eq(2)->filter('.pasta-favorita-estrela'));
    }

    private function criarPastaComNumero(Tenant $tenant, string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
