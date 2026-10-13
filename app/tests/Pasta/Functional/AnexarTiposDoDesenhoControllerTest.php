<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\UseCase\UploadPecaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * DOC-115: o "Anexar" da aba Documentos (`POST /pasta/{id}/peticionar/upload`, o endpoint que o
 * explorador usa) aceita os formatos que o desenho trata e o visor abre — ODT, ODS, ODP, RTF,
 * PPTX, EML, CSV, GIF, WEBP, TIFF e HEIC.
 *
 * O MIME vem do CONTEÚDO (finfo real, via `UploadedFile::getMimeType()`): o MIME que o cliente
 * declara no upload é propositalmente mentiroso (`application/octet-stream`) para provar que não
 * é ele quem decide. As amostras são mínimas e montadas aqui (ZipArchive para os pacotes).
 *
 * As recusas provam que a extensão não abre porta: executável e HTML renomeados para `.odt`
 * continuam fora, e um zip qualquer com extensão `.odt` também — `application/zip` só vale como
 * ODT quando o próprio pacote declara ser ODT (entrada `mimetype`).
 */
#[CoversClass(PeticionarController::class)]
#[CoversClass(UploadPecaUseCase::class)]
final class AnexarTiposDoDesenhoControllerTest extends JusPrimeWebTestCase
{
    /** @var list<string> */
    private array $arquivosCriados = [];

