<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Tenant\Tenant;
use App\Entity\Auth\User;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Exception\SelecaoDeDocumentosInvalidaException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Inteligencia\Service\TextoDoAceiteDaIa;
use App\Pasta\Entity\PastaDocumento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra do "Ler N documentos" (PR F): limites conferidos antes da fila, o tipo pelo conteúdo (o que vai
 * como `media_type`) e o aceite do escritório, que só cobre a leitura se for posterior ao texto novo.
 */
#[CoversClass(RegraDaLeituraDeDocumentos::class)]
#[CoversClass(TextoDoAceiteDaIa::class)]
final class RegraDaLeituraDeDocumentosTest extends TestCase
{
    private const MB = RegraDaLeituraDeDocumentos::MEGABYTE;

    private static function documento(string $nome, string $mime, int $bytes, ?int $paginas = null): PastaDocumento
    {
        return (new PastaDocumento())
            ->setNomeOriginal($nome)
            ->setMimeType($mime)
            ->setTamanhoBytes($bytes)
            ->setPaginas($paginas);
    }

    private function regra(?ConfiguracaoDeInteligencia $config = null): RegraDaLeituraDeDocumentos
    {
        $repo = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $repo->method('findDoTenant')->willReturn($config);

        return new RegraDaLeituraDeDocumentos($repo);
    }

