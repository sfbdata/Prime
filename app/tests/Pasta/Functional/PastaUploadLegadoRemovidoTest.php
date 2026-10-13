<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Routing\RouterInterface;

/**
 * DOC-124 (inventario-documentos.md, §9 #7): o upload legado da pasta saiu — o bloco
 * `{% if false %}` da pasta_show, o JS que só operava sobre ele e a rota `pasta_documento_upload`
 * (que nem gravava `enviado_por`). A aba Documentos envia por `pasta_peticionar_upload`.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaUploadLegadoRemovidoTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('a rota pasta_documento_upload não existe mais e o POST antigo não chega a controller nenhum')]
    public function testRotaLegadaRemovida(): void
    {
        $client = static::createClient();
        $rotas  = static::getContainer()->get(RouterInterface::class)->getRouteCollection();
        self::assertNull($rotas->get('pasta_documento_upload'));
        self::assertNotNull($rotas->get('pasta_peticionar_upload'), 'o caminho vivo continua');

        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/' . $pasta->getId() . '/documento/upload');
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
    }

    #[TestDox('a pasta_show não carrega mais o bloco desativado nem o JS legado de anexos e seções')]
    public function testTemplateSemOLegado(): void
    {
        $fonte = (string) file_get_contents(__DIR__ . '/../../../templates/pasta/show.html.twig');
        foreach (['{% if false %}', 'btnAdicionarAnexo', 'btnMostrarNovaSecao', 'geralDropArea', '/documento/upload', 'upload geral legado'] as $legado) {
            self::assertStringNotContainsString($legado, $fonte, $legado);
        }

        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#documentos [data-url-upload$="/peticionar/upload"]'), 'a aba Documentos segue com o upload vivo');
    }
}