    private ?ArmazenamentoEmMemoriaNoContainer $armazenamento = null;

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        $this->arquivosCriados = [];
        $this->armazenamento   = null;

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string}> tipo => [nome, conteúdo, MIME que o finfo dá] */
    public static function tiposNovos(): iterable
    {
        yield 'ODT'  => ['parecer.odt', self::odf('application/vnd.oasis.opendocument.text'), 'application/vnd.oasis.opendocument.text'];
        yield 'ODS'  => ['calculo.ods', self::odf('application/vnd.oasis.opendocument.spreadsheet'), 'application/vnd.oasis.opendocument.spreadsheet'];
        yield 'ODP'  => ['audiencia.odp', self::odf('application/vnd.oasis.opendocument.presentation'), 'application/vnd.oasis.opendocument.presentation'];
        yield 'PPTX' => ['sustentacao.pptx', self::pptx(), 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        yield 'RTF'  => ['contrato.rtf', "{\\rtf1\\ansi\\deff0 {\\fonttbl {\\f0 Arial;}} Contrato de honorarios\\par }", 'text/rtf'];
        yield 'EML'  => ['email-cliente.eml', self::eml(), 'message/rfc822'];
        yield 'CSV'  => ['parcelas.csv', "nome,valor,data\n\"Fulano\",10,2026-10-07\n\"Beltrano\",20,2026-10-08\n", 'text/csv'];
        yield 'GIF'  => ['carimbo.gif', (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true), 'image/gif'];
        yield 'WEBP' => ['foto.webp', (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true), 'image/webp'];
        yield 'TIFF' => ['digitalizacao.tif', "II*\x00\x08\x00\x00\x00\x00\x00", 'image/tiff'];
        yield 'HEIC' => ['foto-celular.heic', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 16), 'image/heic'];
    }

    #[DataProvider('tiposNovos')]
    #[TestDox('DOC-115: cada tipo novo é aceito pelo conteúdo e gravado com o MIME do finfo')]
    public function testTipoNovoEAceito(string $nome, string $conteudo, string $mime): void
    {
        [$client, $tenant, $pasta] = $this->cenario();

        $client->request(...$this->pedido((int) $pasta->getId(), $conteudo, $nome));

        $corpo = (string) $client->getResponse()->getContent();
        self::assertResponseIsSuccessful($corpo);
        $data = json_decode($corpo, true);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame($nome, $data['documento']['nome']);
        self::assertSame($mime, $data['documento']['mime'], 'o MIME gravado é o do conteúdo, não o declarado no upload');

        $doc = $this->documento((int) $data['documento']['id']);
        self::assertSame($mime, $doc->getMimeType());
        self::assertSame($tenant->getId(), $doc->getTenant()?->getId());
        self::assertSame(\strlen($conteudo), $doc->getTamanhoBytes());
        self::assertCount(1, $this->armazenamento()->memoria->gravadas);
    }

    /** @return iterable<string, array{string, string, string}> caso => [nome, conteúdo, trecho da mensagem] */
    public static function recusas(): iterable
    {
        yield 'executável renomeado para .odt' => [
            'parecer.odt',
            "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00" . str_repeat("\x00", 64) . "PE\x00\x00",
            'Tipo de arquivo não permitido: application/x-dosexec.',
        ];
        yield 'HTML renomeado para .odt' => [
            'parecer.odt',
            '<!DOCTYPE html><html><head><title>x</title></head><body><script>alert(1)</script></body></html>',
            'Tipo de arquivo não permitido: text/html.',
        ];
        yield 'zip genérico com extensão .odt' => [
            'parecer.odt',
            self::zip(['leia-me.txt' => 'não sou um documento']),
            '"parecer.odt": o conteúdo não é um arquivo .odt válido.',
        ];
        yield 'pacote ODS (visto como zip) com extensão .odt' => [
            'parecer.odt',
            self::odfVistoComoZip('application/vnd.oasis.opendocument.spreadsheet'),
            '"parecer.odt": o conteúdo não é um arquivo .odt válido.',
        ];
        yield 'zip genérico com extensão .pptx' => [
            'sustentacao.pptx',
            self::zip(['[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>', 'slides.txt' => 'x']),
            '"sustentacao.pptx": o conteúdo não é um arquivo .pptx válido.',
        ];
    }

    #[DataProvider('recusas')]
    #[TestDox('DOC-115: conteúdo incoerente com a extensão é recusado — 400, nada gravado nem persistido')]
    public function testRecusa(string $nome, string $conteudo, string $mensagem): void
    {
        [$client, , $pasta] = $this->cenario();

        $client->request(...$this->pedido((int) $pasta->getId(), $conteudo, $nome));

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($data['success']);
        self::assertSame($mensagem, $data['error']);
        self::assertSame([], $this->armazenamento()->memoria->gravadas, 'recusado antes de gravar no storage');
        self::assertSame(0, (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = :id',
            ['id' => $pasta->getId()],
        ));
    }

    /**
     * Com a entrada `mimetype` (ODF) ou `[Content_Types].xml` (OOXML) fora da primeira posição, o
     * finfo não reconhece o pacote e devolve `application/zip`. Aí quem decide é o que o pacote
     * declara por dentro, e o documento é gravado com o MIME do pacote — nunca como zip.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function pacotesVistosComoZip(): iterable
    {
        yield 'ODT'  => ['parecer.odt', self::odfVistoComoZip('application/vnd.oasis.opendocument.text'), 'application/vnd.oasis.opendocument.text'];
        yield 'ODS'  => ['calculo.ods', self::odfVistoComoZip('application/vnd.oasis.opendocument.spreadsheet'), 'application/vnd.oasis.opendocument.spreadsheet'];
        yield 'ODP'  => ['audiencia.odp', self::odfVistoComoZip('application/vnd.oasis.opendocument.presentation'), 'application/vnd.oasis.opendocument.presentation'];
        yield 'PPTX' => ['sustentacao.pptx', self::pptxVistoComoZip(), 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    }

    #[DataProvider('pacotesVistosComoZip')]
    #[TestDox('DOC-115: pacote que o finfo vê como application/zip entra pelo tipo que ele declara por dentro')]
    public function testPacoteVistoComoZipEntraPeloTipoDeclarado(string $nome, string $conteudo, string $mime): void
    {
        self::assertSame('application/zip', (new \finfo(\FILEINFO_MIME_TYPE))->buffer($conteudo), 'premissa: o finfo não reconhece este pacote');

        [$client, , $pasta] = $this->cenario();

        $client->request(...$this->pedido((int) $pasta->getId(), $conteudo, $nome));

        $corpo = (string) $client->getResponse()->getContent();
        self::assertResponseIsSuccessful($corpo);
        $data = json_decode($corpo, true);
        self::assertIsArray($data);
        self::assertSame($mime, $data['documento']['mime']);
        self::assertSame($mime, $this->documento((int) $data['documento']['id'])->getMimeType());
    }

    #[TestDox('DOC-115: um .zip de verdade continua aceito como zip (a regra do pacote só vale para extensão de documento)')]
    public function testZipDeVerdadeSegueAceito(): void
    {
        [$client, , $pasta] = $this->cenario();

        $client->request(...$this->pedido((int) $pasta->getId(), self::zip(['leia-me.txt' => 'conteúdo']), 'acervo.zip'));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('application/zip', $data['documento']['mime']);
    }

    // ----------------------------------------------------------------- amostras

    /** ODF mínimo: `mimetype` primeiro e sem compressão, como manda a especificação. */
    private static function odf(string $mime): string
    {
        return self::zip([
            'mimetype'              => $mime,
            'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"/>',
            'content.xml'           => '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>',
        ], guardarMimetype: true);
    }

    /** ODF com `mimetype` fora da 1ª posição: o finfo não o reconhece e diz `application/zip`. */
    private static function odfVistoComoZip(string $mime): string
    {
        return self::zip([
            'content.xml' => '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>',
            'mimetype'    => $mime,
        ]);
    }

    /** PPTX com `[Content_Types].xml` fora da 1ª posição: o finfo diz `application/zip`. */
    private static function pptxVistoComoZip(): string
    {
        return self::zip([
            '_rels/.rels'          => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
            '[Content_Types].xml'  => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>',
            'ppt/presentation.xml' => '<?xml version="1.0" encoding="UTF-8"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>',
        ]);
    }

    private static function pptx(): string
    {
        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>',
            '_rels/.rels'          => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
            'ppt/presentation.xml' => '<?xml version="1.0" encoding="UTF-8"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>',
        ]);
    }

    private static function eml(): string
    {
        return "From: Fulano <fulano@exemplo.com>\r\nTo: Escritorio <contato@exemplo.com>\r\nSubject: Documentos do processo\r\n"
            . "Date: Tue, 7 Oct 2026 10:00:00 -0300\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSeguem os documentos.\r\n";
    }

    /** @param array<string, string> $entradas */
    private static function zip(array $entradas, bool $guardarMimetype = false): string
    {
        // Roda nos data providers: falha vira exceção, não asserção.
        $caminho = tempnam(sys_get_temp_dir(), 'doc115_zip_');
        $zip     = new \ZipArchive();
        if ($caminho === false || $zip->open($caminho, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('não foi possível montar a amostra ZIP');
        }
        foreach ($entradas as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
            if ($guardarMimetype && $nome === 'mimetype') {
                $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
            }
        }
        $zip->close();

        $bytes = (string) file_get_contents($caminho);
        @unlink($caminho);

        return $bytes;
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{KernelBrowser, Tenant, Pasta} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->armazenamento = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant DOC115 ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('doc115_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Anexar');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('DOC115-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $tenant, $pasta];
    }

    private function armazenamento(): ArmazenamentoEmMemoriaNoContainer
    {
        self::assertNotNull($this->armazenamento);

        return $this->armazenamento;
    }

    /**
     * Argumentos do `request()`. O MIME declarado é `application/octet-stream` de propósito: quem
     * decide é o conteúdo.
     *
     * @return array{string, string, array<string, string>, array<string, UploadedFile>}
     */
    private function pedido(int $pastaId, string $conteudo, string $nome): array
    {
        $origem = sys_get_temp_dir() . '/doc115_' . bin2hex(random_bytes(6));
        file_put_contents($origem, $conteudo);
        $this->arquivosCriados[] = $origem;

        return [
            'POST',
            "/pasta/{$pastaId}/peticionar/upload",
            ['_token' => 'TOKEN_peticionar_upload_' . $pastaId, 'categoria' => PastaDocumento::CATEGORIA_DEMAIS],
            ['arquivo' => new UploadedFile($origem, $nome, 'application/octet-stream', null, true)],
        ];
    }

    private function documento(int $id): PastaDocumento
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $doc = $em->getRepository(PastaDocumento::class)->find($id);
        self::assertInstanceOf(PastaDocumento::class, $doc);

        return $doc;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
