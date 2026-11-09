<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\ArquivosDaAssinaturaController;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A entrega do PDF e das logos contra o disco real (o dublê em memória não entrega). Os arquivos vão para
 * `<uploads_dir>/assinatura/<tenant>` do processo de teste e o diretório do escritório sai no `tearDown()`.
 */
#[CoversClass(ArquivosDaAssinaturaController::class)]
final class ArquivosDaAssinaturaControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;

    private ?string $diretorioDoEscritorio = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->csrfPrevisivel();
    }

    protected function tearDown(): void
    {
        if ($this->diretorioDoEscritorio !== null) {
            (new Filesystem())->remove($this->diretorioDoEscritorio);
        }
        parent::tearDown();
    }

    #[TestDox('o PDF, a logo do documento e a logo padrão abrem na tela; a cópia continua abrindo depois que o original sai')]
    public function testEntregaDosArquivos(): void
    {
        [, $tenant] = $this->entrar(true);
        $this->diretorioDoEscritorio = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/assinatura/' . $tenant->getId();
        $id = $this->salvar(null, ['nome' => 'Contrato', 'logo' => ['tipo' => 'propria'], 'usarLogoComoPadrao' => true], $this->pdf(), $this->png());
        self::assertResponseStatusCodeSame(201);
        self::assertDirectoryExists($this->diretorioDoEscritorio, 'o escritório tem diretório próprio');

        self::assertSame(self::PDF_MINIMO, $this->baixar('/assinatura/documentos/' . $id . '/arquivo'));
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(base64_decode(self::PNG_1X1, true), $this->baixar('/assinatura/documentos/' . $id . '/logo'));
        self::assertSame(base64_decode(self::PNG_1X1, true), $this->baixar('/assinatura/logo-padrao'));

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/duplicar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        $copia = (int) $this->json()['id'];
        $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$id]]);
        $this->postarJson('/assinatura/documentos/excluir', ['ids' => [$id]]);
        self::assertResponseIsSuccessful();

        self::assertSame(self::PDF_MINIMO, $this->baixar('/assinatura/documentos/' . $copia . '/arquivo'));
        self::assertSame(base64_decode(self::PNG_1X1, true), $this->baixar('/assinatura/documentos/' . $copia . '/logo'));
    }

    #[TestDox('documento sem PDF ou com a logo da BlueJus: 404 nas rotas de arquivo')]
    public function testSemArquivo(): void
    {
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Vazio']);

        $this->client->request('GET', '/assinatura/documentos/' . $id . '/arquivo');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/assinatura/documentos/' . $id . '/logo');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/assinatura/logo-padrao');
        self::assertResponseStatusCodeSame(404);
    }

    private function baixar(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }
}
