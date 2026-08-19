<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * As URLs antigas `/djen*` não podem morrer com a renomeação para "Push Processual".
 *
 * O motivo é medido, não hipotético: em 19/08/2026 a produção tinha **199 notificações com
 * `url = '/djen'` gravada na linha** (a mais recente do próprio dia). O path é persistido no momento
 * em que a notificação é criada, não montado na hora de exibir — então, sem estas rotas, cada uma
 * dessas 199 vira 404 no clique. Favoritos e links compartilhados caem no mesmo buraco.
 *
 * 🔑 Todo teste aqui loga primeiro, e isso não é ruído de setup: o firewall intercepta **antes** da
 * rota, então deslogado o que se observa em `/djen` é o 302 para `/login`, nunca o 301. É também o
 * caminho real do usuário — quem clica na notificação está autenticado. (Para quem não estiver, o
 * `/djen` vira target path e o 301 acontece depois do login, no mesmo fluxo.)
 *
 * Estes testes falham se alguém apagar `RotasLegadasDjenController` achando que é código morto.
 */
final class RotasLegadasDjenControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;

    /**
     * Usuário SEM acesso ao módulo de propósito: o redirect não confere permissão, e provar isso aqui
     * documenta a escolha — quem barra é o destino.
     */
    private function logarUsuarioQualquer(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $tenant = $this->criarTenant();
        $usuario = $this->criarUsuarioComum($tenant);
        $this->logarComTenant($client, $usuario, $tenant);
        $this->limparIdentityMap();
    }

    #[Test]
    public function djenRedirecionaPermanentementeParaPushProcessual(): void
    {
        $client = static::createClient();
        $this->logarUsuarioQualquer($client);

        $client->request('GET', '/djen');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/push-processual');
    }

    #[Test]
    public function djenOabsRedirecionaPermanentemente(): void
    {
        $client = static::createClient();
        $this->logarUsuarioQualquer($client);

        $client->request('GET', '/djen/oabs');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/push-processual/oabs');
    }

    #[Test]
    public function djenShowRedirecionaPreservandoOId(): void
    {
        $client = static::createClient();
        $this->logarUsuarioQualquer($client);

        $client->request('GET', '/djen/4242');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/push-processual/4242');
    }

    /**
     * Um favorito raramente é a URL pura: costuma carregar filtro e página. Se a query se perdesse no
     * redirect, o usuário cairia numa listagem sem filtro nenhum, que é pior que o 404 porque parece
     * ter funcionado.
     */
    #[Test]
    public function djenPreservaAQueryStringNoRedirect(): void
    {
        $client = static::createClient();
        $this->logarUsuarioQualquer($client);

        $client->request('GET', '/djen?tribunal=TJDFT&pagina=3');

        self::assertResponseStatusCodeSame(301);
        $destino = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/push-processual', $destino);
        self::assertStringContainsString('tribunal=TJDFT', $destino);
        self::assertStringContainsString('pagina=3', $destino);
    }

    /**
     * O redirect é aberto de propósito (não confere permissão), mas isso não pode virar um bypass:
     * quem chega ao destino sem acesso ao módulo tem de ser barrado lá, como sempre foi.
     */
    #[Test]
    public function redirectNaoContornaAGuardaDeAcesso(): void
    {
        $client = static::createClient();
        $this->logarUsuarioQualquer($client);

        $client->request('GET', '/djen');
        self::assertResponseStatusCodeSame(301);

        $client->followRedirect();

        // Sem acesso ao módulo, o destino redireciona para fora — nunca entrega a listagem.
        self::assertResponseStatusCodeSame(302);
        self::assertStringNotContainsString('/push-processual', (string) $client->getResponse()->headers->get('Location'));
    }
}
