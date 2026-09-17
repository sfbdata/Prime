<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Shared\Service\CompressaoDeArquivoArmazenado;
use App\Shared\Service\CompressorArquivo;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * INV-7 de ponta a ponta, no disco: backend local, compressor real, Ghostscript falso na compressão e
 * real na validação. Em sucesso e em falha, o arquivo que fica no storage é íntegro, o tamanho
 * devolvido é o do disco, e nada sobra — nem no diretório privado, nem ao lado do persistido.
 */
#[CoversClass(CompressaoDeArquivoArmazenado::class)]
final class CompressaoDeArquivoArmazenadoNoDiscoTest extends TestCase
{
    private string $raiz;
    private string $privado;
    private ResolvedorDeCaminhoLocal $resolvedor;
    private ArmazenamentoLocal $local;
    private ChaveDeArquivo $chave;
    private string $persistido;
    private string $menor;

    protected function setUp(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript indisponível neste ambiente.');
        }

        $this->raiz    = sys_get_temp_dir() . '/e2-compressao-disco-' . bin2hex(random_bytes(6));
        $this->privado = $this->raiz . '/privado';
        mkdir($this->privado, 0o700, true);
        chmod($this->privado, 0o700);

        $this->resolvedor = new ResolvedorDeCaminhoLocal(
            uploadsDir: $this->raiz . '/pastas',
            clientesUploadsDir: $this->raiz . '/clientes',
            chamadosUploadsDir: $this->raiz . '/chamados',
            justificativasUploadsDir: $this->raiz . '/justificativas',
            fotosPerfilDir: $this->raiz . '/perfil',
            cobrancasUploadsDir: $this->raiz . '/cobrancas',
            kanbanUploadsDir: $this->raiz . '/kanban',
        );
        $this->local = new ArmazenamentoLocal($this->resolvedor, DiretorioTemporarioPrivado::existente($this->privado));

        $this->menor = $this->raiz . '/menor.pdf';
        GhostscriptDeTeste::pdfReal($this->menor, 2);

        $this->chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(3), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'peticao.pdf');
        $this->local->gravar(
            $this->chave,
            FonteDeConteudo::deTexto(file_get_contents($this->menor) . str_repeat("% enchimento\n", 3000)),
        );
        $this->persistido = $this->resolvedor->caminhoDe($this->chave);
    }

    protected function tearDown(): void
    {
        if (isset($this->raiz) && is_dir($this->raiz)) {
            exec('chmod -R u+rwx ' . escapeshellarg($this->raiz) . ' && rm -rf ' . escapeshellarg($this->raiz));
        }
    }

    private function servico(string $gs, int $timeout = 120): CompressaoDeArquivoArmazenado
    {
        $logger = new LoggerEmMemoria();

        return new CompressaoDeArquivoArmazenado(
            $this->local,
            $this->local,
            new CompressorArquivo($logger, $gs, timeoutPdfSegundos: $timeout),
            $logger,
        );
    }

    /** @return array{string, int, int} */
    private function retrato(): array
    {
        clearstatcache(true, $this->persistido);

        return [(string) md5_file($this->persistido), (int) filesize($this->persistido), (int) fileinode($this->persistido)];
    }

    private function assertNadaSobrou(): void
    {
        self::assertSame([], array_values(array_diff(scandir($this->privado) ?: [], ['.', '..'])), 'sobrou algo no diretório privado');
        self::assertSame(
            ['peticao.pdf'],
            array_values(array_diff(scandir(\dirname($this->persistido)) ?: [], ['.', '..'])),
            'sobrou temporário ao lado do persistido',
        );
    }

    #[TestDox('sucesso: o persistido vira o comprimido válido, e o tamanho é o do disco')]
    public function testSucesso(): void
    {
        $gs = GhostscriptDeTeste::falso($this->raiz, sprintf('cp %s "$out"; echo "Processing pages 1 through 2."; exit 0', escapeshellarg($this->menor)));

        $resultado = $this->servico($gs)->comprimir($this->chave, 'application/pdf');

        self::assertTrue($resultado->comprimido);
        self::assertSame(md5_file($this->menor), md5_file($this->persistido));
        clearstatcache(true, $this->persistido);
        self::assertSame(filesize($this->persistido), $resultado->tamanhoFinal);
        $this->assertNadaSobrou();
    }

    #[TestDox('saída corrompida: o persistido fica intacto, o tamanho é o do disco, nada sobra')]
    public function testSaidaCorrompida(): void
    {
        $antes = $this->retrato();
        $gs    = GhostscriptDeTeste::falso($this->raiz, 'printf "%%PDF-1.4\nlixo\n%%%%EOF\n" > "$out"; echo "Processing pages 1 through 2."; exit 0');

        $resultado = $this->servico($gs)->comprimir($this->chave, 'application/pdf');

        self::assertSame($antes, $this->retrato());
        self::assertFalse($resultado->comprimido);
        self::assertSame($antes[1], $resultado->tamanhoFinal);
        $this->assertNadaSobrou();
    }

    #[TestDox('timeout: o persistido fica intacto e o parcial não sobra em lugar nenhum')]
    public function testTimeout(): void
    {
        $antes = $this->retrato();
        $gs    = GhostscriptDeTeste::falso($this->raiz, 'printf "%%PDF-1.4 parcial" > "$out"; sleep 5; exit 0');

        $resultado = $this->servico($gs, timeout: 1)->comprimir($this->chave, 'application/pdf');

        self::assertSame($antes, $this->retrato());
        self::assertSame($antes[1], $resultado->tamanhoFinal);
        $this->assertNadaSobrou();
    }

    #[TestDox('JPEG real pelo GD: comprime, continua decodificável e o tamanho é o do disco')]
    public function testJpegReal(): void
    {
        $foto   = new ChaveDeArquivo(EscopoDeArquivo::deTenant(3), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'foto.jpg');
        $imagem = imagecreatetruecolor(200, 150);
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 150; $y++) {
                imagesetpixel($imagem, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        $origem = $this->raiz . '/foto-origem.jpg';
        imagejpeg($imagem, $origem, 100);
        $this->local->gravar($foto, FonteDeConteudo::deArquivoLocal($origem, consumirOrigem: false));
        $caminho = $this->resolvedor->caminhoDe($foto);
        $antes   = filesize($caminho);

        $resultado = $this->servico(GhostscriptDeTeste::BINARIO_REAL)->comprimir($foto, 'image/jpeg');

        clearstatcache(true, $caminho);
        self::assertTrue($resultado->comprimido);
        self::assertLessThan($antes, filesize($caminho));
        self::assertSame(filesize($caminho), $resultado->tamanhoFinal);
        self::assertSame([200, 150], \array_slice((array) getimagesize($caminho), 0, 2));
        self::assertSame([], array_values(array_diff(scandir($this->privado) ?: [], ['.', '..'])));
    }
}
