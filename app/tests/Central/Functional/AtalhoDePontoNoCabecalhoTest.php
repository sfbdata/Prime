<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Atalho de ponto na sua linha do painel de presença (spec `presenca-e-ponto-no-cabecalho.md` §3, risco ALTO): só com o
 * módulo ponto e jornada cadastrada (o critério do alerta de ponto), e nunca na própria tela do ponto.
 */
final class AtalhoDePontoNoCabecalhoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private function comJornada(User $user, Tenant $tenant): void
    {
        $jornada = new JornadaColaborador();
        $jornada->setUser($user)->setTenant($tenant);
        $this->em()->persist($jornada);
        $this->em()->flush();
    }

    #[TestDox('Módulo ponto e jornada: o atalho entra no selo, com o estado do dia e a batida de sempre, e carrega o módulo da tela do ponto')]
    public function testComModuloEJornada(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'ana');
        $this->comJornada($ana, $tenant);
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/notificacoes');
        self::assertResponseIsSuccessful();
        $raiz = $client->getCrawler()->filter('[data-presenca-cabecalho]');
        self::assertSame('/ponto/estado-do-dia', $raiz->attr('data-ponto-estado'));
        self::assertSame('/ponto/batida', $raiz->attr('data-ponto-batida'));
        self::assertCount(1, $client->getCrawler()->filter('#modalAlertaPonto'), 'com a chave ligada, o alerta de ponto entra');
        $scripts = $client->getCrawler()->filter('script[src]')->each(static fn ($s): string => (string) $s->attr('src'));
        $ordem = array_values(array_filter($scripts, static fn (string $s): bool => (bool) preg_match('#/js/(ponto-batida|presenca-ponto|presenca-cabecalho)\.js#', $s)));
        self::assertCount(3, $ordem);
        self::assertStringContainsString('ponto-batida.js', $ordem[0], 'o módulo da batida antes do atalho, e o atalho antes do painel');
        self::assertStringContainsString('presenca-ponto.js', $ordem[1]);
    }

    #[TestDox('Sem jornada, sem o módulo ou na própria tela do ponto: sem atalho (o selo continua)')]
    public function testSemAtalho(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $semJornada = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'semjornada');
        $semModulo = $this->colega($tenant, 'semmodulo');
        $this->comJornada($semModulo, $tenant);
        $naTela = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'natela');
        $this->comJornada($naTela, $tenant);

        foreach ([[$semJornada, '/notificacoes'], [$semModulo, '/notificacoes'], [$naTela, '/ponto/']] as [$quem, $url]) {
            $this->logarComTenant($client, $quem, $tenant);
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertCount(1, $client->getCrawler()->filter('[data-presenca-cabecalho]'), 'o selo continua: ' . $quem->getFullName());
            self::assertCount(0, $client->getCrawler()->filter('[data-presenca-cabecalho][data-ponto-estado]'), 'sem atalho: ' . $quem->getFullName());
            self::assertCount(0, $client->getCrawler()->filter('script[src*="/js/presenca-ponto.js"]'));
        }
    }

    #[TestDox('Chave "Registra ponto eletrônico" desligada no escritório: com módulo e jornada, sem atalho e sem o alerta de ponto')]
    public function testChaveDesligadaTiraAtalhoEAlerta(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $bia = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'bia');
        $this->comJornada($bia, $tenant);
        $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $bia, 'tenant' => $tenant])?->alterarRegistroDePonto(false);
        $this->em()->flush();
        $this->logarComTenant($client, $bia, $tenant);

        $client->request('GET', '/notificacoes');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('[data-presenca-cabecalho]'), 'o selo continua');
        self::assertCount(0, $client->getCrawler()->filter('[data-presenca-cabecalho][data-ponto-estado]'), 'sem atalho');
        self::assertCount(0, $client->getCrawler()->filter('#modalAlertaPonto'), 'sem o alerta de ponto');
    }
}
