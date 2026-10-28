<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\ChatIa\Controller\ChatIaPaginaController;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/** A página `/chat-ia` (layout próprio, dados em data-*, aviso sem chave, isolamento da conversa aberta pela URL). */
#[CoversClass(ChatIaPaginaController::class)]
final class PaginaDoChatTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: \App\Entity\Tenant\Tenant, 2: \App\Entity\Auth\User, 3: \App\Entity\Auth\User} */
    private function cenario(): array
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'bruno');

        return [$client, $tenant, $ana, $bruno];
    }

    #[TestDox('GET /chat-ia logado: 200, layout próprio (sem AdminLTE), data-* esperados e assets da página')]
    public function testPaginaAbre(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/chat-ia');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['data-urls=', 'data-csrf=', 'data-modelos=', 'data-modelo-padrao=', 'data-nome=', 'data-conversa-id=', 'data-sem-chave=', 'data-aviso=', 'data-textos='] as $atributo) {
            self::assertStringContainsString($atributo, $html);
        }
        self::assertStringContainsString('__ID__', $html);
        self::assertStringContainsString('css/chat-ia.css', $html);
        self::assertStringContainsString('js/chat-ia.js', $html);
        self::assertStringContainsString('prefers-color-scheme: dark', $html);
        self::assertStringContainsString('Novo chat', $html);
        self::assertStringNotContainsString('adminlte', strtolower($html));
        self::assertStringNotContainsString('app-sidebar', $html);
        self::assertStringNotContainsString('bootstrap', strtolower($html));
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    #[TestDox('O template é standalone: não estende base.html.twig')]
    public function testTemplateStandalone(): void
    {
        $twig = (string) file_get_contents(__DIR__ . '/../../../templates/chat_ia/pagina.html.twig');
        self::assertStringNotContainsString('base.html.twig', $twig);
        self::assertStringNotContainsString('{% extends', $twig);
        self::assertStringContainsString('<!DOCTYPE html>', $twig);
        self::assertStringNotContainsString('adminlte', strtolower($twig));
    }

    #[TestDox('Sem chave de IA: a página abre com o banner "A IA ainda não está conectada"')]
    public function testSemChaveMostraAviso(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $this->provedorFalso()->desconfigurar();

        $client->request('GET', '/chat-ia');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-sem-chave="1"', $html);
        self::assertStringContainsString('A IA ainda não está conectada', $html);
    }

    #[TestDox('Conversa aberta pela URL: a própria abre (200, data-conversa-id); a de outra pessoa ou de outro escritório é 404')]
    public function testConversaPelaUrl(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/chat-ia/conversas', []);
        $id = (int) $this->json($client)['id'];

        $client->request('GET', "/chat-ia/$id");
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-conversa-id="' . $id . '"', (string) $client->getResponse()->getContent());

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', "/chat-ia/$id");
        self::assertResponseStatusCodeSame(404);

        [$admin2, $tenant2] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant2, $admin2);
        $carla = $this->criarUsuarioComPermissoes($tenant2, ['modules.inteligencia.view'], 'carla');
        $this->logarComTenant($client, $carla, $tenant2);
        $client->request('GET', "/chat-ia/$id");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem login, /chat-ia redireciona para o login')]
    public function testSemLogin(): void
    {
        $client = $this->cliente();

        $client->request('GET', '/chat-ia');

        self::assertResponseRedirects();
    }
}
