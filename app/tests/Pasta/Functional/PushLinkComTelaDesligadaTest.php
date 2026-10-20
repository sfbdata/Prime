<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Master\EventListener\BloqueioDeRecursoDesativado;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Link externo do Push com a tela `pushpub` desligada pelo Master (spec perfis-1.2-telas-e-recursos, R5/R6):
 * revogar continua liberado (tirar acesso nunca trava), gerar e renovar seguem barrados; o menu do botão
 * direito da aba Push some para quem não é Master; o Master vê a faixa vermelha também na página pública.
 */
#[CoversClass(BloqueioDeRecursoDesativado::class)]
#[Group('pasta')]
final class PushLinkComTelaDesligadaTest extends JusPrimeWebTestCase
{
    use CriaLinkExternoDoPushTrait;
    use CriaUsuarioComPermissoesTrait;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function desligarPushPublico(): void
    {
        $estado = new RecursoEstado('pushpub');
        $estado->desativar('Revisão ética', null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();
    }

    #[TestDox('Tela desligada: quem não é Master revoga o link (200), mas gerar e renovar recebem 503')]
    public function testRevogarLiberadoGerarERenovarBarrados(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $comum = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        [$pasta, $processo] = $this->criarPastaComPush($tenant);
        $link = $this->criarLink($tenant, $pasta, $processo, $admin);
        $expirado = $this->criarLink($tenant, $pasta, $processo, $admin, criadoHa: '-40 days');
        $this->desligarPushPublico();

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $comum, $tenant);
        $token = ['_token' => 'TOKEN_pasta_push_link_' . $pasta->getId()];
        $json = ['HTTP_ACCEPT' => 'application/json'];

        $client->request('POST', "/pasta/{$pasta->getId()}/push/links", $token, server: $json);
        self::assertResponseStatusCodeSame(503, 'gerar segue a tela');

        $client->request('POST', "/pasta/{$pasta->getId()}/push/links/{$expirado->getId()}/renovar", $token, server: $json);
        self::assertResponseStatusCodeSame(503, 'renovar segue a tela');

        $client->request('GET', "/pasta/{$pasta->getId()}/push/links", server: $json);
        self::assertResponseStatusCodeSame(503, 'listar segue a tela');

        $client->request('POST', "/pasta/{$pasta->getId()}/push/links/{$expirado->getId()}/recusar", $token, server: $json);
        self::assertResponseStatusCodeSame(503, 'recusar segue a tela (não estende acesso)');

        $client->request('POST', "/pasta/{$pasta->getId()}/push/links/{$link->getId()}/revogar", $token, server: $json);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['ok' => true, 'id' => $link->getId(), 'revogado' => true],
            json_decode((string) $client->getResponse()->getContent(), true),
            'só a confirmação: sem endereço, código, acessos nem pedido de renovação',
        );
        self::assertTrue($this->em()->find(LinkExternoDoPush::class, $link->getId())?->estaRevogado());
    }

    #[TestDox('Tela desligada: o revogar aberto continua com as guardas (outro escritório 404, CSRF inválido 403)')]
    public function testRevogarAbertoMantemAsGuardas(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $comum = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        [$pasta, $processo] = $this->criarPastaComPush($tenant);
        $link = $this->criarLink($tenant, $pasta, $processo, $admin);
        [, $outroTenant] = $this->criarAdmin();
        $deFora = $this->criarUsuarioComPermissoes($outroTenant, ['resources.pasta.view']);
        $this->desligarPushPublico();
        $this->instalarCsrfPrevisivel();
        $json = ['HTTP_ACCEPT' => 'application/json'];

        $this->logarComTenant($client, $deFora, $outroTenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/links/{$link->getId()}/revogar", ['_token' => 'TOKEN_pasta_push_link_' . $pasta->getId()], server: $json);
        self::assertResponseStatusCodeSame(404, 'pasta de outro escritório');

        $this->logarComTenant($client, $comum, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/links/{$link->getId()}/revogar", ['_token' => 'invalido'], server: $json);
        self::assertResponseStatusCodeSame(403, 'CSRF inválido');

        self::assertFalse($this->em()->find(LinkExternoDoPush::class, $link->getId())?->estaRevogado());
    }

    #[TestDox('Tela desligada: o #pushLinkMenu some para quem não é Master e fica marcado para o Master')]
    public function testMenuDoBotaoDireito(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $comum = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        [$pasta] = $this->criarPastaComPush($tenant);

        $this->logarComTenant($client, $comum, $tenant);
        $ligada = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $ligada->filter('body #pushLinkMenu.ppl-menu'), 'tela ligada: o menu existe');

        $this->desligarPushPublico();
        $desligada = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();
        self::assertCount(0, $desligada->filter('#pushLinkMenu'));
        self::assertCount(0, $desligada->filter('#pastaTabs > #push-tab[data-ppl-menu]'), 'a aba não anuncia o menu que não existe');
        self::assertCount(1, $desligada->filter('#pastaTabs > #push-tab'), 'a aba Push continua');
        self::assertCount(0, $desligada->filter('.js-ppl-abrir'), 'o item do ⋮ some pela mesma regra');

        // O primário (papel de sistema) também não é Master: o menu some para ele.
        $primario = $this->criarPrimario($tenant);
        $this->logarComTenant($client, $primario, $tenant);
        $doPrimario = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();
        self::assertCount(0, $doPrimario->filter('#pushLinkMenu'));
        self::assertCount(0, $doPrimario->filter('#pastaTabs > #push-tab[data-ppl-menu]'));

        $this->logarComTenant($client, $admin, $tenant);
        $master = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $master->filter('body #pushLinkMenu.ppl-menu.bj-recurso-off'));
        self::assertCount(1, $master->filter('#pastaTabs > #push-tab[data-ppl-menu="pushLinkMenu"]'));
    }

    #[TestDox('Página pública com a tela desligada: o Master vê a faixa vermelha; com a tela ligada, não há faixa')]
    public function testFaixaDoMasterNaPaginaPublica(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPush($tenant);
        $link = $this->criarLink($tenant, $pasta, $processo, $admin);

        $this->logarComTenant($client, $admin, $tenant);
        $ligada = $client->request('GET', '/p/' . $link->getEndereco());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $ligada->filter('[data-recurso-faixa]'));

        $this->desligarPushPublico();
        $desligada = $client->request('GET', '/p/' . $link->getEndereco());
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Esta tela está desativada para os usuários. Só o Master está vendo.',
            $desligada->filter('body.pc-corpo > [data-recurso-faixa].bj-recurso-faixa')->text(),
        );
    }

    private function criarPrimario(Tenant $tenant): User
    {
        $tenant = $this->em()->find(Tenant::class, $tenant->getId());
        self::assertNotNull($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Primário ' . uniqid())->setIsSystem(true);
        $user = (new User())->setEmail('prim_' . uniqid() . '@test.com')->setFullName('Primário da Conta')->setRoles(['ROLE_USER'])->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($papel);
        $this->em()->persist($user);
        $this->em()->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $this->em()->flush();

        return $user;
    }
}
