<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Ouvir" os toques do alerta de ponto no Meu Perfil (pedido do Samuel, 06/11/2026): logo abaixo da chave
 * "Sons de alerta", um botão para cada toque (ida ao intervalo, volta do intervalo, fim da jornada). O desenho
 * 07 não tem esta linha; o visual segue os controles do perfil (`mp-btn-contorno`). Só aparece para quem recebe
 * o alerta de ponto (o mesmo critério do modal em base.html.twig: módulo ponto e jornada no escritório).
 */
#[Group('ponto')]
final class OuvirSonsDoPontoNoPerfilTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const LINHA = '.mp-prefs > [data-mp-pref="perfil.sons"] + .mp-ouvir';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->cliente();
    }

    /** @return array{0: User, 1: Tenant} */
    private function colaborador(bool $comJornada): array
    {
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'ana');
        if ($comJornada) {
            $jornada = new JornadaColaborador();
            $jornada->setUser($ana)->setTenant($tenant);
            $this->em()->persist($jornada);
            $this->em()->flush();
        }

        return [$ana, $tenant];
    }

    private function perfil(User $quem, Tenant $tenant): Crawler
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $tela = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    #[TestDox('Com jornada: logo abaixo de "Sons de alerta", três botões Ouvir, um por toque, e o script do som')]
    public function testComJornada(): void
    {
        [$ana, $tenant] = $this->colaborador(true);

        $tela = $this->perfil($ana, $tenant);

        self::assertCount(1, $tela->filter(self::LINHA), 'logo depois da chave Sons de alerta');
        $tipos = $tela->filter(self::LINHA . ' > button.mp-btn-contorno.mp-btn-ouvir[data-ouvir-som]')
            ->each(static fn (Crawler $b): string => (string) $b->attr('data-ouvir-som'));
        self::assertSame(['repouso', 'retorno', 'saida'], $tipos);
        self::assertSame('mp-ouvir-rotulo', $tela->filter(self::LINHA)->attr('aria-labelledby'), 'o grupo diz que os botões são para ouvir');
        self::assertStringContainsString('Ida ao intervalo', $tela->filter(self::LINHA)->text());
        self::assertStringContainsString('Volta do intervalo', $tela->filter(self::LINHA)->text());
        self::assertStringContainsString('Fim da jornada', $tela->filter(self::LINHA)->text());
        self::assertGreaterThanOrEqual(1, $tela->filter('script[src*="/js/ponto-alerta-som.js"]')->count());
    }

    #[TestDox('Sem jornada (não recebe o alerta de ponto): sem a linha Ouvir')]
    public function testSemJornada(): void
    {
        [$ana, $tenant] = $this->colaborador(false);

        $tela = $this->perfil($ana, $tenant);

        self::assertCount(0, $tela->filter('.mp-ouvir'));
        self::assertCount(0, $tela->filter('script[src*="/js/ponto-alerta-som.js"]'), 'sem alerta de ponto, sem o script do som');
        self::assertCount(1, $tela->filter('[data-mp-pref="perfil.sons"]'), 'a chave continua');
    }
}
