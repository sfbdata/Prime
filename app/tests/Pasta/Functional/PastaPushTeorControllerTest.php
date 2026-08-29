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

/**
 * O acordeão da aba: leitura do teor de uma publicação PELA pasta.
 *
 * Como esta rota não exige `modules.djen.view` (decisão do dono: o push é conteúdo do caso), o
 * guarda que sobra precisa ser completo. O teste que mais importa aqui é o da PASTA IRMÃ — mesmo
 * escritório, outra pasta: cross-tenant sozinho seria provado pelo TenantFilter e não diria nada
 * sobre a restrição que este controller adiciona.
 */
#[CoversClass(PastaPushProcessualController::class)]
#[Group('pasta')]
final class PastaPushTeorControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';
    private const NUMERO_ALHEIO   = '07099999999999999999';

    #[TestDox('Devolve o teor da publicação do processo da pasta')]
    public function testDevolveOTeor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '30000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Teor da publicação 30000001', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Ler pela pasta marca a publicação como lida, como abrir pelo módulo faria')]
    public function testMarcaComoLida(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub   = $this->criarPublicacao($tenant, '30000010', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pubId = (int) $pub->getId();
        self::assertFalse($pub->isLida());

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pubId}");
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertTrue($em->getRepository(PublicacaoDjen::class)->find($pubId)?->isLida());
    }

    #[TestDox('Publicação de OUTRA pasta do mesmo escritório dá 404 — o id na URL não vira leitor geral')]
    public function testPublicacaoDePastaIrmaDa404(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pastaA          = $this->criarPasta($tenant);
        $pastaB          = $this->criarPasta($tenant);
        $this->vincular($pastaA, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));
        $processoB = $this->criarProcesso($tenant, self::NUMERO_ALHEIO);
        $this->vincular($pastaB, $processoB);
        $daIrma = $this->criarPublicacao($tenant, '30000020', self::NUMERO_ALHEIO, '2026-08-20', $processoB);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pastaA->getId()}/push/{$daIrma->getId()}");

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Pasta de outro escritório dá 404 mesmo para quem tem a publicação no próprio')]
    public function testPastaDeOutroEscritorioDa404(): void
    {
        $client          = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB   = $this->criarPasta($tenantB);
        $processoB = $this->criarProcesso($tenantB, self::NUMERO_DA_PASTA);
        $this->vincular($pastaB, $processoB);
        $pubB = $this->criarPublicacao($tenantB, '30000030', self::NUMERO_DA_PASTA, '2026-08-20', $processoB);

        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', "/pasta/{$pastaB->getId()}/push/{$pubB->getId()}");

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Pasta de outro escritório com o MESMO número de processo também dá 404')]
    public function testPastaDeOutroEscritorioComOMesmoNumeroDa404(): void
    {
        // O 404 do teste anterior sai mesmo sem a conferência de dono da pasta: a busca da
        // publicação já é escopada pelo escritório da sessão, e a de B não está lá. Medido por
        // reintrodução. Quem prova a conferência é este cenário: dois escritórios atuando no MESMO
        // processo — situação comum. Sem a conferência, A passaria o id de uma pasta de B e
        // receberia 200, e o par 200/404 diria a A se a pasta de B contém aquele processo.
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();

        $pastaA    = $this->criarPasta($tenantA);
        $processoA = $this->criarProcesso($tenantA, self::NUMERO_DA_PASTA);
        $this->vincular($pastaA, $processoA);
        $pubA = $this->criarPublicacao($tenantA, '30000045', self::NUMERO_DA_PASTA, '2026-08-20', $processoA);

        $pastaB = $this->criarPasta($tenantB);
        $this->vincular($pastaB, $this->criarProcesso($tenantB, self::NUMERO_DA_PASTA));

        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', "/pasta/{$pastaB->getId()}/push/{$pubA->getId()}");

        self::assertResponseStatusCodeSame(404, 'pasta de outro escritório não pode servir de porta, nem para publicação própria');
    }

    #[TestDox('A publicação continua existindo depois do 404: o que houve foi isolamento, não exclusão')]
    public function testO404NaoApagaNada(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO_DA_PASTA);
        $this->vincular($pastaB, $processoB);
        $pubId = (int) $this->criarPublicacao($tenantB, '30000040', self::NUMERO_DA_PASTA, '2026-08-20', $processoB)->getId();

        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', "/pasta/{$pastaB->getId()}/push/{$pubId}");
        self::assertResponseStatusCodeSame(404);

        // O TenantFilter continua ligado com o escritório A depois da request; sem desligá-lo, a
        // consulta devolveria null por FILTRO e o teste "não apagou" passaria a provar outra coisa.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(PublicacaoDjen::class, $pubId), 'a publicação de B deve continuar existindo');
    }

    #[TestDox('Quem não pode ver a pasta não lê o teor por ela')]
    public function testSemPermissaoDeVerAPastaNaoLe(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $user     = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '30000050', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Quem tem só a permissão de ver a pasta lê o teor — sem `modules.djen.view`')]
    public function testSemPermissaoDoModuloLe(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $user     = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '30000060', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");

        self::assertResponseIsSuccessful();
    }

    #[TestDox('O link "Abrir no módulo" leva o caminho de volta para ESTA pasta, com a aba certa')]
    public function testLinkParaOModuloLevaOCaminhoDeVolta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '30000070', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");

        self::assertResponseIsSuccessful();
        // Compara o VALOR, não o texto codificado: o gerador de URL do Symfony deixa a barra crua
        // e escapa só o `#`, e um assert sobre a codificação quebraria sem defeito nenhum.
        $href = (string) $crawler->filter('.ps-push-teor-pe a[href^="/push-processual/"]')->attr('href');
        parse_str((string) parse_url($href, PHP_URL_QUERY), $parametros);
        self::assertSame("/pasta/{$pasta->getId()}#push", $parametros['voltar'] ?? null);
    }
}
