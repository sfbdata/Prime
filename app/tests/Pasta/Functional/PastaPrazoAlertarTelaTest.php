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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PAS-038 — o ⋮ do prazo no trilho da aba Dados ganha "Alertar <primeiro nome>" (desenho dc:6151),
 * com o MESMO POST do sino das Metas (`tarefa_alertar`, token `alertar_tarefa_<id>`).
 *
 * Aparece para quem tem o módulo Tarefas (a guarda do sino), some para quem não tem e some na
 * pasta excluída (lápide, somente leitura). O envio usa o token REAL da página.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(MetaNaListaController::class)]
#[Group('pasta')]
final class PastaPrazoAlertarTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarColega(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('prazo_alertar_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel prazo ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    /** @param User[] $responsaveis */
    private function criarMeta(Pasta $pasta, User $autor, array $responsaveis): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Protocolar contestação');
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+2 days midnight'));
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
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

    /** @return array{KernelBrowser, User, Tenant, Pasta, User, Tarefa} */
    private function cenario(): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setCriadoPor($user);
        $this->em()->flush();
        $jessica = $this->criarColega($tenant, 'Jéssica Martins');
        $meta    = $this->criarMeta($pasta, $user, [$user, $jessica]);

        return [$client, $user, $tenant, $pasta, $jessica, $meta];
    }

    private function menuDoPrazo(KernelBrowser $client, Pasta $pasta, Tarefa $meta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('[data-trilho="prazos"] .ps-prazo[data-prazo-id="' . $meta->getId() . '"] #psPrazoMenu' . $meta->getId());
        self::assertCount(1, $menu, 'o ⋮ do prazo existe');

        return $menu;
    }

    #[TestDox('⋮ do prazo: "Alertar <primeiro nome>" com sino, logo depois de "Ver nas metas", POST tarefa_alertar para o responsável (sem o próprio usuário)')]
    public function testItemNoMenuDoPrazo(): void
    {
        [$client, $user, $tenant, $pasta, $jessica, $meta] = $this->cenario();

        $this->logarComTenant($client, $user, $tenant);
        $menu = $this->menuDoPrazo($client, $pasta, $meta);

        $forms = $menu->filter('#psPrazoMenu' . $meta->getId() . ' > form.ps-prazo-alertar');
        self::assertCount(1, $forms, 'um item por responsável, sem quem está logado');
        self::assertSame('/tarefas/' . $meta->getId() . '/alertar', $forms->attr('action'));
        self::assertSame('post', strtolower((string) $forms->attr('method')));
        self::assertSame((string) $jessica->getId(), $forms->filter('input[name="usuario"]')->attr('value'));
        self::assertNotSame('', (string) $forms->filter('input[name="_token"]')->attr('value'));
        self::assertSame('Alertar Jéssica', trim($forms->filter('button.ps-pop-item')->text()));
        self::assertCount(1, $forms->filter('button.ps-pop-item > i.bi.bi-bell'));

        // Ordem do desenho: Ver nas metas · Alertar · separador · .ics · Copiar.
        $filhos = $menu->children()->each(static fn (Crawler $n) => $n->nodeName() . '.' . (string) $n->attr('class'));
        $posVer     = array_search('button.ps-pop-item', $filhos, true);
        $posAlertar = array_search('form.ps-prazo-alertar', $filhos, true);
        $posSep     = array_search('div.ps-pop-sep', $filhos, true);
        self::assertNotFalse($posVer);
        self::assertNotFalse($posAlertar);
        self::assertNotFalse($posSep);
        self::assertGreaterThan($posVer, $posAlertar);
        self::assertLessThan($posSep, $posAlertar);
    }

    #[TestDox('Alertar pelo ⋮ do prazo (token real): a responsável recebe a notificação e a tela volta para as metas')]
    public function testAlertarPeloPrazo(): void
    {
        [$client, $user, $tenant, $pasta, $jessica, $meta] = $this->cenario();
        $id        = (int) $meta->getId();
        $jessicaId = (int) $jessica->getId();

        $this->logarComTenant($client, $user, $tenant);
        $menu = $this->menuDoPrazo($client, $pasta, $meta);
        $client->submit($menu->filter('form.ps-prazo-alertar')->form());

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#tarefas');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notificacao WHERE tarefa_id = :t AND usuario_id = :u AND tipo = :tipo',
            ['t' => $id, 'u' => $jessicaId, 'tipo' => AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO],
        ));
    }

    #[TestDox('Sem o módulo Tarefas: o prazo aparece, mas sem "Alertar"')]
    public function testSemModuloTarefasNaoAparece(): void
    {
        [$client, , $tenant, $pasta, , $meta] = $this->cenario();
        $soPasta = $this->criarUsuarioSemPermissaoDoModulo($tenant);

        $this->logarComTenant($client, $soPasta, $tenant);
        $menu = $this->menuDoPrazo($client, $pasta, $meta);

        self::assertCount(0, $menu->filter('form.ps-prazo-alertar'));
        self::assertStringNotContainsString('/tarefas/' . $meta->getId() . '/alertar', $menu->html());
    }

    #[TestDox('Pasta excluída (lápide): o prazo aparece, mas sem "Alertar"')]
    public function testLapideNaoAparece(): void
    {
        [$client, $user, $tenant, $pasta, , $meta] = $this->cenario();
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $menu = $this->menuDoPrazo($client, $pasta, $meta);

        self::assertCount(0, $menu->filter('form.ps-prazo-alertar'));
    }
}
