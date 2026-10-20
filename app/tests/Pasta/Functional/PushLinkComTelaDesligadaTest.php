<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

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

        $client->request('POST', "/pasta/{$pasta->getId()}/push/links/{$link->getId()}/revogar", $token, server: $json);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->em()->find(LinkExternoDoPush::class, $link->getId())?->estaRevogado());
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
}
