<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PMC-060 — na pasta excluída (lápide) a meta não oferece escrita.
 *
 * O `PastaSomenteLeituraListener` recusa concluir, reabrir, renomear e alertar a meta da lápide
 * (a meta é filha da pasta); a tela não pode oferecer o que o servidor recusa. No ⋮ e no rodapé
 * do drawer "Relatório da meta" ficam só as leituras (Abrir relatório, Copiar link, Abrir em
 * Metas) e o sino "Alertar para verificar" some. A pasta viva, com o mesmo cenário, continua com
 * tudo — é ela que prova que o teste da lápide não passa por falta de fixture.
 *
 * A aba devolvida por XHR (`ListaDeMetasDaPastaEmJson`) renderiza o MESMO `_metas.html.twig`;
 * na lápide ela nem é montada, porque o listener recusa o pedido antes (coberto em
 * `AlternarMetaPorXhrTest::testConcluirPorXhrNaLapide`).
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class MetaNaLapideSemEscritaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarColega(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('pmc060_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel PMC-060 ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    private function criarMeta(Pasta $pasta, User $autor, User $colega, string $status, string $titulo): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+5 days midnight'));
        $meta->setStatus($status);
        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $meta->setDataConclusao(new \DateTimeImmutable('-1 hour'));
        }
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        $meta->addResponsavel($autor);
        // Um responsável que não é o próprio usuário: sem ele o sino nem é desenhado.
        $meta->addResponsavel($colega);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    /** @return array{Crawler, Tarefa, Tarefa} página aberta, meta aberta, meta concluída */
    private function abrirPasta(bool $excluida): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setCriadoPor($user);
        $this->em()->flush();

        $colega    = $this->criarColega($tenant, 'Bruno Colega');
        $aberta    = $this->criarMeta($pasta, $user, $colega, Tarefa::STATUS_PENDENTE, 'Juntar procuração');
        $concluida = $this->criarMeta($pasta, $user, $colega, Tarefa::STATUS_CONCLUIDA, 'Protocolar recurso');

        if ($excluida) {
            $pasta->marcarExcluida($user, new \DateTimeImmutable());
            $this->em()->flush();
        }

        $this->logarComTenant($client, $user, $tenant);

        return [$this->abrir($client, $pasta), $aberta, $concluida];
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function linha(Crawler $crawler, Tarefa $meta): Crawler
    {
        return $crawler->filter('[data-ps-metas] .ps-metas-lista > article.ps-meta[data-meta-id="' . $meta->getId() . '"]');
    }

    private function menu(Crawler $crawler, Tarefa $meta): Crawler
    {
        return $this->linha($crawler, $meta)->filter('.ps-meta-acoes > #psMetaMenu' . $meta->getId());
    }

    private function rodapeDoDrawer(Crawler $crawler, Tarefa $meta): Crawler
    {
        return $crawler->filter('#psMetaRelatorio .ps-mrel-meta[data-meta-rel="' . $meta->getId() . '"] .ps-mrel-rodape');
    }

    #[TestDox('Lápide: o ⋮ da meta não tem Concluir, Reabrir nem Editar nome; o sino some; ficam Abrir relatório, Copiar link e Abrir em Metas')]
    public function testLapideSemEscritaNoMenu(): void
    {
        [$crawler, $aberta, $concluida] = $this->abrirPasta(true);

        foreach ([$aberta, $concluida] as $meta) {
            $menu = $this->menu($crawler, $meta);
            self::assertCount(1, $menu, 'o ⋮ continua: ele também tem as leituras');
            self::assertCount(0, $menu->filter('form'), 'nenhum formulário de escrita no ⋮ da lápide');
            self::assertCount(0, $menu->filter('[data-ps-meta-renomear]'));
            self::assertCount(1, $menu->filter('[data-ps-meta-relatorio="' . $meta->getId() . '"]'));
            self::assertCount(1, $menu->filter('button.js-ps-copiar'));
            self::assertCount(1, $menu->filter('a[href="/tarefas/' . $meta->getId() . '"]'));

            $linha = $this->linha($crawler, $meta);
            self::assertCount(0, $linha->filter('.ps-meta-sino-wrap'), 'o sino faz POST tarefa_alertar, recusado na lápide');
            self::assertCount(0, $linha->filter('form.ps-meta-renomear'));
        }

        self::assertCount(0, $crawler->filter('[data-ps-metas] form, #psMetaRelatorio form'), 'nenhum formulário na aba Metas nem no drawer da lápide');
    }

    #[TestDox('Lápide: o rodapé do drawer "Relatório da meta" fica só com "Abrir em Metas"')]
    public function testLapideSemEscritaNoDrawer(): void
    {
        [$crawler, $aberta, $concluida] = $this->abrirPasta(true);

        foreach ([$aberta, $concluida] as $meta) {
            $rodape = $this->rodapeDoDrawer($crawler, $meta);
            self::assertCount(1, $rodape);
            self::assertCount(0, $rodape->filter('form'));
            self::assertCount(1, $rodape->filter('a.ps-mrel-modulo[href="/tarefas/' . $meta->getId() . '"]'));
        }
    }

    #[TestDox('PMC-062: na lápide o cabeçalho da aba Metas não oferece "Nova meta"')]
    public function testLapideSemNovaMetaNoCabecalho(): void
    {
        [$crawler] = $this->abrirPasta(true);

        $cab = $crawler->filter('[data-ps-metas] > .ps-card-cab');
        self::assertCount(1, $cab);
        self::assertCount(0, $cab->filter('button[data-bs-target="#modalCriarTarefa"]'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalCriarTarefa"]'), 'nenhum gatilho do modal Nova meta na lápide');
    }

    #[TestDox('PMC-062: na pasta viva o cabeçalho da aba Metas tem "Nova meta", que abre o modal')]
    public function testPastaVivaComNovaMetaNoCabecalho(): void
    {
        [$crawler] = $this->abrirPasta(false);

        $botao = $crawler->filter('[data-ps-metas] > .ps-card-cab > button.ps-btn--primario[data-bs-toggle="modal"][data-bs-target="#modalCriarTarefa"]');
        self::assertCount(1, $botao);
        self::assertStringContainsString('Nova meta', $botao->text());
    }

    #[TestDox('Pasta viva: o ⋮ tem Concluir (aberta) / Reabrir (concluída) e Editar nome, o sino aparece na aberta e o drawer tem o alternar')]
    public function testPastaVivaComEscrita(): void
    {
        [$crawler, $aberta, $concluida] = $this->abrirPasta(false);

        self::assertCount(1, $this->menu($crawler, $aberta)->filter('form.ps-meta-concluir[action="/tarefas/' . $aberta->getId() . '/concluir"]'));
        self::assertCount(1, $this->menu($crawler, $concluida)->filter('form.ps-meta-reabrir[action="/tarefas/' . $concluida->getId() . '/reabrir"]'));
        self::assertCount(1, $this->menu($crawler, $aberta)->filter('[data-ps-meta-renomear]'));
        self::assertCount(1, $this->linha($crawler, $aberta)->filter('form.ps-meta-renomear'));
        self::assertCount(1, $this->linha($crawler, $aberta)->filter('.ps-meta-sino-wrap form[action="/tarefas/' . $aberta->getId() . '/alertar"]'));

        self::assertCount(1, $this->rodapeDoDrawer($crawler, $aberta)->filter('form.ps-mrel-alternar-form[action$="/concluir"]'));
        self::assertCount(1, $this->rodapeDoDrawer($crawler, $concluida)->filter('form.ps-mrel-alternar-form[action$="/reabrir"]'));
    }
}
