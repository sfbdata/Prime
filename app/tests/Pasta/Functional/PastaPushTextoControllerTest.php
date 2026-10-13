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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * C6-FPN-03: o texto inteiro do teor para o "Copiar texto" do menu do cartão do Push
 * (`pasta_push_texto`; dc:5394 copia o teor todo, sem abrir o cartão).
 *
 * Duas coisas importam: a guarda é a mesma do teor (tenant da pasta → 404, VER a pasta → 403,
 * publicação de um processo DESTA pasta → 404), e a rota NÃO marca como lida — copiar não é
 * abrir. O par da pasta irmã prova a terceira camada: a mesma publicação pela pasta certa dá 200.
 */
#[CoversClass(PastaPushProcessualController::class)]
#[Group('pasta')]
final class PastaPushTextoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';
    private const NUMERO_ALHEIO   = '07099999999999999999';

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
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[TestDox('Devolve o teor INTEIRO em texto puro (além dos 220 do título) e a publicação continua NÃO lida')]
    public function testDevolveOTextoInteiroSemMarcarLida(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub   = $this->criarPublicacao($tenant, '41000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $longo = str_repeat('Palavra ', 60) . 'FIM';
        $pub->setTexto('<p>Intimação &amp; prazo:</p><p>' . $longo . '</p>');
        $this->em()->flush();
        $pubId = (int) $pub->getId();
        self::assertFalse($this->lidaNoBanco($pubId));

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pubId}/texto");

        self::assertResponseIsSuccessful();
        $texto = $this->json($client)['texto'];
        self::assertSame('Intimação & prazo: ' . $longo, $texto, 'sem tags, entidades decodificadas, espaços colapsados');
        self::assertGreaterThan(220, mb_strlen((string) $texto), 'é o teor todo, não o trecho do título');
        self::assertStringEndsWith('FIM', (string) $texto);
        self::assertFalse($this->lidaNoBanco($pubId), 'copiar não é abrir: o cartão segue "Novo"');
    }

    #[TestDox('Publicação sem teor devolve texto null')]
    public function testSemTeorDevolveNull(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '41000002', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pub->setTexto(null);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}/texto");

        self::assertResponseIsSuccessful();
        self::assertSame(['texto' => null], $this->json($client));
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
        $pubId = (int) $this->criarPublicacao($tenant, '41000010', self::NUMERO_ALHEIO, '2026-08-20', $processoB)->getId();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pastaA->getId()}/push/{$pubId}/texto");
        self::assertResponseStatusCodeSame(404, 'a pasta A não é porta para a publicação do processo da pasta B');
        self::assertStringNotContainsString('Teor da publicação 41000010', (string) $client->getResponse()->getContent());

        // Recurso irmão: a MESMA publicação, o mesmo usuário, pela pasta a que ela pertence.
        $client->request('GET', "/pasta/{$pastaB->getId()}/push/{$pubId}/texto");
        self::assertResponseIsSuccessful('pela pasta certa o texto sai — o 404 acima foi a guarda da pasta');
        self::assertSame('Teor da publicação 41000010', $this->json($client)['texto']);
        self::assertFalse($this->lidaNoBanco($pubId));
    }

    #[TestDox('Cross-tenant: pasta de outro escritório com o MESMO número dá 404 — nem a publicação de lá, nem a própria por ela')]
    public function testPastaDeOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        $client->disableReboot();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();

        $pastaA    = $this->criarPasta($tenantA);
        $processoA = $this->criarProcesso($tenantA, self::NUMERO_DA_PASTA);
        $this->vincular($pastaA, $processoA);
        $pubA = $this->criarPublicacao($tenantA, '41000020', self::NUMERO_DA_PASTA, '2026-08-20', $processoA);

        $pastaB    = $this->criarPasta($tenantB);
        $processoB = $this->criarProcesso($tenantB, self::NUMERO_DA_PASTA);
        $this->vincular($pastaB, $processoB);
        $pubB = $this->criarPublicacao($tenantB, '41000021', self::NUMERO_DA_PASTA, '2026-08-20', $processoB);

        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('GET', "/pasta/{$pastaB->getId()}/push/{$pubB->getId()}/texto");
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Teor da publicação 41000021', (string) $client->getResponse()->getContent());

        $client->request('GET', "/pasta/{$pastaB->getId()}/push/{$pubA->getId()}/texto");
        self::assertResponseStatusCodeSame(404, 'pasta de outro escritório não serve de porta, nem para publicação própria');

        // Pela própria pasta, a publicação de B continua fora (casada por tenant na consulta).
        $client->request('GET', "/pasta/{$pastaA->getId()}/push/{$pubB->getId()}/texto");
        self::assertResponseStatusCodeSame(404);

        // Recurso irmão: a própria publicação pela própria pasta.
        $client->request('GET', "/pasta/{$pastaA->getId()}/push/{$pubA->getId()}/texto");
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Quem não pode ver a pasta recebe 403; quem só VÊ (sem modules.djen.view) lê')]
    public function testPermissaoDeVer(): void
    {
        $client   = static::createClient();
        $client->disableReboot();
        $tenant   = $this->criarTenant();
        $semNada  = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $leitor   = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pubId = (int) $this->criarPublicacao($tenant, '41000030', self::NUMERO_DA_PASTA, '2026-08-20', $processo)->getId();

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pubId}/texto");
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Teor da publicação 41000030', (string) $client->getResponse()->getContent());

        $this->logarComTenant($client, $leitor, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pubId}/texto");
        self::assertResponseIsSuccessful();
        self::assertSame('Teor da publicação 41000030', $this->json($client)['texto']);
        self::assertFalse($this->lidaNoBanco($pubId));
    }

    #[TestDox('Na pasta excluída (lápide) o texto ainda se copia: é leitura, como o teor')]
    public function testLapideLe(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pubId = (int) $this->criarPublicacao($tenant, '41000040', self::NUMERO_DA_PASTA, '2026-08-20', $processo)->getId();
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pubId}/texto");

        self::assertResponseIsSuccessful();
        self::assertSame('Teor da publicação 41000040', $this->json($client)['texto']);
        self::assertFalse($this->lidaNoBanco($pubId));
    }

    #[TestDox('POST na rota não existe: é só leitura')]
    public function testPostNaoExiste(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pubId = (int) $this->criarPublicacao($tenant, '41000050', self::NUMERO_DA_PASTA, '2026-08-20', $processo)->getId();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/{$pubId}/texto");

        self::assertResponseStatusCodeSame(405);
        self::assertFalse($this->lidaNoBanco($pubId));
    }
}
