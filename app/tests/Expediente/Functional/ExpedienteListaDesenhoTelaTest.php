<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E8 da frente de fidelidade (`docs/specs/fidelidade-designer-1310/E8-PLANO.md`): a Lista do Expediente no
 * desenho de 13/10 (`03 - EXPEDIENTE Lista 1.1.dc.html`), SÓ visual. Estes testes travam a marcação de que o
 * CSS e o JS dependem (`.exp-body`, barra, chips) e, principalmente, que as regras de sempre continuam: rótulo
 * da prioridade do sistema, duplo clique da situação, total de metas, vários marcadores, a janela da E7 na página.
 * Aparência (cor, hover, colunas) é da comparação na tela.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteListaDesenhoTelaTest extends JusPrimeWebTestCase
{
    #[TestDox('casca: <body> com exp-body, o CSS da Lista por último, a janela de confirmação da E7 (uma só) e os scripts dela e dos menus')]
    public function testCasca(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);

        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('exp-body', (string) $crawler->filter('body')->attr('class'));
        self::assertCount(1, $crawler->filter('link[href$="/css/expediente-lista.css"]'));
        self::assertCount(1, $crawler->filter('#psConfirmar[hidden] .ps-cf[role="alertdialog"][aria-modal="true"]'));
        self::assertCount(1, $crawler->filter('script[src$="/js/pasta-dialogos.js"]'));
        self::assertCount(1, $crawler->filter('script[src$="/js/expediente-lista.js"]'));
        self::assertCount(1, $crawler->filter('h1.exp-titulo > .exp-titulo-icone[aria-hidden="true"]'));
        self::assertSame('Expediente', trim($crawler->filter('h1.exp-titulo')->text()));
    }

    #[TestDox('acervo: barra do desenho (título + Nova Pasta que abre o modal de sempre) e resumo "página de N · Total: X pastas" contando a consulta inteira')]
    public function testBarraEResumo(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $this->criarPasta($tenant, 'BARRA-' . uniqid());
        $this->criarPasta($tenant, 'BARRA-' . uniqid());

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#acervo-geral-wrapper.exp-painel > .exp-barra > h2.exp-barra-titulo'));
        self::assertSame('Acervo geral', trim($crawler->filter('.exp-barra-titulo')->text()));
        $nova = $crawler->filter('.exp-barra > button.exp-nova-pasta');
        self::assertSame('#modalNovaPasta', $nova->attr('data-bs-target'), 'o mesmo modal de sempre — cliente opcional, número no servidor');
        self::assertSame('modal', $nova->attr('data-bs-toggle'));
        self::assertMatchesRegularExpression('/^1 de 1 · Total: 2 pastas$/', trim((string) preg_replace('/\s+/', ' ', $crawler->filter('.exp-resumo')->text())));
        // Com 1 página a paginação do topo (a do desenho) continua visível; a do rodapé (só do BlueJus)
        // só aparece com mais de uma página — uma só na tela.
        self::assertCount(1, $crawler->filter('nav[aria-label="Paginação"] .page-item.active'));
    }

    #[TestDox('linha: prioridade com o rótulo do SISTEMA ("Prioridade", não "Alta") e bandeira; situação mantém o duplo clique; metas mostram o TOTAL')]
    public function testChipsPreservamAsRegras(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $pasta = $this->criarPasta($tenant, 'CHIP-' . uniqid(), PrioridadePasta::Prioridade);

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        $linha = $crawler->filter('#tabelaPastas tbody tr.pasta-row-link')->reduce(static fn ($tr): bool => str_contains($tr->text(), $pasta->getNup()));
        self::assertCount(1, $linha);

        $prio = $linha->filter('span.exp-prio');
        self::assertSame('prioridade', $prio->attr('data-prio'));
        self::assertSame('Prioridade', trim($prio->text()));
        self::assertCount(1, $prio->filter('i.bi-flag-fill'));

        $sit = $linha->filter('.js-toggle-situacao');
        self::assertSame('ativo', $sit->attr('data-situacao'));
        self::assertNotEmpty($sit->attr('data-url'), 'duplo clique preservado');
        self::assertNotEmpty($sit->attr('data-csrf'));

        self::assertCount(1, $linha->filter('.exp-sem-metas'), 'sem metas: o traço do desenho');
    }

    #[TestDox('marcadores: vários por pasta continuam (chip + "+N" + botão), o chip ganha a pasta do desenho e o nome de todos no title')]
    public function testVariosMarcadores(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = new Marcador('Alfa ' . uniqid(), $tenant, $admin);
        $b = new Marcador('Beta ' . uniqid(), $tenant, $admin);
        $em->persist($a);
        $em->persist($b);
        $pasta = $this->criarPasta($tenant, 'MARC-' . uniqid());
        $pasta->addMarcador($a);
        $pasta->addMarcador($b);
        $em->flush();

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        $col = $crawler->filter('td.pasta-marcadores-col[data-pasta-id="' . $pasta->getId() . '"]');
        self::assertCount(1, $col->filter('.marcador-principal > i.bi-folder-fill'));
        self::assertSame('+1', trim($col->filter('.marcadores-extra')->text()));
        self::assertCount(1, $col->filter('button.js-mover-para'));
        $title = (string) $col->filter('.marcador-principal')->attr('title');
        self::assertStringContainsString($a->getNome(), $title);
        self::assertStringContainsString($b->getNome(), $title);
    }

    #[TestDox('painel do marcador: a barra traz o nome do marcador e o total dele; vazio no texto do desenho')]
    public function testPainelDoMarcadorEVazio(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $m = new Marcador('Gama ' . uniqid(), $tenant, $admin);
        $em->persist($m);
        $em->flush();

        $crawler = $client->xmlHttpRequest('GET', '/expediente/marcador/' . $m->getId() . '/pastas');
        self::assertResponseIsSuccessful();
        self::assertSame($m->getNome(), trim($crawler->filter('#painel-marcador-wrapper.exp-painel .exp-barra-marcador')->text()));
        self::assertSame('0 pastas', trim($crawler->filter('.exp-barra-total')->text()));
        self::assertStringContainsString('Nenhuma pasta', trim($crawler->filter('.exp-vazio')->text()));
        self::assertCount(1, $crawler->filter('.exp-vazio > i.bi-folder-x'));
    }

    /** @return array{0: User, 1: Tenant} */
    private function entrar($client): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lista ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('lista_' . uniqid() . '@test.com');
        $user->setFullName('Admin Lista');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $nup, ?PrioridadePasta $prioridade = null): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        if ($prioridade !== null) {
            $pasta->setPrioridade($prioridade);
        }
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }
}
