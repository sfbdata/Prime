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
use App\Tarefa\Controller\MetaNaListaController;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L9 da Trilha B — na tela: o ⋮ da meta com "Marcar como concluída" / "Reabrir meta" e
 * "Editar nome", o formulário de renomear como filho direto da linha (fora da âncora) e
 * o sino "Alertar para verificar" antes do ⋮, só em meta aberta.
 *
 * Os envios usam o token REAL da sessão (submit do formulário renderizado): prova que o
 * id do token no template é o mesmo que o MetaNaListaController confere.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(MetaNaListaController::class)]
#[Group('pasta')]
final class MetaNaListaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarPastaDe(Tenant $tenant, User $criador): Pasta
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setCriadoPor($criador);
        $this->em()->flush();

        return $pasta;
    }

    private function criarColega(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('metas_l9_tela_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel L9 ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    /** @param User[] $responsaveis */
    private function criarMeta(Pasta $pasta, User $autor, array $responsaveis, string $status, string $titulo = 'Juntar procuração'): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+5 days midnight'));
        $meta->setStatus($status);
        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $meta->setDataConclusao(new \DateTimeImmutable('-1 day'));
        }
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        foreach ($responsaveis as $r) {
            $meta->addResponsavel($r);
        }
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    private function abrir(object $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function linha(Crawler $crawler, Tarefa $meta): Crawler
    {
        $linha = $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $meta->getId() . '"]');
        self::assertCount(1, $linha);

        return $linha;
    }

    private function metaNoBanco(int $id): Tarefa
    {
        $filtros = $this->em()->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
        $this->em()->clear();
        $meta = $this->em()->find(Tarefa::class, $id);
        self::assertNotNull($meta);

        return $meta;
    }

    // =========================================================================
    // ⋮ da meta
    // =========================================================================

    #[TestDox('⋮ da meta aberta: "Marcar como concluída" e "Editar nome"; da concluída: "Reabrir meta" (POST tarefa_reabrir) e "Editar nome"')]
    public function testItensDoMenu(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $aberta          = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE);
        $concluida       = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_CONCLUIDA);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $menuAberta = $this->linha($crawler, $aberta)->filter('article > .ps-meta-acoes > #psMetaMenu' . $aberta->getId() . '.ps-pop');
        self::assertSame(
            ['Marcar como concluída', 'Editar nome'],
            $menuAberta->filter('.ps-pop > form > button.ps-pop-item, .ps-pop > button.ps-pop-item')->each(static fn (Crawler $b) => trim($b->text())),
        );
        self::assertSame((string) $aberta->getId(), $menuAberta->filter('.ps-pop > button[type="button"][data-ps-meta-renomear]')->attr('data-ps-meta-renomear'));
        self::assertCount(0, $menuAberta->filter('form.ps-meta-reabrir'));

        $menuConcluida = $this->linha($crawler, $concluida)->filter('article > .ps-meta-acoes > #psMetaMenu' . $concluida->getId() . '.ps-pop');
        self::assertSame(
            ['Reabrir meta', 'Editar nome'],
            $menuConcluida->filter('.ps-pop > form > button.ps-pop-item, .ps-pop > button.ps-pop-item')->each(static fn (Crawler $b) => trim($b->text())),
        );
        $reabrir = $menuConcluida->filter('.ps-pop > form.ps-meta-reabrir');
        self::assertSame('post', strtolower((string) $reabrir->attr('method')));
        self::assertSame('/tarefas/' . $concluida->getId() . '/reabrir', $reabrir->attr('action'));
        self::assertCount(1, $reabrir->filter('i.bi-arrow-counterclockwise'));
        self::assertCount(0, $menuConcluida->filter('form.ps-meta-concluir'));
    }

    #[TestDox('reabrir pela lista (token real): a meta volta a aberta, sem data de conclusão, e a linha passa a oferecer concluir')]
    public function testReabrirPelaLista(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $meta            = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_CONCLUIDA);
        $id              = (int) $meta->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $client->submit($crawler->filter('#psMetaMenu' . $id . ' form.ps-meta-reabrir')->form());

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        $doBanco = $this->metaNoBanco($id);
        self::assertSame(Tarefa::STATUS_PENDENTE, $doBanco->getStatus());
        self::assertNull($doBanco->getDataConclusao());

        $crawler = $this->abrir($client, $pasta);
        $linha   = $this->linha($crawler, $doBanco);
        self::assertSame('aberta', $linha->attr('data-meta-estado'));
        self::assertCount(1, $linha->filter('form.ps-meta-concluir'));
    }

    // =========================================================================
    // Renomear na lista
    // =========================================================================

    #[TestDox('renomear: o formulário é FILHO DIRETO da linha (fora da âncora), escondido, com o nome atual, Salvar e Cancelar')]
    public function testFormularioDeRenomear(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $meta            = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE, 'Juntar "procuração" nova');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $linha   = $this->linha($crawler, $meta);

        $form = $linha->filter('article > form.ps-meta-renomear');
        self::assertCount(1, $form);
        self::assertNotNull($form->attr('hidden'), 'só aparece quando "Editar nome" é clicado');
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame('/tarefas/' . $meta->getId() . '/renomear', $form->attr('action'));
        self::assertCount(0, $linha->filter('a.ps-meta-abrir form, a.ps-meta-abrir input'), 'nada de campo dentro do link');

        $campo = $form->filter('form > input[type="text"][name="titulo"].ps-meta-ren-campo');
        self::assertSame('Juntar "procuração" nova', $campo->attr('value'));
        self::assertSame('160', $campo->attr('maxlength'), 'limite do desenho');
        self::assertNotNull($campo->attr('required'));
        self::assertSame('Salvar', trim($form->filter('form > button[type="submit"].ps-meta-ren-salvar')->text()));
        self::assertSame('Cancelar', trim($form->filter('form > button[type="button"].ps-meta-ren-cancelar')->text()));
    }

    #[TestDox('renomear pela lista (token real): grava o novo nome e a linha mostra o nome novo')]
    public function testRenomearPelaLista(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $meta            = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE);
        $id              = (int) $meta->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $form    = $this->linha($crawler, $meta)->filter('article > form.ps-meta-renomear')->form(['titulo' => 'Juntar procuração atualizada']);
        $client->submit($form);

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        $doBanco = $this->metaNoBanco($id);
        self::assertSame('Juntar procuração atualizada', $doBanco->getTitulo());

        $crawler = $this->abrir($client, $pasta);
        self::assertSame('Juntar procuração atualizada', trim($this->linha($crawler, $doBanco)->filter('.ps-meta-titulo > .ps-meta-titulo-tx')->text()));
    }

    // =========================================================================
    // Sino "Alertar para verificar"
    // =========================================================================

    #[TestDox('sino: filho direto da linha, ANTES do ⋮, só em meta aberta; lista os responsáveis sem o próprio usuário, um POST por pessoa')]
    public function testSino(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $bruno           = $this->criarColega($tenant, 'Bruno Responsável');
        $aberta          = $this->criarMeta($pasta, $user, [$user, $bruno], Tarefa::STATUS_PENDENTE);
        $concluida       = $this->criarMeta($pasta, $user, [$bruno], Tarefa::STATUS_CONCLUIDA);
        $soEu            = $this->criarMeta($pasta, $user, [$user], Tarefa::STATUS_PENDENTE);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $linha = $this->linha($crawler, $aberta);
        $filhos = $linha->filter('article > *')->each(static fn (Crawler $n) => (string) $n->attr('class'));
        $posSino = array_search('ps-pop-wrap ps-meta-sino-wrap', $filhos, true);
        $posMenu = array_search('ps-pop-wrap ps-meta-acoes', $filhos, true);
        self::assertNotFalse($posSino, 'o sino é filho direto da linha');
        self::assertNotFalse($posMenu);
        self::assertLessThan($posMenu, $posSino, 'o sino vem antes do ⋮ (desenho)');

        $botao = $linha->filter('article > .ps-meta-sino-wrap > button.ps-meta-sino');
        self::assertSame('Alertar para verificar', $botao->attr('title'));
        self::assertSame('psMetaSino' . $aberta->getId(), $botao->attr('data-ps-pop'));

        $forms = $linha->filter('.ps-meta-sino-wrap > #psMetaSino' . $aberta->getId() . '.ps-pop > form.ps-meta-alertar');
        self::assertCount(1, $forms, 'só o Bruno: o próprio usuário não aparece');
        self::assertSame('/tarefas/' . $aberta->getId() . '/alertar', $forms->attr('action'));
        self::assertSame((string) $bruno->getId(), $forms->filter('input[type="hidden"][name="usuario"]')->attr('value'));
        self::assertSame('Bruno Responsável · responsável', trim($forms->filter('button[type="submit"].ps-pop-item')->text()));

        self::assertCount(0, $this->linha($crawler, $concluida)->filter('.ps-meta-sino'), 'concluída não tem sino');
        self::assertCount(0, $this->linha($crawler, $soEu)->filter('.ps-meta-sino'), 'sem ninguém a alertar, sem sino');
    }

    #[TestDox('sino pela lista (token real): o responsável recebe a notificação')]
    public function testAlertarPelaLista(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaDe($tenant, $user);
        $bruno           = $this->criarColega($tenant, 'Bruno Responsável');
        $meta            = $this->criarMeta($pasta, $user, [$bruno], Tarefa::STATUS_PENDENTE);
        $id              = (int) $meta->getId();
        $brunoId         = (int) $bruno->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $client->submit($crawler->filter('#psMetaSino' . $id . ' form.ps-meta-alertar')->form());

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notificacao WHERE tarefa_id = :t AND usuario_id = :u AND tipo = :tipo',
            ['t' => $id, 'u' => $brunoId, 'tipo' => AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO],
        ));
    }
}
