<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Pasta\Controller\PastaPushProcessualController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Marcar como lida / não lida pela pasta (`pasta_push_lida`, desenho 1.2.3).
 *
 * A guarda é a mesma do teor: pasta do escritório, permissão de VER a pasta e publicação casada
 * com um processo DESTA pasta. O teste que prova a última camada é o da pasta irmã — e ele só
 * prova alguma coisa porque o recurso irmão (a mesma publicação pela pasta CERTA) responde 200:
 * sem esse par, um 404 por qualquer outro motivo passaria por isolamento.
 */
#[CoversClass(PastaPushProcessualController::class)]
#[Group('pasta')]
final class PastaPushLidaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';
    private const NUMERO_ALHEIO   = '07099999999999999999';

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

    private function csrf(int $pastaId): string
    {
        return 'TOKEN_pasta_push_lida_' . $pastaId;
    }

    private function marcarComoLida(PublicacaoDjen $pub): void
    {
        $pub->setLida(true);
        $this->em()->flush();
    }

    private function lidaNoBanco(int $pubId): ?bool
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em->find(PublicacaoDjen::class, $pubId)?->isLida();
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[TestDox('Marcar como NÃO lida devolve 200 e grava lida = false')]
    public function testMarcarComoNaoLida(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '40000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->marcarComoLida($pub);
        $pubId = (int) $pub->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/{$pubId}/lida", [
            '_token' => $this->csrf((int) $pasta->getId()),
            'lida'   => '0',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['sucesso' => true, 'lida' => false], $this->json($client));
        self::assertFalse($this->lidaNoBanco($pubId));
    }

    #[TestDox('Marcar como lida devolve 200 e grava lida = true')]
    public function testMarcarComoLida(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pubId = (int) $this->criarPublicacao($tenant, '40000002', self::NUMERO_DA_PASTA, '2026-08-20', $processo)->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/{$pubId}/lida", [
            '_token' => $this->csrf((int) $pasta->getId()),
            'lida'   => '1',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['sucesso' => true, 'lida' => true], $this->json($client));
        self::assertTrue($this->lidaNoBanco($pubId));
    }

    #[TestDox('CSRF inválido dá 403 e não muda nada')]
    public function testCsrfInvalidoDa403(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '40000003', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->marcarComoLida($pub);
        $pubId = (int) $pub->getId();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/{$pubId}/lida", [
            '_token' => 'token_invalido',
            'lida'   => '0',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->lidaNoBanco($pubId), 'a publicação continua lida');
    }

    #[TestDox('Sem o campo `lida` dá 422 — não há valor padrão que adivinhe a intenção')]
    public function testSemOCampoLidaDa422(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '40000004', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->marcarComoLida($pub);
        $pubId = (int) $pub->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/{$pubId}/lida", [
            '_token' => $this->csrf((int) $pasta->getId()),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->lidaNoBanco($pubId));
    }

    #[TestDox('GET na rota não existe: marcar é POST')]
    public function testGetNaoMarca(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pubId = (int) $this->criarPublicacao($tenant, '40000005', self::NUMERO_DA_PASTA, '2026-08-20', $processo)->getId();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pubId}/lida");

        self::assertResponseStatusCodeSame(405);
        self::assertFalse($this->lidaNoBanco($pubId));
    }

    #[TestDox('Publicação de processo da pasta IRMÃ dá 404 pela pasta errada — e 200 pela certa')]
    public function testPublicacaoDePastaIrmaDa404EPelaCertaDa200(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pastaA          = $this->criarPasta($tenant);
        $pastaB          = $this->criarPasta($tenant);
        $this->vincular($pastaA, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));
        $processoB = $this->criarProcesso($tenant, self::NUMERO_ALHEIO);
        $this->vincular($pastaB, $processoB);
        $pubId = (int) $this->criarPublicacao($tenant, '40000010', self::NUMERO_ALHEIO, '2026-08-20', $processoB)->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pastaA->getId()}/push/{$pubId}/lida", [
            '_token' => $this->csrf((int) $pastaA->getId()),
            'lida'   => '1',
        ]);

        self::assertResponseStatusCodeSame(404, 'a pasta A não é porta para a publicação do processo da pasta B');
        self::assertFalse($this->lidaNoBanco($pubId), 'nada foi gravado pela pasta errada');

        // Recurso irmão: a MESMA publicação, o mesmo usuário, pela pasta a que ela pertence.
        $client->request('POST', "/pasta/{$pastaB->getId()}/push/{$pubId}/lida", [
            '_token' => $this->csrf((int) $pastaB->getId()),
            'lida'   => '1',
        ]);

        self::assertResponseIsSuccessful('pela pasta certa a mesma publicação é marcada — o 404 acima foi a guarda da pasta');
        self::assertTrue($this->lidaNoBanco($pubId));
    }

    #[TestDox('Pasta de outro escritório com o MESMO número de processo dá 404')]
    public function testPastaDeOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        $client->disableReboot();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();

        $pastaA    = $this->criarPasta($tenantA);
        $processoA = $this->criarProcesso($tenantA, self::NUMERO_DA_PASTA);
        $this->vincular($pastaA, $processoA);
        $pubA = $this->criarPublicacao($tenantA, '40000020', self::NUMERO_DA_PASTA, '2026-08-20', $processoA);
        $this->marcarComoLida($pubA);

        $pastaB    = $this->criarPasta($tenantB);
        $processoB = $this->criarProcesso($tenantB, self::NUMERO_DA_PASTA);
        $this->vincular($pastaB, $processoB);
        $pubB = $this->criarPublicacao($tenantB, '40000021', self::NUMERO_DA_PASTA, '2026-08-20', $processoB);
        $this->marcarComoLida($pubB);
        $pubAId = (int) $pubA->getId();
        $pubBId = (int) $pubB->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        // Pela pasta de B, nem a publicação de B nem a própria de A.
        $client->request('POST', "/pasta/{$pastaB->getId()}/push/{$pubBId}/lida", [
            '_token' => $this->csrf((int) $pastaB->getId()),
            'lida'   => '0',
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pastaB->getId()}/push/{$pubAId}/lida", [
            '_token' => $this->csrf((int) $pastaB->getId()),
            'lida'   => '0',
        ]);
        self::assertResponseStatusCodeSame(404, 'pasta de outro escritório não serve de porta, nem para publicação própria');

        self::assertTrue($this->lidaNoBanco($pubBId), 'a publicação de B segue intacta');
        self::assertTrue($this->lidaNoBanco($pubAId), 'a de A também');
    }

    #[TestDox('Quem não pode ver a pasta recebe 403')]
    public function testSemPermissaoDeVerAPastaDa403(): void
    {
        $client   = static::createClient();
        $client->disableReboot();
        $tenant   = $this->criarTenant();
        $user     = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pubId = (int) $this->criarPublicacao($tenant, '40000030', self::NUMERO_DA_PASTA, '2026-08-20', $processo)->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/{$pubId}/lida", [
            '_token' => $this->csrf((int) $pasta->getId()),
            'lida'   => '1',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->lidaNoBanco($pubId));
    }

    #[TestDox('Quem só pode ver a pasta (sem `modules.djen.view`) marca — ver já marca como lida no teor')]
    public function testSoComPermissaoDeVerMarca(): void
    {
        $client   = static::createClient();
        $client->disableReboot();
        $tenant   = $this->criarTenant();
        $user     = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '40000040', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->marcarComoLida($pub);
        $pubId = (int) $pub->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/{$pubId}/lida", [
            '_token' => $this->csrf((int) $pasta->getId()),
            'lida'   => '0',
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->lidaNoBanco($pubId));
    }

    #[TestDox('Desmarcar a leitura volta a contar no selo da aba Push')]
    public function testSeloDaAbaVoltaAContarDepoisDeDesmarcar(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '40000050', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->marcarComoLida($pub);
        $this->marcarComoLida($this->criarPublicacao($tenant, '40000051', self::NUMERO_DA_PASTA, '2026-08-21', $processo));

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertSame(0, $crawler->filter('#pastaTabs > #push-tab > .ps-aba-badge')->count(), 'as duas lidas: sem selo');

        $client->request('POST', "/pasta/{$pasta->getId()}/push/{$pub->getId()}/lida", [
            '_token' => $this->csrf((int) $pasta->getId()),
            'lida'   => '0',
        ]);
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertSame('1', trim($crawler->filter('#pastaTabs > #push-tab > .ps-aba-badge')->text()));
        self::assertSame(1, $crawler->filter('#pastaTabs > #push-tab.ps-aba--pend > .ps-aba-pend')->count(), 'a linha de pendência volta');
        self::assertSame(1, $crawler->filter('.ps-push-lista > .ps-push-item.ps-push-item--nova')->count());
        self::assertSame(
            (string) $pub->getId(),
            $crawler->filter('.ps-push-lista > .ps-push-item.ps-push-item--nova')->attr('data-push-id'),
            'a nova é a que foi desmarcada',
        );
    }
}
