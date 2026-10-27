<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\ProcessosDaOabController;
use App\Djen\Entity\ProcessoAcompanhado;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ProcessosDaOabController::class)]
#[Group('djen')]
final class ProcessosDaOabControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO_A = '07022222220268070001';
    private const NUMERO_B = '07033333320268070002';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarProcesso(Tenant $tenant, string $numero): Processo
    {
        $processo = new Processo();
        $processo->setTenant($this->em()->getReference(Tenant::class, $tenant->getId()));
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setOrgaoJulgador('1ª Vara Cível');
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: Tenant} */
    private function entrar(): array
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        return [$client, $tenant];
    }

    #[Test]
    public function listaOsProcessosAcompanhadosNoArranjoDoDesenho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav.pp-abas a.pp-aba.is-ativa[data-pp-aba="processos"]'));
        self::assertCount(5, $crawler->filter('.pp-pagina > .pp-kpis > a.pp-kpi'));
        self::assertCount(1, $crawler->filter('.pp-pagina > section[data-pp-inclusao] > form'));
        self::assertCount(2, $crawler->filter('.pp-pagina > .pp-subabas > a.pp-subaba'));
        self::assertCount(1, $crawler->filter('[data-pp-inclusao] input[data-pp-mascara-cnj]'), 'máscara CNJ do desenho');
        self::assertSame('0 | 150', trim($crawler->filter('[data-pp-inclusao] [data-pp-obs-n]')->text()), 'contador da observação do desenho');
        self::assertCount(1, $crawler->filter('.pp-tabela__corpo > .pp-linha--dado'));
        self::assertCount(1, $crawler->filter('.pp-tabela__corpo > .pp-linha--dado[data-pp-linha-proc][data-numero]'), 'a linha carrega o contexto do menu de botão direito');
        self::assertCount(1, $crawler->filter('.pp-linha--dado .pp-c-acoes > button[data-pp-vincular-proc]'), 'sem pasta, o botão Pasta abre o modal');
        self::assertCount(1, $crawler->filter('[data-pp-modal-pasta]'));
        self::assertStringContainsString('0702222-22.2026.8.07.0001', $crawler->filter('.pp-linha--dado')->text());
        self::assertSame('1', trim($crawler->filter('[data-pp-kpi="processos"] .pp-kpi__valor')->text()));
        self::assertSame('1', trim($crawler->filter('[data-pp-kpi="nunca"] .pp-kpi__valor')->text()));
        self::assertCount(0, $crawler->filter('.pp-pagina .card, .pp-pagina .btn, .pp-pagina .table'));
    }

    #[Test]
    public function incluirNumeroSemProcessoGravaEAparece(): void
    {
        [$client, $tenant] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-inclusao] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/incluir', ['_token' => $token, 'numero' => '0703333-33.2026.8.07.0002', 'email' => 'a@b.com', 'observacao' => 'Cliente Y']);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('incluído para acompanhamento', $crawler->text());
        self::assertStringContainsString('Cliente Y', $crawler->filter('.pp-linha--dado')->text());
        $linha = $this->em()->getRepository(ProcessoAcompanhado::class)->findOneBy(['numeroProcesso' => self::NUMERO_B]);
        self::assertNotNull($linha);
        self::assertSame($tenant->getId(), $linha->getTenant()->getId());
        self::assertTrue($linha->isAcompanhando());
    }

    #[Test]
    public function incluirComTokenInvalidoNaoGrava(): void
    {
        [$client] = $this->entrar();

        $client->request('POST', '/push-processual/processos/incluir', ['_token' => 'errado', 'numero' => self::NUMERO_B]);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertNull($this->em()->getRepository(ProcessoAcompanhado::class)->findOneBy(['numeroProcesso' => self::NUMERO_B]));
    }

    #[Test]
    public function pararDeAcompanharPausaOProcessoESaiDaLista(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $processo = $this->criarProcesso($tenant, self::NUMERO_A);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-inclusao] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/parar', ['_token' => $token, 'numeros' => [self::NUMERO_A]]);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('1 processo(s) deixaram de ser acompanhados', $crawler->text());
        self::assertCount(0, $crawler->filter('.pp-linha--dado'));
        $this->limparIdentityMap();
        self::assertFalse($this->em()->find(Processo::class, $processo->getId())->monitoramentoAtivo());
    }

    #[Test]
    public function relatorioListaNumeroDaOabQueAindaNaoEAcompanhadoEIncluiNoAcompanhamento(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $oab = $this->criarOab($tenant, '49613', 'DF');
        $publicacao = $this->criarPublicacao($tenant, '950001', self::NUMERO_B);
        $publicacao->setOabMonitorada($oab);
        $this->em()->flush();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos?sub=relatorio');

        self::assertCount(1, $crawler->filter('.pp-linha--dado'));
        self::assertStringContainsString('0703333-33.2026.8.07.0002', $crawler->filter('.pp-linha--dado')->text());
        $token = $crawler->filter('.pp-linha--dado input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/incluir-do-relatorio', ['_token' => $token, 'numeros' => [self::NUMERO_B]]);
        $client->followRedirect();
        $crawler = $client->request('GET', '/push-processual/processos?sub=relatorio');

        self::assertCount(0, $crawler->filter('.pp-linha--dado'), 'incluído, sai do Relatório');
        $crawler = $client->request('GET', '/push-processual/processos');
        self::assertCount(1, $crawler->filter('.pp-linha--dado'));
    }

    #[Test]
    public function anotacoesGravamObservacaoAreaEFase(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/processos');
        $token = $crawler->filter('[data-pp-inclusao] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/processos/anotacoes', ['_token' => $token, 'numero' => self::NUMERO_A, 'observacao' => 'Cliente Z', 'area' => 'Cível', 'fase' => 'Recurso protocolado', 'email' => '']);
        $crawler = $client->followRedirect();

        $linha = $crawler->filter('.pp-linha--dado')->text();
        self::assertStringContainsString('Cliente Z', $linha);
        self::assertStringContainsString('Cível', $linha);
        self::assertStringContainsString('Recurso protocolado', $linha);
        self::assertStringContainsString('Recursal', $linha, 'o grupo da fase aparece sob o número');
        $crawler = $client->request('GET', '/push-processual/processos', ['area' => 'Família']);
        self::assertCount(0, $crawler->filter('.pp-linha--dado'));
        self::assertCount(1, $crawler->filter('[data-pp-limpar]'));
    }

    #[Test]
    public function exportaCsvComOsFiltros(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarProcesso($tenant, self::NUMERO_A);
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        ob_start();
        $client->request('GET', '/push-processual/processos/exportar');
        $csv = ob_get_clean() . (string) $client->getInternalResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('0702222-22.2026.8.07.0001', (string) $csv);
    }

    #[Test]
    public function nadaVazaEntreEscritorios(): void
    {
        $client = static::createClient();
        $outro = $this->criarTenant();
        $this->criarProcesso($outro, self::NUMERO_A);
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/processos');

        self::assertCount(0, $crawler->filter('.pp-linha--dado'));
    }

    #[Test]
    public function semOModuloProcessosVaiParaAInicial(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['modules.djen.view']), $tenant);

        $client->request('GET', '/push-processual/processos');

        self::assertResponseRedirects();
    }
}
