<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\TrabalhoExternoController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A moldura da tela do Trabalho Externo (desenho 06 - Trabalho Externo 1.0): o contêiner raiz
 * que o `trabalho-externo.js` desenha, com o painel e as URLs da API em data-attributes, e o
 * esqueleto fixo do template em filhos diretos.
 *
 * Lista, KPIs, painel lateral e modais são desenhados no navegador e não aparecem aqui; estilo
 * (cor, fonte, raio) segue invisível para o PHPUnit e é do smoke do dono.
 */
#[CoversClass(TrabalhoExternoController::class)]
final class TrabalhoExternoTelaTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarMaster(): array
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Tela Trabalho Externo ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_te_tela_' . uniqid() . '@test.com');
        $user->setFullName('Master Tela Trabalho Externo');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function abrirComoMaster(KernelBrowser $client): Crawler
    {
        [$user, $tenant] = $this->criarMaster();
        $this->logarComTenant($client, $user, $tenant);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $crawler = $client->request('GET', '/trabalho-externo');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('o contêiner raiz leva o painel do MontarPainelUseCase em data-painel, já com o perfil Master')]
    public function testRaizLevaOPainel(): void
    {
        $client = static::createClient();
        $crawler = $this->abrirComoMaster($client);

        $raiz = $crawler->filter('#tePagina.te-page');
        self::assertCount(1, $raiz);

        $painel = json_decode((string) $raiz->attr('data-painel'), true);
        self::assertIsArray($painel, 'data-painel precisa ser JSON válido');
        foreach (['perfil', 'configuracao', 'bloqueio', 'aviso', 'envioBloqueado', 'hoje', 'demandas', 'avisoDeMensagem', 'novaSolicitacao'] as $chave) {
            self::assertArrayHasKey($chave, $painel, "o JS lê a chave {$chave}");
        }
        self::assertSame('master', $painel['perfil']['chave']);
        self::assertTrue($painel['perfil']['master']);
        self::assertTrue($painel['perfil']['podeSolicitar'], 'o Master, como usuário primário do escritório dele, também abre solicitação (16/10)');
        self::assertNull($painel['bloqueio'], 'o Master nunca vê a tela de bloqueio');
        self::assertTrue($painel['novaSolicitacao']['peloMaster'], 'o formulário vem no modo "pelo Master"');
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $painel['hoje']);
    }

    #[TestDox('as URLs da API vão em data-attributes, as com {codigo} com o marcador TE-0 que o JS troca')]
    public function testUrlsDaApiEmDataAttributes(): void
    {
        $client = static::createClient();
        $raiz = $this->abrirComoMaster($client)->filter('#tePagina');

        self::assertSame('TE-0', $raiz->attr('data-codigo-marcador'));
        $esperadas = [
            'data-url-index' => '/trabalho-externo',
            'data-url-painel' => '/trabalho-externo/api/painel',
            'data-url-detalhe' => '/trabalho-externo/api/demandas/TE-0',
            'data-url-solicitacao' => '/trabalho-externo/api/solicitacoes',
            'data-url-acao' => '/trabalho-externo/api/demandas/TE-0/acoes/__acao__',
            'data-url-proposta' => '/trabalho-externo/api/demandas/TE-0/propostas',
            'data-url-mensagem' => '/trabalho-externo/api/demandas/TE-0/mensagens',
            'data-url-lida' => '/trabalho-externo/api/demandas/TE-0/lida',
            'data-url-checklist' => '/trabalho-externo/api/demandas/TE-0/checklist/0',
            'data-url-andamento' => '/trabalho-externo/api/demandas/TE-0/andamentos',
            'data-url-anexar' => '/trabalho-externo/api/demandas/TE-0/documentos',
            'data-url-documento' => '/trabalho-externo/demandas/TE-0/documentos/0',
            'data-url-despachar' => '/trabalho-externo/api/demandas/TE-0/despachos',
            'data-url-despacho-responder' => '/trabalho-externo/api/demandas/TE-0/despachos/respostas',
            'data-url-despacho-respostas' => '/trabalho-externo/api/demandas/TE-0/despachos/aceitar-resposta',
            'data-url-despacho-extrato' => '/trabalho-externo/demandas/TE-0/despachos/extrato',
            'data-url-configuracao' => '/trabalho-externo/api/configuracao',
            'data-url-marcacoes' => '/trabalho-externo/api/demandas/marcacoes',
            'data-url-mensagens-marcacoes' => '/trabalho-externo/api/demandas/TE-0/mensagens/marcacoes',
            'data-url-mensagem-apagar' => '/trabalho-externo/api/demandas/TE-0/mensagens/0/apagar',
            'data-url-vagas' => '/trabalho-externo/api/vagas',
            'data-url-vaga' => '/trabalho-externo/api/vagas/VG-0',
            'data-url-vaga-salvar' => '/trabalho-externo/api/vagas',
            'data-url-vaga-acao' => '/trabalho-externo/api/vagas/VG-0/acoes/__acao__',
            'data-url-vagas-marcacoes' => '/trabalho-externo/api/vagas/marcacoes',
            'data-url-vagas-compartilhar' => '/trabalho-externo/api/vagas/compartilhar',
            'data-url-vagas-divulgacao' => '/trabalho-externo/api/vagas/divulgacao',
            'data-url-vaga-editar' => '/trabalho-externo/api/vagas/VG-0/editar',
            'data-url-vaga-excluir' => '/trabalho-externo/api/vagas/VG-0/excluir',
            'data-url-vagas-visita' => '/trabalho-externo/api/vagas/visita',
            'data-url-campanha' => '/trabalho-externo/api/campanha',
            'data-url-campanha-csv' => '/trabalho-externo/campanha/destinatarios.csv',
            'data-url-campanha-html' => '/trabalho-externo/campanha/email.html',
            'data-url-campanha-registro' => '/trabalho-externo/api/campanha/registro',
            'data-url-curriculo-word' => '/trabalho-externo/curriculos/meu/word',
            'data-url-andamentos' => '/trabalho-externo/api/andamentos',
        ];
        foreach ($esperadas as $atributo => $url) {
            self::assertSame($url, $raiz->attr($atributo), $atributo);
        }
        self::assertContains($raiz->attr('data-perfil-sons'), ['0', '1'], '"Sons de alerta" do Meu Perfil, para o toque do andamento (D10)');
    }

    #[TestDox('esqueleto fixo em filhos diretos: título, bloqueio, área liberada com período, KPIs, filtros, barra de lote e tabela de 9 colunas (a primeira é a de marcação), e as camadas do JS')]
    public function testEsqueletoDoTemplate(): void
    {
        $client = static::createClient();
        $crawler = $this->abrirComoMaster($client);

        self::assertCount(1, $crawler->filter('#tePagina > .te-titulo-linha > h1.te-titulo > i.bi-geo-alt'));
        self::assertStringContainsString('Trabalho Externo', $crawler->filter('#tePagina > .te-titulo-linha > h1.te-titulo .te-titulo-nome')->text());
        self::assertStringContainsString('Central de demandas, vagas e talentos jurídicos', $crawler->filter('#tePagina > .te-titulo-linha > h1.te-titulo .te-titulo-sub')->text());
        self::assertCount(1, $crawler->filter('#tePagina > .te-titulo-linha > #teTituloAcoes'));

        self::assertCount(1, $crawler->filter('#tePagina > #teBloqueio'));
        self::assertCount(1, $crawler->filter('#tePagina > #teLiberado > #teAviso'));
        self::assertCount(1, $crawler->filter('#tePagina > #teLiberado > #tePeriodo'));
        self::assertCount(1, $crawler->filter('#tePagina > #teLiberado > #teKpis'));
        self::assertCount(1, $crawler->filter('#tePagina > #teLiberado > .te-filtros > #teChips'));
        self::assertSame(
            'Buscar demanda, cidade, processo',
            $crawler->filter('#tePagina > #teLiberado > .te-filtros > label.te-busca > input#teBusca')->attr('placeholder'),
        );

        $cabecalho = $crawler->filter('#tePagina > #teLiberado > .te-tabela-caixa > .te-tabela > .te-grade--cab > div');
        self::assertCount(9, $cabecalho);
        self::assertCount(1, $crawler->filter('#tePagina > #teLiberado > .te-tabela-caixa > .te-tabela > .te-grade--cab > div.te-cab-sel#teCabSel:first-child'));
        self::assertCount(1, $crawler->filter('#tePagina > #teLiberado > .te-filtros + #teLote + .te-tabela-caixa'), 'a barra de lote fica entre os filtros e a tabela');
        self::assertSame(
            ['', 'ID', 'SERVIÇO', 'ORIGEM → EXECUÇÃO', 'PRAZO', 'RESPONSÁVEL', 'SITUAÇÃO', 'CHAT', ''],
            $cabecalho->each(static fn (Crawler $c): string => trim($c->text())),
        );
        self::assertCount(1, $crawler->filter('#tePagina > #teLiberado > .te-tabela-caixa > .te-tabela > #teLinhas'));

        // Abas de módulo (desenho VS 18.10.26, linhas 85–87) entre o bloqueio e as Demandas; a aba
        // Vagas é irmã das Demandas e nasce escondida (o JS mostra se o servidor disser `veAba`).
        self::assertCount(1, $crawler->filter('#tePagina > #teBloqueio + #teModulos + #teLiberado'));
        self::assertCount(1, $crawler->filter('#tePagina > #teLiberado + #teVagas[hidden]'));
        self::assertSame(
            'Buscar vaga, cargo, área ou cidade',
            $crawler->filter('#tePagina > #teVagas > .te-vg-barra > label.te-vg-busca > input#teVgBusca')->attr('placeholder'),
        );
        self::assertCount(1, $crawler->filter('#tePagina > #teVagas > .te-vg-barra + #teVgLote + #teVgVazio + #teVgGrade.te-vg-grade'));

        foreach (['teMenu', 'teDrawer', 'teNova', 'teCfg', 'teConf', 'teToast', 'teDesfazer', 'teVagaModal', 'teEnvio'] as $camada) {
            self::assertCount(1, $crawler->filter("#tePagina > #{$camada}"), "camada {$camada} dentro do .te-page, para herdar os tokens do tema");
        }
    }

    #[TestDox('carrega o CSS e o JS próprios da tela, o JS com defer')]
    public function testCarregaOsAssets(): void
    {
        $client = static::createClient();
        $crawler = $this->abrirComoMaster($client);

        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="css/trabalho-externo.css"]'));
        self::assertCount(1, $crawler->filter('script[src*="js/trabalho-externo.js"][defer]'));
        self::assertCount(1, $crawler->filter('body.te-body'));
    }
}
