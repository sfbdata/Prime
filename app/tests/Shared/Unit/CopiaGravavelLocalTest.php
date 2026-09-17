<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A cópia GRAVÁVEL do backend de disco (E2.6A, D9, D29): um temporário possuído, num diretório
 * privado fora do volume, que o compressor pode reescrever sem tocar o persistido.
 *
 * É aqui que a obrigação real do INV-9 #4 aparece — disco cheio no meio, origem ilegível, diretório
 * temporário exposto: em todos, o original fica como estava e nenhuma cópia parcial sobra.
 */
#[CoversClass(ArmazenamentoLocal::class)]
final class CopiaGravavelLocalTest extends TestCase
{
    private string $raiz;
    private string $privado;
    private ResolvedorDeCaminhoLocal $resolvedor;
    private ArmazenamentoLocal $local;

    protected function setUp(): void
    {
        $this->raiz    = sys_get_temp_dir() . '/e2-copia-' . bin2hex(random_bytes(6));
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
    }

    protected function tearDown(): void
    {
        if (is_dir($this->raiz)) {
            exec('chmod -R u+rwx ' . escapeshellarg($this->raiz) . ' && rm -rf ' . escapeshellarg($this->raiz));
        }
    }

    private function chave(string $nome, int $tenant = 1, CategoriaDeArquivo $categoria = CategoriaDeArquivo::CLIENTE_DOCUMENTO): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant($tenant), $categoria, $nome);
    }

    private function gravado(string $nome, string $conteudo): string
    {
        $chave = $this->chave($nome);
        $this->local->gravar($chave, FonteDeConteudo::deTexto($conteudo));

        return $this->resolvedor->caminhoDe($chave);
    }

    /** @return array{string, int, int, int, int} */
    private function retrato(string $caminho): array
    {
        clearstatcache(true, $caminho);

        return [(string) md5_file($caminho), (int) filesize($caminho), (int) fileinode($caminho), fileperms($caminho), (int) filemtime($caminho)];
    }

    /** @return list<string> */
    private function noDiretorioPrivado(): array
    {
        return array_values(array_diff(scandir($this->privado) ?: [], ['.', '..']));
    }

    #[TestDox('a cópia é possuída, tem o mesmo conteúdo e nasce no diretório privado, só para o dono')]
    public function testCopiaIgualNoDiretorioPrivado(): void
    {
        $original = $this->gravado('contrato.pdf', str_repeat('conteudo do cliente ', 500));

        $copia = $this->local->copiaGravavel($this->chave('contrato.pdf'));

        self::assertInstanceOf(ArquivoTemporarioPossuido::class, $copia);
        self::assertSame($this->privado, \dirname($copia->caminho()));
        self::assertNotSame($original, $copia->caminho());
        self::assertSame(md5_file($original), md5_file($copia->caminho()));
        self::assertSame(0o600, fileperms($copia->caminho()) & 0o777);
        $copia->liberar();
    }

    #[TestDox('INV-9: escrever na cópia não muda o original — nem conteúdo, tamanho, inode, modo ou data')]
    public function testEscreverNaCopiaNaoMudaOOriginal(): void
    {
        $original = $this->gravado('foto.png', str_repeat('A', 4096));
        touch($original, time() - 3600);
        $antes = $this->retrato($original);

        $copia = $this->local->copiaGravavel($this->chave('foto.png'));
        file_put_contents($copia->caminho(), 'reescrito pelo compressor');

        self::assertSame($antes, $this->retrato($original));
        $copia->liberar();
    }

    #[TestDox('liberar a cópia apaga só a cópia')]
    public function testLiberarApagaSoACopia(): void
    {
        $original = $this->gravado('laudo.pdf', 'laudo');
        $copia    = $this->local->copiaGravavel($this->chave('laudo.pdf'));
        $caminho  = $copia->caminho();

        $copia->liberar();

        self::assertFileDoesNotExist($caminho);
        self::assertFileExists($original);
        self::assertSame([], $this->noDiretorioPrivado());
    }

    #[TestDox('D10: arquivo ausente lança ArquivoNaoEncontrado e nada nasce no diretório privado')]
    public function testAusente(): void
    {
        try {
            $this->local->copiaGravavel($this->chave('nao-existe.pdf'));
            self::fail('Arquivo ausente foi copiado.');
        } catch (ArquivoNaoEncontrado) {
        }

        self::assertSame([], $this->noDiretorioPrivado());
    }

    #[TestDox('D10: ancestral ilegível lança FalhaDeArmazenamento, não ausência — e nada sobra')]
    public function testAncestralIlegivel(): void
    {
        $this->pularSeRoot();
        $this->gravado('segredo.pdf', 'segredo');
        chmod($this->raiz . '/clientes', 0o000);

        try {
            $this->local->copiaGravavel($this->chave('segredo.pdf'));
            self::fail('Pane virou cópia ou ausência.');
        } catch (ArquivoNaoEncontrado) {
            self::fail('Diretório ilegível foi lido como ausência (D10).');
        } catch (FalhaDeArmazenamento) {
        } finally {
            chmod($this->raiz . '/clientes', 0o755);
        }

        self::assertSame([], $this->noDiretorioPrivado());
    }

    #[TestDox('arquivo presente e ilegível lança FalhaDeArmazenamento, o original fica e nada sobra')]
    public function testArquivoIlegivel(): void
    {
        $this->pularSeRoot();
        $original = $this->gravado('fechado.pdf', 'fechado');
        chmod($original, 0o000);

        try {
            $this->local->copiaGravavel($this->chave('fechado.pdf'));
            self::fail('Arquivo ilegível foi copiado.');
        } catch (FalhaDeArmazenamento) {
        } finally {
            chmod($original, 0o644);
        }

        self::assertSame('fechado', file_get_contents($original));
        self::assertSame([], $this->noDiretorioPrivado());
    }

    #[TestDox('D29: diretório temporário exposto é recusado antes de copiar, e o original fica')]
    public function testDiretorioTemporarioExpostoEhRecusado(): void
    {
        $original = $this->gravado('atestado.pdf', 'atestado medico');
        $antes    = $this->retrato($original);
        chmod($this->privado, 0o755);

        try {
            $this->local->copiaGravavel($this->chave('atestado.pdf'));
            self::fail('Cópia de conteúdo de cliente foi feita em diretório exposto.');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('não é privado', $e->getMessage());
        } finally {
            chmod($this->privado, 0o700);
        }

        self::assertSame($antes, $this->retrato($original));
        self::assertSame([], $this->noDiretorioPrivado());
    }

    #[TestDox('isolamento físico: a chave de um escritório não copia o arquivo de outro')]
    public function testNaoCopiaArquivoDeOutroEscritorio(): void
    {
        $doCinco = $this->chave('peca.html', 5, CategoriaDeArquivo::PASTA_IMAGEM_EDITOR);
        $this->local->gravar($doCinco, FonteDeConteudo::deTexto('do escritorio 5'));

        $this->expectException(ArquivoNaoEncontrado::class);

        $this->local->copiaGravavel($this->chave('peca.html', 6, CategoriaDeArquivo::PASTA_IMAGEM_EDITOR));
    }

    /**
     * Disco cheio de verdade, sem root: o teste roda a cópia num processo filho com limite de
     * tamanho de arquivo (`ulimit -f`) e o SIGXFSZ ignorado — a escrita passa a falhar depois de
     * 4 KB, como falharia com o volume cheio.
     */
    #[TestDox('INV-9 #4: disco cheio no meio da cópia lança, apaga a cópia parcial e não toca o original')]
    public function testDiscoCheioNoMeioDaCopia(): void
    {
        $original = $this->gravado('grande.pdf', random_bytes(64 * 1024));
        $antes    = $this->retrato($original);
        $script   = $this->raiz . '/copiar.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[1];
            use App\Shared\Armazenamento\{ArmazenamentoLocal, CategoriaDeArquivo, ChaveDeArquivo, DiretorioTemporarioPrivado, EscopoDeArquivo, ResolvedorDeCaminhoLocal};
            $raiz = $argv[2];
            $local = new ArmazenamentoLocal(
                new ResolvedorDeCaminhoLocal($raiz . '/pastas', $raiz . '/clientes', $raiz . '/chamados', $raiz . '/justificativas', $raiz . '/perfil', $raiz . '/cobrancas', $raiz . '/kanban'),
                DiretorioTemporarioPrivado::existente($raiz . '/privado'),
            );
            try {
                $copia = $local->copiaGravavel(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'grande.pdf'));
                echo 'COPIOU ', filesize($copia->caminho());
            } catch (\Throwable $e) {
                echo get_class($e), ': ', $e->getMessage();
            }
            PHP);

        $processo = new Process([
            'sh', '-c', 'trap "" XFSZ; ulimit -f 8; exec "$0" "$@"',
            \PHP_BINARY, $script, \dirname(__DIR__, 3) . '/vendor/autoload.php', $this->raiz,
        ]);
        $processo->mustRun();

        // D26: a culpa é do TEMPORÁRIO — o arquivo do cliente está intacto, então quem chama pode
        // seguir sem comprimir em vez de devolver 500.
        self::assertStringStartsWith(FalhaNoTemporario::class, $processo->getOutput(), 'a cópia incompleta foi aceita');
        self::assertSame([], $this->noDiretorioPrivado(), 'a cópia parcial ficou no diretório privado');
        self::assertSame($antes, $this->retrato($original));
    }

    /**
     * O outro lado da mesma moeda: a leitura entrega MENOS do que o `fstat` prometeu. Um arquivo de
     * `/proc` tem tamanho 0 e conteúdo — é a forma de exercitar a divergência sem corromper disco.
     * Aqui a culpa NÃO é do temporário: o persistido não entregou o que tinha, e isso é pane.
     */
    #[TestDox('D26: leitura que não bate com o tamanho do arquivo é pane, não falha do temporário')]
    public function testLeituraQueNaoBateComOTamanhoEhPane(): void
    {
        if (!is_readable('/proc/self/stat')) {
            self::markTestSkipped('precisa de /proc para simular tamanho declarado diferente do lido');
        }

        $chave   = $this->chave('declarado.bin', 1, CategoriaDeArquivo::CLIENTE_DOCUMENTO);
        $caminho = $this->resolvedor->caminhoDe($chave);
        @mkdir(\dirname($caminho), 0o755, true);
        symlink('/proc/self/stat', $caminho);

        try {
            $this->local->copiaGravavel($chave);
            self::fail('Cópia com tamanho diferente do declarado foi aceita.');
        } catch (FalhaNoTemporario $e) {
            self::fail('Pane de leitura classificada como falha do temporário: ' . $e->getMessage());
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('bytes', $e->getMessage());
        }

        self::assertSame([], $this->noDiretorioPrivado(), 'a cópia parcial ficou no diretório privado');
    }

    private function pularSeRoot(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root lê e escreve sem respeitar as permissões que o teste retira');
        }
    }
}
