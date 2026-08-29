<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\DjenController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O botão "Voltar" da publicação leva de volta à página de onde se veio — a pasta, a listagem com
 * os filtros aplicados, ou o que for.
 *
 * A origem é dado de fora (query string e cabeçalho Referer), então só caminho INTERNO é aceito.
 * Sem essa trava, `?voltar=https://site-falso/` faria a nossa tela exibir um botão "Voltar" que
 * leva o usuário para fora do sistema — com a credibilidade do sistema emprestada.
 */
#[CoversClass(DjenController::class)]
#[Group('djen')]
final class PushProcessualVoltarTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;

    #[TestDox('Sem origem nenhuma, volta para a lista de publicações')]
    public function testSemOrigemVoltaParaALista(): void
    {
        [$client, $id] = $this->abrirPublicacao();

        $crawler = $client->request('GET', '/push-processual/' . $id);

        self::assertSame('/push-processual', $crawler->filter('#push-voltar')->attr('href'));
    }

    #[TestDox('Com ?voltar= interno, volta exatamente para lá — inclusive com o fragmento da aba')]
    public function testVoltarInternoEhRespeitado(): void
    {
        [$client, $id] = $this->abrirPublicacao();

        $crawler = $client->request('GET', '/push-processual/' . $id . '?voltar=' . urlencode('/pasta/223#push'));

        self::assertSame('/pasta/223#push', $crawler->filter('#push-voltar')->attr('href'));
    }

    #[TestDox('Endereço de outro site em ?voltar= é recusado')]
    public function testVoltarExternoEhRecusado(): void
    {
        [$client, $id] = $this->abrirPublicacao();

        foreach (['https://site-falso.example/', '//site-falso.example/', '/\\site-falso.example'] as $hostil) {
            $crawler = $client->request('GET', '/push-processual/' . $id . '?voltar=' . urlencode($hostil));

            self::assertSame(
                '/push-processual',
                $crawler->filter('#push-voltar')->attr('href'),
                sprintf('"%s" não pode virar destino do botão Voltar', $hostil),
            );
        }
    }

    #[TestDox('Sem ?voltar=, a origem vem do Referer — a listagem volta com os filtros')]
    public function testRefererInternoViraDestino(): void
    {
        [$client, $id] = $this->abrirPublicacao();

        $crawler = $client->request(
            'GET',
            '/push-processual/' . $id,
            server: ['HTTP_REFERER' => 'http://localhost/push-processual?tribunal=TRT10&pagina=2'],
        );

        self::assertSame('/push-processual?tribunal=TRT10&pagina=2', $crawler->filter('#push-voltar')->attr('href'));
    }

    #[TestDox('Referer de outro host é ignorado')]
    public function testRefererDeOutroHostEhIgnorado(): void
    {
        [$client, $id] = $this->abrirPublicacao();

        $crawler = $client->request(
            'GET',
            '/push-processual/' . $id,
            server: ['HTTP_REFERER' => 'https://site-falso.example/pasta/223'],
        );

        self::assertSame('/push-processual', $crawler->filter('#push-voltar')->attr('href'));
    }

    #[TestDox('Voltar para a própria publicação não é volta nenhuma: cai na lista')]
    public function testNaoVoltaParaSiMesma(): void
    {
        [$client, $id] = $this->abrirPublicacao();

        $crawler = $client->request(
            'GET',
            '/push-processual/' . $id,
            server: ['HTTP_REFERER' => 'http://localhost/push-processual/' . $id],
        );

        self::assertSame('/push-processual', $crawler->filter('#push-voltar')->attr('href'));
    }

    /** @return array{\Symfony\Bundle\FrameworkBundle\KernelBrowser, int} */
    private function abrirPublicacao(): array
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'voltar_' . uniqid() . '@test.com');
        $pub    = $this->criarPublicacao($tenant, '990001', '07011345720258070007');
        $id     = (int) $pub->getId();
        $this->limparIdentityMap();
        $this->logarComTenant($client, $gestor, $tenant);

        return [$client, $id];
    }
}
