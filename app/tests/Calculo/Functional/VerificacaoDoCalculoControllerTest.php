<?php

declare(strict_types=1);

namespace App\Tests\Calculo\Functional;

use App\Calculo\Controller\VerificacaoDoCalculoController;
use App\Calculo\Entity\CalculoEmitido;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/** Página pública de verificação do código do PDF: sem login, só o que o PDF já mostra, hash do arquivo conferido. */
#[CoversClass(VerificacaoDoCalculoController::class)]
final class VerificacaoDoCalculoControllerTest extends JusPrimeWebTestCase
{
    private const CODIGO = 'BJC-MVV219D2';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }

    private function emitir(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Verificável ' . uniqid());
        $em->persist($tenant);
        $em->persist(new CalculoEmitido($tenant, null, self::CODIGO, new \DateTimeImmutable('2026-10-30 14:05:00'), new \DateTimeImmutable('2026-10-30'), '20094.57', hash('sha256', 'pdf-original')));
        $em->flush();
    }

    private function enviar(string $conteudo, string $codigo = self::CODIGO): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($caminho, $conteudo);
        $this->client->request('POST', '/calculo/verificar', ['_token' => 'TOKEN_calculo_verificar', 'codigo' => $codigo], ['arquivo' => new UploadedFile($caminho, 'calculo.pdf', 'application/pdf', null, true)]);
    }

    public function testPaginaAbreSemLoginEComNoindex(): void
    {
        $this->client->request('GET', '/calculo/verificar');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSelectorExists('form[action="/calculo/verificar"] input[name=codigo]');
        self::assertSelectorExists('form input[type=file][name=arquivo]');
    }

    public function testCodigoNaUrlMostraSoOQueOPdfMostra(): void
    {
        $this->emitir();

        $this->client->request('GET', '/calculo/verificar', ['codigo' => 'bjc-mvv219d2']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Escritório Verificável', $html);
        self::assertStringContainsString('30/10/2026 14:05', $html);
        self::assertStringContainsString('20.094,57', $html);
        self::assertStringContainsString('Envie o PDF para conferir', $html);
    }

    public function testCodigoInexistenteEFormatoInvalido(): void
    {
        $this->client->request('GET', '/calculo/verificar', ['codigo' => 'BJC-ZZZZZZZZ']);
        self::assertSelectorTextContains('.cv-ruim', 'Nenhum PDF foi emitido');

        $this->client->request('GET', '/calculo/verificar', ['codigo' => 'abc']);
        self::assertSelectorTextContains('.cv-ruim', 'formato BJC-');
    }

    public function testArquivoIdenticoConfere(): void
    {
        $this->emitir();

        $this->enviar('pdf-original');

        self::assertStringContainsString('é idêntico ao PDF emitido', (string) $this->client->getResponse()->getContent());
    }

    public function testArquivoAlteradoNaoConfere(): void
    {
        $this->emitir();

        $this->enviar('pdf-adulterado');

        self::assertSelectorTextContains('.cv-ruim', 'NÃO é idêntico');
    }

    public function testPostSemTokenNaoConsulta(): void
    {
        $this->emitir();

        $this->client->request('POST', '/calculo/verificar', ['_token' => 'errado', 'codigo' => self::CODIGO]);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Escritório Verificável', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('.cv-aviso', 'expirou');
    }

    public function testSemTravessaoNaPagina(): void
    {
        $this->client->request('GET', '/calculo/verificar');

        self::assertDoesNotMatchRegularExpression('/[—–]/u', (string) $this->client->getResponse()->getContent());
    }
}
