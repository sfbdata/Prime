<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Shared\Http\EntregaDeArquivo;
use App\Shared\Service\ArquivoStorageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A entrega HTTP endereçada por chave (E2.3, D10, D11), contra arquivos de verdade.
 *
 * O teste mais importante daqui é o de EQUIVALÊNCIA: para o mesmo arquivo, o mesmo nome e a mesma
 * disposição, os cabeçalhos que a entrega nova produz são os mesmos que `ArquivoStorageService::
 * servir()` produzia. É a prova de que as 15 rotas não mudaram para quem baixa — enquanto o
 * serviço antigo ainda existe para comparar (ele sai na E2.8).
 */
#[CoversClass(EntregaDeArquivo::class)]
final class EntregaDeArquivoTest extends TestCase
{
    /** PNG 1x1 válido: o suficiente para o finfo reconhecer image/png. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89"
        . "\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private string $raiz;
    private ResolvedorDeCaminhoLocal $resolvedor;
    private ArmazenamentoLocal $local;
    private EntregaDeArquivo $entrega;

    protected function setUp(): void
    {
        $this->raiz       = sys_get_temp_dir() . '/e2-entrega-' . bin2hex(random_bytes(6));
        $this->resolvedor = new ResolvedorDeCaminhoLocal(
            uploadsDir: $this->raiz . '/pastas',
            clientesUploadsDir: $this->raiz . '/clientes',
            chamadosUploadsDir: $this->raiz . '/chamados',
            justificativasUploadsDir: $this->raiz . '/justificativas',
            fotosPerfilDir: $this->raiz . '/perfil',
            cobrancasUploadsDir: $this->raiz . '/cobrancas',
            kanbanUploadsDir: $this->raiz . '/kanban',
        );
        $this->local   = new ArmazenamentoLocal($this->resolvedor);
        $this->entrega = new EntregaDeArquivo($this->local);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->raiz)) {
            @chmod($this->raiz, 0o755);
            exec('rm -rf ' . escapeshellarg($this->raiz));
        }
    }

    // ================================================================ D11

    #[TestDox('a entrega recebe CHAVE, nunca caminho físico (D11)')]
    public function testRecebeChaveNaoCaminho(): void
    {
        $parametros = (new \ReflectionMethod(EntregaDeArquivo::class, 'resposta'))->getParameters();

        self::assertSame(ChaveDeArquivo::class, (string) $parametros[0]->getType());
        self::assertSame(['chave', 'nomeParaDownload', 'inline'], array_map(static fn ($p) => $p->getName(), $parametros));
    }

    // ================================================================ cabeçalhos

    #[TestDox('inline continua inline, com o nome apresentado ao usuário')]
    public function testInline(): void
    {
        $resposta = $this->preparar($this->entrega->resposta($this->gravar('a.pdf', self::PDF), 'Contrato.pdf', inline: true));

        self::assertSame('inline; filename=Contrato.pdf', $resposta->headers->get('Content-Disposition'));
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
    }

    #[TestDox('download continua attachment, com o nome apresentado ao usuário')]
    public function testAttachment(): void
    {
        $resposta = $this->preparar($this->entrega->resposta($this->gravar('a.pdf', self::PDF), 'Contrato.pdf', inline: false));

        self::assertSame('attachment; filename=Contrato.pdf', $resposta->headers->get('Content-Disposition'));
    }

    #[TestDox('nome com acento mantém o nome original em UTF-8 e o fallback ASCII')]
    public function testNomeComAcento(): void
    {
        $resposta = $this->preparar($this->entrega->resposta($this->gravar('b.pdf', self::PDF), 'Petição açaí.pdf', inline: false));

        $disposicao = (string) $resposta->headers->get('Content-Disposition');
        self::assertStringContainsString("filename*=utf-8''Peti%C3%A7%C3%A3o%20a%C3%A7a%C3%AD.pdf", $disposicao);
        self::assertStringContainsString('filename="Peti__o a_a_.pdf"', $disposicao);
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function casosDeEquivalencia(): iterable
    {
        yield 'pdf inline'        => ['x.pdf', self::PDF, 'Contrato.pdf', true];
        yield 'pdf attachment'    => ['x.pdf', self::PDF, 'Contrato.pdf', false];
        yield 'png inline'        => ['x.png', self::PNG, 'foto.png', true];
        yield 'png attachment'    => ['x.png', self::PNG, 'foto.png', false];
        yield 'nome com acento'   => ['x.pdf', self::PDF, 'Petição açaí.pdf', false];
        yield 'nome com %'        => ['x.pdf', self::PDF, 'desconto 10%.pdf', true];
        yield 'hash como nome'    => ['9f2a.pdf', self::PDF, '9f2a.pdf', true];
        yield 'legado sem ext.'   => ['9f2a.', self::PDF, 'peça antiga', false];
    }

    #[DataProvider('casosDeEquivalencia')]
    #[TestDox('cabeçalhos idênticos aos do servir() antigo — status, tipo, disposição, ranges, tamanho, data')]
    public function testEquivalenciaComServirAntigo(string $nomeNoDisco, string $conteudo, string $nomeParaDownload, bool $inline): void
    {
        $chave   = $this->gravar($nomeNoDisco, $conteudo);
        $caminho = $this->resolvedor->caminhoDe($chave);

        $antiga = $this->preparar((new ArquivoStorageService())->servir($caminho, $nomeParaDownload, $inline));
        $nova   = $this->preparar($this->entrega->resposta($chave, $nomeParaDownload, $inline));

        self::assertSame($antiga->getStatusCode(), $nova->getStatusCode());
        self::assertSame($antiga::class, $nova::class);
        foreach (['Content-Type', 'Content-Disposition', 'Accept-Ranges', 'Content-Length', 'Last-Modified', 'Cache-Control', 'ETag'] as $cabecalho) {
            self::assertSame($antiga->headers->get($cabecalho), $nova->headers->get($cabecalho), $cabecalho);
        }
        self::assertSame($this->corpo($antiga), $this->corpo($nova));
    }

    // ================================================================ Range

    #[TestDox('Range continua funcionando: bytes=0-99 → 206 com Content-Range e exatamente 100 bytes')]
    public function testRange(): void
    {
        $conteudo = str_repeat('0123456789', 50); // 500 bytes
        $resposta = $this->preparar(
            $this->entrega->resposta($this->gravar('grande.pdf', $conteudo), 'grande.pdf', inline: true),
            ['HTTP_RANGE' => 'bytes=0-99'],
        );

        self::assertSame(Response::HTTP_PARTIAL_CONTENT, $resposta->getStatusCode());
        self::assertSame('bytes 0-99/500', $resposta->headers->get('Content-Range'));
        self::assertSame('100', $resposta->headers->get('Content-Length'));
        self::assertSame(substr($conteudo, 0, 100), $this->corpo($resposta));
    }

    // ================================================================ INV-9

    #[TestDox('a resposta NÃO apaga o arquivo persistido: sem deleteFileAfterSend, e ele existe depois de enviado (INV-9 #3)')]
    public function testNaoApagaOPersistido(): void
    {
        $chave    = $this->gravar('persistido.pdf', self::PDF);
        $caminho  = $this->resolvedor->caminhoDe($chave);
        $resposta = $this->preparar($this->entrega->resposta($chave, 'persistido.pdf', inline: true));

        self::assertInstanceOf(BinaryFileResponse::class, $resposta);
        self::assertFalse(
            (new \ReflectionProperty(BinaryFileResponse::class, 'deleteFileAfterSend'))->getValue($resposta),
            'deleteFileAfterSend ligado sobre arquivo EMPRESTADO apagaria o documento do cliente',
        );

        $this->corpo($resposta); // sendContent() de verdade

        self::assertFileExists($caminho);
        self::assertSame(self::PDF, file_get_contents($caminho));
    }

    #[TestDox('arquivo grande não é carregado inteiro em memória: sai em blocos pelo sendContent()')]
    public function testArquivoGrandeNaoVaiParaAMemoria(): void
    {
        $tamanho = 64 * 1024 * 1024;
        $chave   = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'grande.bin');
        $this->local->gravar($chave, FonteDeConteudo::deTexto(''));
        $caminho = $this->resolvedor->caminhoDe($chave);
        $fp      = fopen($caminho, 'r+b');
        ftruncate($fp, $tamanho); // esparso: não ocupa disco, mas lê 64 MB
        fclose($fp);

        $resposta = $this->preparar($this->entrega->resposta($chave, 'grande.bin', inline: false));
        self::assertInstanceOf(BinaryFileResponse::class, $resposta);

        $enviados = 0;
        memory_reset_peak_usage();
        $base = memory_get_usage();
        ob_start(static function (string $bloco) use (&$enviados): string {
            $enviados += \strlen($bloco);

            return '';
        }, 65536);
        try {
            $resposta->sendContent();
        } finally {
            ob_end_flush();
        }
        $pico = memory_get_peak_usage() - $base;

        self::assertSame($tamanho, $enviados, 'o arquivo inteiro tem de sair');
        self::assertLessThan(8 * 1024 * 1024, $pico, sprintf('pico de %d bytes: o arquivo foi para a memória', $pico));
    }

    // ================================================================ D10

    #[TestDox('chave válida mas arquivo ausente → 404 (D10 #2)')]
    public function testAusenteVira404(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'nunca.pdf');

        try {
            $this->entrega->resposta($chave, 'nunca.pdf', inline: true);
            self::fail('deveria ter lançado 404');
        } catch (NotFoundHttpException $e) {
            self::assertInstanceOf(ArquivoNaoEncontrado::class, $e->getPrevious());
        }
    }

    #[TestDox('erro operacional do storage NÃO vira 404 (D10 #3)')]
    public function testErroOperacionalNaoVira404(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão; o guarda não é observável');
        }

        $chave = $this->gravar('escondido.pdf', self::PDF);
        chmod($this->raiz, 0o000);

        try {
            $this->entrega->resposta($chave, 'escondido.pdf', inline: true);
            self::fail('deveria ter lançado');
        } catch (HttpExceptionInterface $e) {
            self::fail(sprintf('falha operacional foi mascarada como HTTP %d', $e->getStatusCode()));
        } catch (FalhaDeArmazenamento) {
            self::assertTrue(true);
        } finally {
            chmod($this->raiz, 0o755);
        }
    }

    // ================================================================ apoio

    private function gravar(string $nome, string $conteudo): ChaveDeArquivo
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
        $this->local->gravar($chave, FonteDeConteudo::deTexto($conteudo));

        return $chave;
    }

    /** @param array<string, string> $server */
    private function preparar(Response $resposta, array $server = []): Response
    {
        return $resposta->prepare(Request::create('/qualquer', 'GET', server: $server));
    }

    private function corpo(Response $resposta): string
    {
        ob_start();
        try {
            $resposta->sendContent();
        } finally {
            $corpo = (string) ob_get_clean();
        }

        return $corpo;
    }
}