    #[TestDox('Até 3 documentos, PDF e imagem dentro dos tetos: passa')]
    public function testSelecaoValidaPassa(): void
    {
        $this->regra()->conferir([
            self::documento('a.pdf', 'application/pdf', 10 * self::MB, 100),
            self::documento('b.jpg', 'image/jpg', 3 * self::MB),
            self::documento('c.webp', 'IMAGE/WEBP ', 1000),
        ]);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{list<PastaDocumento>, string}> */
    public static function selecoesInvalidas(): iterable
    {
        $um = self::documento('a.pdf', 'application/pdf', 1000);
        yield 'quatro documentos' => [[$um, $um, $um, $um], 'no máximo 3'];
        yield 'formato não lido' => [[self::documento('planilha.xlsx', 'application/vnd.ms-excel', 10)], 'planilha.xlsx'];
        yield 'docx' => [[self::documento('peca.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 10)], 'peca.docx'];
        yield 'PDF grande' => [[self::documento('grande.pdf', 'application/pdf', 10 * self::MB + 1)], '10 MB'];
        yield 'imagem grande' => [[self::documento('foto.png', 'image/png', 3 * self::MB + 1)], '3 MB'];
        yield 'páginas demais' => [[self::documento('longo.pdf', 'application/pdf', 1000, 101)], '100 páginas'];
        yield 'total acima de 20 MB' => [[
            self::documento('a.pdf', 'application/pdf', 9 * self::MB),
            self::documento('b.pdf', 'application/pdf', 9 * self::MB),
            self::documento('c.png', 'image/png', 3 * self::MB),
        ], '20 MB'];
    }

    /** @param list<PastaDocumento> $documentos */
    #[DataProvider('selecoesInvalidas')]
    #[TestDox('Seleção fora da regra: recusa com mensagem clara ($_dataName)')]
    public function testSelecaoInvalidaRecusa(array $documentos, string $trecho): void
    {
        try {
            $this->regra()->conferir($documentos);
            self::fail('Deveria recusar.');
        } catch (SelecaoDeDocumentosInvalidaException $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
            self::assertStringNotContainsString('—', $e->getMessage());
            self::assertStringNotContainsString('–', $e->getMessage());
        }
    }

    #[TestDox('Contagem de páginas desconhecida não bloqueia o PDF')]
    public function testPaginasDesconhecidasNaoBloqueiam(): void
    {
        $this->regra()->conferir([self::documento('a.pdf', 'application/pdf', 1000, null)]);
        $this->addToAssertionCount(1);
    }

    #[TestDox('Tipo declarado: sinônimos normalizados; o resto é nulo')]
    public function testTipoDeclarado(): void
    {
        self::assertSame('image/jpeg', RegraDaLeituraDeDocumentos::tipoDeclarado('image/pjpeg'));
        self::assertSame(AnexoDeDocumento::PDF, RegraDaLeituraDeDocumentos::tipoDeclarado('application/x-pdf'));
        self::assertSame('image/gif', RegraDaLeituraDeDocumentos::tipoDeclarado('image/gif'));
        self::assertNull(RegraDaLeituraDeDocumentos::tipoDeclarado('image/svg+xml'));
        self::assertNull(RegraDaLeituraDeDocumentos::tipoDeclarado('text/plain'));
        self::assertNull(RegraDaLeituraDeDocumentos::tipoDeclarado(''));
    }

    #[TestDox('Tipo pelo conteúdo: assinatura de PDF, PNG, JPEG, GIF e WEBP; o resto é nulo')]
    public function testTipoPeloConteudo(): void
    {
        self::assertSame(AnexoDeDocumento::PDF, RegraDaLeituraDeDocumentos::tipoPeloConteudo("%PDF-1.7\n..."));
        self::assertSame('image/png', RegraDaLeituraDeDocumentos::tipoPeloConteudo("\x89PNG\r\n\x1A\nIHDR"));
        self::assertSame('image/jpeg', RegraDaLeituraDeDocumentos::tipoPeloConteudo("\xFF\xD8\xFF\xE0JFIF"));
        self::assertSame('image/gif', RegraDaLeituraDeDocumentos::tipoPeloConteudo('GIF89a....'));
        self::assertSame('image/gif', RegraDaLeituraDeDocumentos::tipoPeloConteudo('GIF87a....'));
        self::assertSame('image/webp', RegraDaLeituraDeDocumentos::tipoPeloConteudo("RIFF\x10\x00\x00\x00WEBPVP8 "));
        self::assertNull(RegraDaLeituraDeDocumentos::tipoPeloConteudo('RIFF....WAVE'));
        self::assertNull(RegraDaLeituraDeDocumentos::tipoPeloConteudo('<svg xmlns="..."/>'));
        self::assertNull(RegraDaLeituraDeDocumentos::tipoPeloConteudo("PK\x03\x04 docx"));
        self::assertNull(RegraDaLeituraDeDocumentos::tipoPeloConteudo(''));
    }

    #[TestDox('Aceite: sem configuração, sem aceite ou aceite antigo não cobre; aceite novo cobre')]
    public function testAceite(): void
    {
        $tenant = new Tenant();
        self::assertFalse($this->regra(null)->aceiteCobre($tenant));

        $semAceite = new ConfiguracaoDeInteligencia($tenant);
        self::assertFalse($this->regra($semAceite)->aceiteCobre($tenant));

        $antigo = new ConfiguracaoDeInteligencia($tenant);
        $this->definirAceite($antigo, new \DateTimeImmutable('2026-10-01 10:00:00'));
        self::assertFalse($this->regra($antigo)->aceiteCobre($tenant));

        $novo = new ConfiguracaoDeInteligencia($tenant);
        $this->definirAceite($novo, TextoDoAceiteDaIa::comDocumentosDesde());
        self::assertTrue($this->regra($novo)->aceiteCobre($tenant), 'no instante do texto novo já cobre');
        self::assertFalse(TextoDoAceiteDaIa::cobreDocumentos(TextoDoAceiteDaIa::comDocumentosDesde()->modify('-1 second')));
    }

    #[TestDox('Renovação: salvar com a IA ligada e aceite antigo grava o aceite de novo; com aceite novo, não mexe')]
    public function testRenovacaoDoAceite(): void
    {
        $config = new ConfiguracaoDeInteligencia(new Tenant());
        $admin = new User();
        $config->atualizar(true, 10, 100, true, $admin);
        $this->definirAceite($config, new \DateTimeImmutable('2026-10-01 10:00:00'));
        self::assertFalse($config->aceiteCobreDocumentos());

        $config->atualizar(true, 10, 100, true, $admin);
        self::assertTrue($config->aceiteCobreDocumentos(), 'salvar ligada renova');
        $renovado = $config->getConsentimentoEnvioExternoEm();

        $config->atualizar(true, 20, 100, true, $admin);
        self::assertSame($renovado, $config->getConsentimentoEnvioExternoEm(), 'aceite novo não é regravado a cada salvar');

        $desligada = new ConfiguracaoDeInteligencia(new Tenant());
        $desligada->atualizar(false, 10, 100, true, $admin);
        self::assertNull($desligada->getConsentimentoEnvioExternoEm(), 'salvar desligada não dá aceite');
    }

    private function definirAceite(ConfiguracaoDeInteligencia $config, \DateTimeImmutable $em): void
    {
        $prop = new \ReflectionProperty(ConfiguracaoDeInteligencia::class, 'consentimentoEnvioExternoEm');
        $prop->setValue($config, $em);
    }
}
