<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Pasta\Twig\ExclusaoDaMetaExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Tenant\Support\DesligaEdicaoSemPrazo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PMC-028 e PMC-030 — o ⋮ da meta na aba Metas ganha "Copiar link" (URL ABSOLUTA de
 * `tarefa_show`, pelo `.js-ps-copiar`) e "Excluir" (POST `tarefa_excluir`, com confirm(), sem
 * "Desfazer"), este só quando `ExcluirTarefaUseCase::podeExcluir` deixa e nunca na lápide.
 *
 * Exclusão pela lista com o token REAL da página: prova que o id do token no template é o que o
 * `TarefaController::excluir` confere.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(TarefaController::class)]
#[CoversClass(ExclusaoDaMetaExtension::class)]
#[Group('pasta')]
final class MetaCopiarLinkExcluirTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarColega(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('meta_link_excl_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel meta ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    private function criarMeta(Pasta $pasta, User $autor, string $titulo = 'Juntar procuração'): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+5 days midnight'));
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($autor);
        $meta->addResponsavel($autor);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    /** @return array{KernelBrowser, User, Tenant, Pasta} */
    private function cenario(): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setCriadoPor($user);
        $this->em()->flush();

        return [$client, $user, $tenant, $pasta];
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function menu(Crawler $crawler, Tarefa $meta): Crawler
    {
        return $crawler->filter('.ps-metas-lista > article.ps-meta[data-meta-id="' . $meta->getId() . '"] > .ps-meta-acoes > #psMetaMenu' . $meta->getId());
    }

    #[TestDox('Copiar link: item com o ícone de link e a URL ABSOLUTA da própria meta, aviso "Link da meta copiado"')]
    public function testCopiarLink(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $meta  = $this->criarMeta($pasta, $user);
        $outra = $this->criarMeta($pasta, $user, 'Outra meta');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $item = $this->menu($crawler, $meta)->filter('button.ps-pop-item.js-ps-copiar');
        self::assertCount(1, $item);
        self::assertSame('http://localhost/tarefas/' . $meta->getId(), $item->attr('data-ps-copiar'));
        self::assertSame('Link da meta copiado', $item->attr('data-ps-copiado'));
        self::assertSame('Copiar link', trim($item->text()));
        self::assertCount(1, $item->filter('i.bi.bi-link-45deg'));

        // Cada meta copia o PRÓPRIO link.
        self::assertSame(
            'http://localhost/tarefas/' . $outra->getId(),
            $this->menu($crawler, $outra)->filter('button.js-ps-copiar')->attr('data-ps-copiar'),
        );
    }

    #[TestDox('Excluir: só na meta do próprio criador, por último, depois do separador, vermelho, com confirm() e POST tarefa_excluir')]
    public function testExcluirSoParaOCriador(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $minha = $this->criarMeta($pasta, $user);
        $colega = $this->criarColega($tenant, 'Colega Autora');
        $alheia = $this->criarMeta($pasta, $colega, 'Meta da colega');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $menu  = $this->menu($crawler, $minha);
        $forms = $menu->filter('#psMetaMenu' . $minha->getId() . ' > form.ps-meta-excluir');
        self::assertCount(1, $forms);
        self::assertSame('/tarefas/' . $minha->getId() . '/excluir', $forms->attr('action'));
        self::assertStringContainsString('confirm(', (string) $forms->attr('onsubmit'));
        $botao = $forms->filter('button[type="submit"].ps-pop-item.ps-pop-item--perigo');
        self::assertSame('Excluir', trim($botao->text()));
        self::assertCount(1, $botao->filter('i.bi.bi-trash3'));

        $filhos = $menu->children()->each(static fn (Crawler $n) => $n->nodeName() . '.' . (string) $n->attr('class'));
        self::assertSame('form.ps-meta-excluir', end($filhos), 'Excluir é o último item');
        self::assertSame('div.ps-pop-sep', $filhos[count($filhos) - 2], 'depois do separador');

        self::assertCount(0, $botao->filter('.selo-sem-prazo'), 'pela regra de sempre, sem selo');

        // Meta de outra pessoa: o admin daqui é o Master, que exclui pelo poder sem prazo, com o selo (spec edicao-sem-prazo).
        self::assertCount(1, $this->menu($crawler, $alheia)->filter('form.ps-meta-excluir > button.com-selo-sem-prazo > .selo-sem-prazo[data-selo-sem-prazo="master"]'));
    }

    #[TestDox('Edição sem prazo: o primário vê Excluir com o selo na meta alheia (opção ligada) e não vê com a opção desligada')]
    public function testPrimarioConformeAOpcao(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $meta = $this->criarMeta($pasta, $user);
        $primaria = $this->criarColega($tenant, 'Paula Primária');

        $this->logarComTenant($client, $primaria, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $selo = $this->menu($crawler, $meta)->filter('form.ps-meta-excluir > button.com-selo-sem-prazo > .selo-sem-prazo[data-selo-sem-prazo="primario"]');
        self::assertCount(1, $selo);
        self::assertSame('EXCLUSIVO DO PRIMÁRIO', trim($selo->text()));
        self::assertCount(1, $selo->filter('i.bi.bi-star-fill'));

        DesligaEdicaoSemPrazo::no($this->em(), $this->em()->find(Tenant::class, $tenant->getId()) ?? $tenant, static::getContainer());
        $crawler = $this->abrir($client, $pasta);
        self::assertCount(0, $this->menu($crawler, $meta)->filter('form.ps-meta-excluir'));
    }

    #[TestDox('Excluir: meta fora da janela de exclusão só aparece pelo poder sem prazo (o Master), com o selo')]
    public function testExcluirForaDaJanela(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $velha = $this->criarMeta($pasta, $user);
        $this->em()->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = :d WHERE id = :id',
            ['d' => '2020-01-01 00:00:00', 'id' => $velha->getId()],
        );

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $this->menu($crawler, $velha));
        // O admin daqui é o Master: fora da janela o item continua, pelo poder sem prazo e com o selo.
        self::assertCount(1, $this->menu($crawler, $velha)->filter('form.ps-meta-excluir > button.com-selo-sem-prazo > .selo-sem-prazo'));
    }

    #[TestDox('Excluir pela lista (token real): a meta some do banco e a tela volta para a aba Metas')]
    public function testExcluirPelaLista(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $meta = $this->criarMeta($pasta, $user);
        $id   = (int) $meta->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $client->submit($this->menu($crawler, $meta)->filter('form.ps-meta-excluir')->form());

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tarefa WHERE id = :id', ['id' => $id]));
    }

    #[TestDox('Pasta excluída (lápide): sem Excluir; Copiar link continua (é leitura)')]
    public function testLapide(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $meta = $this->criarMeta($pasta, $user);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(0, $this->menu($crawler, $meta)->filter('form.ps-meta-excluir'));
        self::assertCount(1, $this->menu($crawler, $meta)->filter('button.js-ps-copiar'));
    }

    #[TestDox('Sem o módulo Tarefas: a linha aparece, sem ⋮ — nem Copiar link nem Excluir')]
    public function testSemModuloTarefas(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $meta    = $this->criarMeta($pasta, $user);
        $soPasta = $this->criarUsuarioSemPermissaoDoModulo($tenant);

        $this->logarComTenant($client, $soPasta, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('article.ps-meta[data-meta-id="' . $meta->getId() . '"]'));
        self::assertCount(0, $crawler->filter('article.ps-meta[data-meta-id="' . $meta->getId() . '"] .js-ps-copiar'));
        self::assertCount(0, $crawler->filter('form.ps-meta-excluir'));
    }
}
