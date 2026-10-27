<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Processos e Push Processual UNIFICADOS (pedido do dono, 16/10):
 *
 *  - a barra superior troca "Processos" por "Minhas Metas";
 *  - o menu lateral perde "Processos" e o Push Processual responde pelas telas de processo;
 *  - a tela do Push leva ao cadastro e à lista de processos, ao lado do "Sincronizar agora";
 *  - o "Sincronizar agora" capta pela OAB E pelo número de cada processo cadastrado.
 *
 * Nenhuma rota de processo mudou: o que se prova aqui é que o caminho até elas continua existindo
 * para quem tinha acesso, inclusive para o perfil que tem Processos mas não tem o Push.
 */
final class PushProcessualUnificadoTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO = '07011111111111111111';

    #[Test]
    public function barraSuperiorTrocaProcessosPorMinhasMetas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');

        self::assertResponseIsSuccessful();
        $metas = $crawler->filter('.navtop-subnav > a[href="/tarefas/minhas"]');
        self::assertCount(1, $metas, 'Minhas Metas na barra superior');
        self::assertSame('Minhas Metas', trim($metas->text()));
        self::assertCount(0, $crawler->filter('.navtop-subnav a[href="/processos/"]'), 'Processos saiu da barra superior');
        self::assertSame(
            ['Expediente', 'Demandas', 'Minhas Metas'],
            array_slice($crawler->filter('.navtop-subnav > a')->each(static fn ($a): string => trim($a->text())), 0, 3),
            'Minhas Metas ocupa o lugar que era de Processos',
        );
    }

    #[Test]
    public function minhasMetasNaBarraSuperiorAcendeNaTelaDeMetas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.navtop-subnav > a.active[href="/tarefas/minhas"]'));
    }

    #[Test]
    public function minhasMetasNaoApareceNaBarraParaQuemNaoTemOModulo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['modules.djen.view']), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.navtop-subnav a[href="/tarefas/minhas"]'));
    }

    #[Test]
    public function menuLateralNaoTemMaisProcessosEPushAcendeNasTelasDeProcesso(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/processos/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#pjeSidebar a[href="/processos/"]'), 'item Processos saiu do menu lateral');
        self::assertCount(1, $crawler->filter('#pjeSidebar a.active[href="/push-processual"]'), 'Push Processual acende na lista de processos');
        self::assertStringContainsString(
            'processos',
            (string) $crawler->filter('#pjeSidebar a[href="/push-processual"]')->attr('data-busca-alias'),
            'o acesso rápido ainda acha "processos"',
        );
    }

    #[Test]
    public function quemTemProcessosSemOPushAindaChegaAosProcessosPeloMenu(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['modules.processos.view']), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/processos/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#pjeSidebar a[href="/push-processual"]'));
        self::assertCount(1, $crawler->filter('#pjeSidebar a[href="/processos/"]'), 'sem o Push, o caminho até os processos fica no menu');
        self::assertCount(0, $crawler->filter('section.content-header a[href="/push-processual"]'), 'sem o Push, a lista não oferece ir para ele');
    }

    #[Test]
    public function telaDoPushLevaAoCadastroEAListaDeProcessosAoLadoDoSincronizar(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarProcesso($tenant, self::NUMERO);
        $this->criarProcesso($tenant, '07022222222222222222', new \DateTime('2026-01-10'));
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');

        self::assertResponseIsSuccessful();
        // Mesmo grupo do topo do desenho (irmãos diretos), não só "em algum lugar".
        $grupo = '.pp-pagina > .pp-topo';
        self::assertCount(1, $crawler->filter($grupo . ' > button[data-push-cadastrar-processo] ~ form[action="/push-processual/sincronizar"]'));
        $aba = $crawler->filter('nav[data-pp-abas] a[data-pp-aba="processos"][href="/push-processual/processos"]');
        self::assertCount(1, $aba, 'a lista de processos é a aba Processos da OAB');
        self::assertSame('1', trim($aba->filter('.pp-aba__n')->text()), 'conta só o processo sem baixa (o que a sincronização consulta)');
    }

    #[Test]
    public function telaDoPushEscondeOsBotoesDeProcessoDeQuemNaoTemOModulo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['modules.djen.view']), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-push-cadastrar-processo]'));
        self::assertCount(0, $crawler->filter('a[data-pp-aba="processos"]'));
        self::assertCount(1, $crawler->filter('form[action="/push-processual/sincronizar"]'), 'o resto do Push segue igual');
    }

    #[Test]
    public function cadastroDeProcessoSegueAbrindoComCaminhoDeVoltaAoPush(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/processos/novo');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.breadcrumb a[href="/push-processual"]'));

        $crawler = $client->request('GET', '/processos/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('section.content-header a[href="/push-processual"]'));
        self::assertCount(1, $crawler->filter('section.content-header a[href="/processos/novo"]'));
    }

    #[Test]
    public function sincronizarAgoraCaptaPelaOabEPeloNumeroDoProcessoCadastrado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $this->criarOab($tenant, '67228', 'PR');
        $this->criarProcesso($tenant, self::NUMERO);

        // A OAB não traz nada; só a consulta pelo NÚMERO do processo cadastrado traz a publicação.
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturnCallback(
            static fn (ConsultaComunicacoesQuery $q): RespostaComunicacoes => $q->numeroProcesso === self::NUMERO
                ? new RespostaComunicacoes(1, [[
                    'id' => 880001,
                    'numero_processo' => self::NUMERO,
                    'siglaTribunal' => 'TJDFT',
                    'tipoComunicacao' => 'Intimação',
                    'data_disponibilizacao' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                ]])
                : new RespostaComunicacoes(0, [])
        );
        static::getContainer()->set(DjenClient::class, $mock);

        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');
        $client->submit($crawler->selectButton('Sincronizar agora')->form());

        self::assertResponseRedirects('/push-processual');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('1 processo(s) cadastrado(s) consultado(s)', $crawler->text());

        $this->limparIdentityMap();
        $publicacao = static::getContainer()->get(PublicacaoDjenRepository::class)->findOneBy(['djenId' => '880001']);
        self::assertNotNull($publicacao, 'publicação captada pelo número do processo');
        self::assertNull($publicacao->getOabMonitorada());
        self::assertSame(self::NUMERO, $publicacao->getProcesso()?->getNumeroProcesso(), 'já entra vinculada ao processo');
        self::assertSame($tenant->getId(), $publicacao->getTenant()?->getId());
    }

    private function criarProcesso(Tenant $tenant, string $numero, ?\DateTime $baixa = null): void
    {
        $em = $this->em();
        $processo = new Processo();
        $processo->setTenant($em->getReference(Tenant::class, $tenant->getId()));
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual('Procedimento Comum');
        if ($baixa !== null) {
            $processo->setDataBaixa($baixa);
        }
        $em->persist($processo);
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
