<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Som do alerta de ponto (pedido do Samuel, 05/11): o alerta que já aparece em toda página
 * (`_modal_alerta_ponto`, consulta a cada 60 s em /ponto/alerta-horario) toca um som quando chega a hora
 * de voltar do intervalo (`retorno`) e a de ir embora (`saida`). Só esses dois tipos. Respeita "Sons de
 * alerta" do Meu Perfil (`data-perfil-sons` no modal) e o `alertaHabilitado` da jornada (sem ele, o servidor
 * não alerta e nada toca). Som bloqueado pelo navegador fica só no modal.
 *
 * Que o som de fato toca é smoke do dono; aqui se travam o atributo no nó certo, o script e as regras no JS.
 */
#[Group('ponto')]
final class SomDoAlertaDePontoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const MODAL = 'body #modalAlertaPonto';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->cliente();
    }

    /** @return array{0: User, 1: Tenant} */
    private function colaboradorComJornada(): array
    {
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'ana');
        $jornada = new JornadaColaborador();
        $jornada->setUser($ana)->setTenant($tenant);
        $this->em()->persist($jornada);
        $this->em()->flush();

        return [$ana, $tenant];
    }

    private function pagina(User $quem, Tenant $tenant): Crawler
    {
        $client = $this->client;
        $this->logarComTenant($client, $quem, $tenant);
        $tela = $client->request('GET', '/notificacoes');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    #[TestDox('Padrão: o modal do alerta sai com data-perfil-sons="1" e a página carrega o som do alerta')]
    public function testPadraoLigado(): void
    {
        [$ana, $tenant] = $this->colaboradorComJornada();

        $tela = $this->pagina($ana, $tenant);

        self::assertCount(1, $tela->filter(self::MODAL));
        self::assertSame('1', $tela->filter(self::MODAL)->attr('data-perfil-sons'));
        self::assertCount(1, $tela->filter('script[src*="/js/ponto-alerta-som.js"]'));
    }

    #[TestDox('"Sons de alerta" desligado no Meu Perfil: o modal sai com data-perfil-sons="0"')]
    public function testPerfilDesligado(): void
    {
        [$ana, $tenant] = $this->colaboradorComJornada();
        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)->gravar($tenant, $ana, CatalogoDePreferenciasDoPerfil::SONS, false);

        $tela = $this->pagina($ana, $tenant);

        self::assertSame('0', $tela->filter(self::MODAL)->attr('data-perfil-sons'));
    }

    #[TestDox('O JS toca só retorno e saída, lê o atributo do modal, espera a primeira interação e não deixa erro escapar')]
    public function testRegrasDoJs(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        $som = (string) file_get_contents($dir . '/public/js/ponto-alerta-som.js');

        self::assertStringContainsString("TIPOS_COM_SOM = { retorno:", $som);
        self::assertStringNotContainsString('entrada:', $som, 'a entrada não toca');
        self::assertStringNotContainsString('repouso:', $som, 'a ida ao intervalo não toca');
        self::assertStringContainsString("getAttribute('data-perfil-sons') !== '0'", $som);
        self::assertStringContainsString("addEventListener('pointerdown', liberarAudio", $som);
        self::assertStringContainsString('catch (e)', $som);

        $modal = (string) file_get_contents($dir . '/templates/ponto/_modal_alerta_ponto.html.twig');
        self::assertStringContainsString('window.BJSomDoAlertaDePonto', $modal, 'o modal chama o som ao abrir');
    }
}
