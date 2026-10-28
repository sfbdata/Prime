<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tela da Agenda 1A (docs/specs/agenda-1a.md, seção 5): o arranjo do desenho por combinador de filho direto.
 * Grade, lista, Detalhe e legendas são desenhados pelo JS; aqui vale o esqueleto que o servidor entrega.
 */
#[CoversNothing]
final class Agenda1ATelaTest extends JusPrimeWebTestCase
{
    #[TestDox('/agenda entrega a barra do desenho: Hoje, setas, título, busca, segmento Mês/Semana/Lista e Novo evento')]
    public function testBarra(): void
    {
        $crawler = $this->abrir();

        self::assertCount(1, $crawler->filter('.a1 > .a1-barra'));
        self::assertCount(1, $crawler->filter('.a1-barra > .a1-nav > button#a1Hoje'));
        self::assertCount(1, $crawler->filter('.a1-barra > .a1-nav > .a1-setas > button#a1Ant'));
        self::assertCount(1, $crawler->filter('.a1-barra > .a1-nav > .a1-setas > button#a1Prox'));
        self::assertCount(1, $crawler->filter('.a1-barra > .a1-periodo > h1.a1-titulo#a1Titulo'));
        self::assertCount(1, $crawler->filter('.a1-barra > .a1-periodo > .a1-resumo#a1Resumo'));
        self::assertCount(1, $crawler->filter('.a1-barra > label.a1-busca > input#a1Busca'));
        self::assertSame('Buscar evento, local ou pessoa', $crawler->filter('#a1Busca')->attr('placeholder'));
        self::assertSame(
            ['Mês', 'Semana', 'Lista'],
            $crawler->filter('.a1-barra > .a1-seg > button.a1-seg-bt')->each(static fn ($b): string => trim($b->text())),
        );
        self::assertSame(
            ['mes', 'semana', 'lista'],
            $crawler->filter('.a1-barra > .a1-seg > button')->each(static fn ($b): string => (string) $b->attr('data-a1-vista')),
        );
        self::assertSame('Novo evento', trim($crawler->filter('.a1-barra > button#a1Novo')->text()));
    }

    #[TestDox('/agenda: grade à esquerda e coluna direita na ordem do desenho (Hoje, Legendas, Filtros) com o Google no fim; o Detalhe só vem do JS')]
    public function testCorpoEColunaDireita(): void
    {
        $crawler = $this->abrir();

        self::assertCount(1, $crawler->filter('.a1 > .a1-corpo > .a1-principal#a1Grade'));
        self::assertCount(1, $crawler->filter('.a1 > .a1-corpo > .a1-lateral#a1Lateral'));
        self::assertCount(1, $crawler->filter('.a1-lateral > .a1-hoje + .a1-legendas + .a1-filtros + .a1-google'));
        self::assertCount(4, $crawler->filter('.a1-lateral > *'));
        self::assertCount(0, $crawler->filter('#a1Detalhe'));
        self::assertSame('a1-hoje', explode(' ', (string) $crawler->filter('.a1-lateral > *')->first()->attr('class'))[1]);
        self::assertStringContainsString('a1-google', (string) $crawler->filter('.a1-lateral > *')->last()->attr('class'));

        // Cartão Hoje: rótulo, data, lista e "A seguir".
        self::assertSame('Hoje', trim($crawler->filter('.a1-hoje > .a1-hoje-cab > .a1-rotulo')->text()));
        self::assertCount(1, $crawler->filter('.a1-hoje > .a1-hoje-cab > #a1HojeRotulo'));
        self::assertCount(1, $crawler->filter('.a1-hoje > #a1HojeLista'));
        self::assertSame('A seguir', trim($crawler->filter('.a1-hoje > .a1-seguir > .a1-rotulo')->text()));
        self::assertCount(1, $crawler->filter('.a1-seguir > #a1Proximos'));

        // Legendas: "mostrar todas" escondido, engrenagem sem ação, lista desenhada pelo JS.
        self::assertSame('Legendas', trim($crawler->filter('.a1-legendas > .a1-leg-cab > .a1-rotulo')->text()));
        self::assertNotNull($crawler->filter('.a1-leg-cab > #a1LegTodas[hidden]')->getNode(0));
        self::assertSame('Gerenciar legendas', $crawler->filter('.a1-leg-cab > button.a1-leg-gear')->attr('title'));
        self::assertCount(0, $crawler->filter('.a1-leg-gear[data-a1-acao], .a1-leg-gear[id]'));
        self::assertCount(1, $crawler->filter('.a1-legendas > #a1LegLista'));

        // Filtros: Pessoa, Status (3), Mostrar cancelados e Limpar filtros (escondido sem filtro).
        self::assertSame('Filtros', trim($crawler->filter('.a1-filtros > .a1-rotulo')->text()));
        self::assertSame('Todo o escritório', trim($crawler->filter('.a1-filtros select#a1Pessoa > option')->first()->text()));
        self::assertSame(
            ['Todos', 'Agendados', 'Concluídos'],
            $crawler->filter('.a1-filtros .a1-seg-status > button')->each(static fn ($b): string => trim($b->text())),
        );
        self::assertSame('Mostrar cancelados', trim($crawler->filter('.a1-filtros label.a1-check > span')->text()));
        self::assertNotNull($crawler->filter('.a1-filtros-col > #a1Limpar[hidden]')->getNode(0));
        self::assertSame('Limpar filtros', trim($crawler->filter('#a1Limpar')->text()));

        // Google no estilo dos cartões, no fim, com os ids que o JS usa.
        self::assertCount(1, $crawler->filter('.a1-google .a1-google-col > button#a1Google'));
        self::assertSame('GOOGLE AGENDA', trim($crawler->filter('.a1-google > .a1-rotulo')->text()));
    }

