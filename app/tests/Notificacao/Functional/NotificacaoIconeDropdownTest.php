<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Controller\NotificacaoController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Inteligencia\MessageHandler\ProcessarAnaliseDeInteligenciaHandler;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * FPN-036 na lista do sino: o alerta de meta sai com o sino laranja e a análise de IA concluída
 * com o documento analisado, como no desenho — e não mais com o sino cinza do `default`.
 */
#[CoversClass(NotificacaoController::class)]
#[Group('notificacao')]
final class NotificacaoIconeDropdownTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarUsuario(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Ícone ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('notif_icone_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Ícone');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
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

        return [$user, $tenant];
    }

    private function notificar(User $user, Tenant $tenant, string $tipo, string $titulo): void
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $notif = new Notificacao();
        $notif->setUsuario($user);
        $notif->setTenant($tenant);
        $notif->setTipo($tipo);
        $notif->setTitulo($titulo);
        $notif->setMensagem($titulo . ' (mensagem)');
        $notif->setUrl('/pasta/1');
        $em->persist($notif);
        $em->flush();
    }

    #[TestDox('No dropdown do sino, cada item leva o ícone e a cor do tipo, ao lado do título')]
    public function testIconesNoDropdown(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuario();
        $this->notificar($user, $tenant, AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, 'Alerta para verificar a meta');
        $this->notificar($user, $tenant, ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO, 'Análise da IA concluída');

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/notificacoes/lista-dropdown');
        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        $html  = new Crawler('<div id="raiz">' . (string) $dados['html'] . '</div>');

        $alerta = $html->filter('#raiz > a.notif-item')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'Alerta para verificar a meta'));
        self::assertCount(1, $alerta);
        self::assertCount(1, $alerta->filter('a.notif-item > i.bi.bi-bell-fill.text-orange.notif-icon'));

        $ia = $html->filter('#raiz > a.notif-item')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'Análise da IA concluída'));
        self::assertCount(1, $ia);
        self::assertCount(1, $ia->filter('a.notif-item > i.bi.bi-file-earmark-check.text-primary.notif-icon'));

        self::assertStringNotContainsString('bi-bell text-secondary', (string) $dados['html'], 'nenhum dos dois cai mais no sino cinza');
    }

    #[TestDox('C6-FPN-02: na página /notificacoes o alerta de meta leva o sino com text-orange, dentro do .notif-ico da linha')]
    public function testIconeLaranjaNaPagina(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuario();
        $this->notificar($user, $tenant, AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, 'Alerta para verificar a meta');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/notificacoes');
        self::assertResponseIsSuccessful();

        $linha = $crawler->filter('.notif-row')->reduce(static fn (Crawler $r): bool => str_contains($r->text(), 'Alerta para verificar a meta'));
        self::assertCount(1, $linha);
        // Na página o <i> não tem `.notif-icon`: a cor vem da regra geral `.text-orange` do app.css
        // (o `NotificacaoTextOrangeCssTest` trava que ela existe fora do escopo do dropdown).
        self::assertCount(1, $linha->filter('.notif-row > span.notif-ico > i.bi.bi-bell-fill.text-orange'));
    }
}
