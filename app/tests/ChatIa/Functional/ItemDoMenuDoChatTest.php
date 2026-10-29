<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\TestDox;

/** O item "BlueJus IA" do menu lateral leva a /chat-ia e só aparece a quem tem o módulo de inteligência. */
final class ItemDoMenuDoChatTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    #[TestDox('Com o módulo de inteligência o menu mostra "BlueJus IA" apontando para /chat-ia; sem o módulo, não')]
    public function testItemSoParaQuemTemAcesso(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $com = $this->criarUsuarioComPermissoes($tenant, ['modules.expediente.view', 'modules.inteligencia.view'], 'com');
        $sem = $this->criarUsuarioComPermissoes($tenant, ['modules.expediente.view'], 'sem');

        $this->logarComTenant($client, $com, $tenant);
        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('.pje-menu-list a.pje-menu-link[href="/chat-ia"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('BlueJus IA', $link->text());
        self::assertCount(1, $link->filter('i.bi-stars'));

        $this->logarComTenant($client, $sem, $tenant);
        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pje-menu-list a[href="/chat-ia"]'));
    }
}