    #[TestDox('/agenda não leva nada que o desenho não tem: .card, .modal, mini calendário, calendários, testar lembretes, feriados')]
    public function testSemOQueODesenhoNaoTem(): void
    {
        $crawler = $this->abrir();

        self::assertCount(0, $crawler->filter('.a1 .card'));
        self::assertCount(0, $crawler->filter('.a1 .modal'));
        self::assertCount(0, $crawler->filter('#agMini'));
        self::assertCount(0, $crawler->filter('#agCals'));
        self::assertCount(0, $crawler->filter('.ag-teste'));
        self::assertCount(0, $crawler->filter('.ag-lado'));
        self::assertCount(0, $crawler->filter('.ag-pf'));
        self::assertCount(0, $crawler->filter('#agEscopos'));
        self::assertCount(0, $crawler->filter('#agCtx'));
        self::assertCount(0, $crawler->filter('[data-url-datas], [data-url-acrescentar], [data-url-ocultar], [data-url-feriados]'));
    }

    #[TestDox('/agenda: raiz com os endereços da janela e do Google, camadas num invólucro próprio e os dois CSS e o JS da tela')]
    public function testRaizCamadasEAssets(): void
    {
        $crawler = $this->abrir();

        $raiz = $crawler->filter('div.a1#a1Pagina');
        self::assertCount(1, $raiz);
        foreach (['url-compromissos', 'url-criar', 'url-salvar', 'url-excluir', 'url-avisar', 'url-avisar-prazo', 'url-status', 'url-pasta', 'url-google-desconectar', 'url-google-aviso-dispensar', 'google', 'google-aviso', 'escritorio', 'eu', 'equipe', 'pode-ver-pastas'] as $nome) {
            self::assertNotNull($raiz->getNode(0)->attributes->getNamedItem('data-' . $nome), 'falta data-' . $nome);
        }
        self::assertStringContainsString('/agenda/compromissos', (string) $raiz->attr('data-url-compromissos'));

        // Camadas fora do layout da tela antiga: um .ag-page só para elas, dentro da raiz.
        self::assertCount(1, $crawler->filter('.a1 > .ag-page.a1-camadas > #agModal'));
        self::assertCount(1, $crawler->filter('.a1 > .ag-page.a1-camadas > #agPerg'));
        self::assertCount(1, $crawler->filter('.a1 > .ag-page.a1-camadas > #agToast'));
        self::assertCount(0, $crawler->filter('.ag-page > .ag-corpo'));

        self::assertCount(1, $crawler->filter('link[href*="css/agenda-nova.css"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/a1-agenda.css"]'));
        self::assertCount(1, $crawler->filter('script[src*="js/agenda-1a.js"]'));
        self::assertCount(0, $crawler->filter('script[src*="js/agenda-nova.js"]'));
        self::assertStringContainsString('a1-body', (string) $crawler->filter('body')->attr('class'));
        self::assertStringContainsString('agenda.js.um_a.legenda.aud', implode(' ', $crawler->filter('script[data-bj-t]')->each(static fn ($s): string => $s->text(null, false))));
    }

    #[TestDox('Os arquivos da tela não levam travessão (regra do dono)')]
    public function testSemTravessao(): void
    {
        $app = \dirname(__DIR__, 3);
        foreach (['public/js/agenda-1a.js', 'public/css/a1-agenda.css', 'templates/agenda/um_a.html.twig'] as $arquivo) {
            self::assertDoesNotMatchRegularExpression('/[\x{2014}\x{2013}]/u', (string) file_get_contents($app . '/' . $arquivo), $arquivo);
        }
    }

    private function abrir(): \Symfony\Component\DomCrawler\Crawler
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant), $tenant);

        $crawler = $client->request('GET', '/agenda/1a');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant A1 ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('a1_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }
}
