<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\Twig\PreferenciasDoPerfilExtension;
use App\Tests\Dashboard\Functional\DashboardWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Sons de alerta" do Meu Perfil (desenho "07 - Meu Perfil", VS 18.10.26, chave `som`) chega aos três
 * lugares que tocam som: cada elemento raiz que carrega o JS leva `data-perfil-sons` ("1" ligado, "0"
 * desligado) e o JS lê o atributo do próprio elemento. O /dashboard tem os três na mesma página:
 * lançador da Central (central-flutuante.js), presença no cabeçalho (presenca-cabecalho.js) e o
 * `.db-page` do calendário (dashboard-filtros.js).
 *
 * Que o som de fato não toca é smoke do dono; aqui se trava o atributo no nó certo e a leitura no JS.
 */
#[CoversClass(PreferenciasDoPerfilExtension::class)]
final class SonsDoPerfilTelaTest extends DashboardWebTestCase
{
    private const RAIZ_CENTRAL   = 'body [data-central-flutuante]';
    private const RAIZ_PRESENCA  = '.menu-usuario > .presenca-cab[data-presenca-cabecalho]';
    private const RAIZ_DASHBOARD = 'section.db-page';

    private function repo(): PreferenciaDoUsuarioRepository
    {
        return static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
    }

    #[TestDox('Padrão (nada gravado): os três elementos raiz do /dashboard saem com data-perfil-sons="1"')]
    public function testPadraoLigadoNosTres(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $tela = $this->abrir($client, '/dashboard');

        self::assertSame(['1', '1', '1'], $this->valores($tela));
        $this->assertScriptsCarregados($tela);
    }

    #[TestDox('perfil.sons desligado: os três elementos raiz do /dashboard saem com data-perfil-sons="0"')]
    public function testDesligadoNosTres(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoPerfil::SONS, false);

        $tela = $this->abrir($client, '/dashboard');

        self::assertSame(['0', '0', '0'], $this->valores($tela));
        $this->assertScriptsCarregados($tela);
    }

    #[TestDox('O perfil desligado não mexe na opção "Sons" do Dashboard: as duas valem juntas')]
    public function testPerfilNaoMexeNoDashboardSons(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoPerfil::SONS, false);

        $tela = $this->abrir($client, '/dashboard');

        self::assertSame('db-page', $tela->filter(self::RAIZ_DASHBOARD)->attr('class'));
    }

    #[TestDox('Fora do Dashboard (Meu Perfil): o lançador e a presença também levam o valor da pessoa')]
    public function testOutraPagina(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $tela = $this->abrir($client, '/perfil');
        self::assertSame('1', $tela->filter(self::RAIZ_CENTRAL)->attr('data-perfil-sons'));
        self::assertSame('1', $tela->filter(self::RAIZ_PRESENCA)->attr('data-perfil-sons'));

        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoPerfil::SONS, false);
        $tela = $this->abrir($client, '/perfil');
        self::assertSame('0', $tela->filter(self::RAIZ_CENTRAL)->attr('data-perfil-sons'));
        self::assertSame('0', $tela->filter(self::RAIZ_PRESENCA)->attr('data-perfil-sons'));
    }

    #[TestDox('Desligado por um colega ou em outro escritório não silencia a minha tela')]
    public function testNaoVaza(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $colega = $this->criarColaborador($tenant, 'Colega Silenciosa');
        $this->repo()->gravar($tenant, $colega, CatalogoDePreferenciasDoPerfil::SONS, false);
        $outro = $this->criarTenant();
        $this->repo()->gravar($outro, $user, CatalogoDePreferenciasDoPerfil::SONS, false);

        self::assertSame(['1', '1', '1'], $this->valores($this->abrir($client, '/dashboard')));
    }

    #[TestDox('Meu Perfil renderiza com o include do selo de apoiador mesmo sem o parcial do Contribua')]
    public function testMeuPerfilSemParcialDoSelo(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $tela = $this->abrir($client, '/perfil');

        self::assertCount(1, $tela->filter('.mp-card[data-screen-label="Preferências"]'));
        self::assertCount(1, $tela->filter('.mp-card[data-screen-label="Segurança"]'));
    }

    #[TestDox('Cada JS lê data-perfil-sons do próprio elemento raiz antes de tocar')]
    public function testJsLeOAtributo(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/public/js/';

        $central = (string) file_get_contents($dir . 'central-flutuante.js');
        self::assertStringContainsString("raizLancador.getAttribute('data-perfil-sons') !== '0'", $central);
        self::assertStringContainsString('if (!perfilSons || !prefs.som', $central);

        $presenca = (string) file_get_contents($dir . 'presenca-cabecalho.js');
        self::assertStringContainsString("raiz.getAttribute('data-perfil-sons') !== '0'", $presenca);
        self::assertStringContainsString('if (!perfilSons || !somLigado())', $presenca);

        $filtros = (string) file_get_contents($dir . 'dashboard-filtros.js');
        self::assertStringContainsString('.db-page[data-perfil-sons="0"]', $filtros);
    }

    private function abrir(KernelBrowser $client, string $url): Crawler
    {
        $tela = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /** @return list<string|null> central, presença, dashboard */
    private function valores(Crawler $tela): array
    {
        $valores = [];
        foreach ([self::RAIZ_CENTRAL, self::RAIZ_PRESENCA, self::RAIZ_DASHBOARD] as $seletor) {
            self::assertCount(1, $tela->filter($seletor), $seletor);
            $valores[] = $tela->filter($seletor)->attr('data-perfil-sons');
        }

        return $valores;
    }

    private function assertScriptsCarregados(Crawler $tela): void
    {
        foreach (['central-flutuante.js', 'presenca-cabecalho.js', 'dashboard-filtros.js'] as $js) {
            self::assertCount(1, $tela->filter('script[src*="/js/' . $js . '"]'), $js);
        }
    }
}
