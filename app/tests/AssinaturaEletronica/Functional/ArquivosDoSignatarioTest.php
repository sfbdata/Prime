<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\ArquivosDaAssinaturaController;
use App\AssinaturaEletronica\Controller\AssinaturaPublicaController;
use App\AssinaturaEletronica\UseCase\ObterArquivoDoSignatarioUseCase;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Os arquivos da folha pela rota pública (PDF, logo própria e rubrica) e a rubrica pela rota interna, contra o disco
 * real (o dublê em memória não entrega). Os arquivos vão para `<uploads_dir>/assinatura/<tenant>` do processo de teste
 * e o diretório do escritório sai no `tearDown()`.
 */
#[CoversClass(AssinaturaPublicaController::class)]
#[CoversClass(ArquivosDaAssinaturaController::class)]
#[CoversClass(ObterArquivoDoSignatarioUseCase::class)]
final class ArquivosDoSignatarioTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

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

    #[TestDox('pelo link: PDF, logo própria e rubrica de quem assinou abrem enquanto o signatário pode assinar')]
    public function testArquivosPeloLink(): void
    {
        [, $tenant] = $this->entrar(true);
        $this->diretorioDoEscritorio = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/assinatura/' . $tenant->getId();
        $id = $this->salvar(null, [
            'nome' => 'Contrato',
            'logo' => ['tipo' => 'propria'],
            'blocos' => [['k' => 'pg', 'n' => 1], ['k' => 'a', 'i' => 0, 'r' => '']],
            'signatarios' => [['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'], ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com']],
        ], $this->pdf(), $this->png());
        $tokens = self::tokens($this->enviar($id));
        $this->sair($this->client);

        $publico = $this->abrir((string) $tokens[1]);
        self::assertSame('/assinar/' . $tokens[1] . '/arquivo', $publico['documento']['arquivo']['url']);
        self::assertSame(self::PDF_MINIMO, $this->baixar((string) $publico['documento']['arquivo']['url']));
        $disposicao = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertSame('inline; filename=documento.pdf', $disposicao, 'nome neutro: o nome do arquivo do escritório não sai na rota pública');
        self::assertSame(base64_decode(self::PNG_1X1, true), $this->baixar((string) $publico['documento']['logo']['url']));

        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]), ['cpf' => '11144477735']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(base64_decode(self::PNG_1X1, true), $this->baixar('/assinar/' . $tokens[1] . '/rubrica/0'));
        $this->client->request('GET', '/assinar/' . $tokens[1] . '/rubrica/1');
        self::assertResponseStatusCodeSame(404, 'quem não assinou não tem rubrica');
        $this->client->request('GET', '/assinar/' . $tokens[0] . '/arquivo');
        self::assertResponseStatusCodeSame(404, 'quem já assinou não abre mais os arquivos');

        $this->entrar(true);
        $this->client->request('GET', '/assinatura/documentos/' . $id . '/rubrica/0');
        self::assertResponseStatusCodeSame(404, 'a rubrica interna é só do escritório do documento');
    }

    #[TestDox('a rubrica interna abre para o escritório do documento')]
    public function testRubricaInterna(): void
    {
        [, $tenant] = $this->entrar(true);
        $this->diretorioDoEscritorio = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/assinatura/' . $tenant->getId();
        [$id, $tokens] = $this->documentoEnviado();

        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        self::assertSame(base64_decode(self::PNG_1X1, true), $this->baixar('/assinatura/documentos/' . $id . '/rubrica/0'));
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    private function baixar(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }
}
