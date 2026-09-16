<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A leitura materializada do backend de disco (E2.3): o caminho real que a camada de entrega
 * precisa para montar a resposta HTTP.
 *
 * Duas promessas, e as duas são sobre NÃO fazer coisas:
 *
 *  - **cópia zero, sem posse** — o caminho devolvido é o do próprio arquivo de produção, embrulhado
 *    num tipo que não sabe apagar (INV-9). Nada é criado no disco;
 *  - **ausente ≠ ilegível** (D10) — "o arquivo não está lá" e "não consegui olhar" são exceções
 *    diferentes, porque a rota transforma a primeira em 404 e NÃO pode transformar a segunda.
 */
#[CoversClass(ArmazenamentoLocal::class)]
final class MaterializadorLocalTest extends TestCase
{
    private string $raiz;
    private ResolvedorDeCaminhoLocal $resolvedor;
    private ArmazenamentoLocal $local;

    protected function setUp(): void
    {
        $this->raiz       = sys_get_temp_dir() . '/e2-materializador-' . bin2hex(random_bytes(6));
        $this->resolvedor = new ResolvedorDeCaminhoLocal(
            uploadsDir: $this->raiz . '/pastas',
            clientesUploadsDir: $this->raiz . '/clientes',
            chamadosUploadsDir: $this->raiz . '/chamados',
            justificativasUploadsDir: $this->raiz . '/justificativas',
            fotosPerfilDir: $this->raiz . '/perfil',
            cobrancasUploadsDir: $this->raiz . '/cobrancas',
            kanbanUploadsDir: $this->raiz . '/kanban',
        );
        $this->local = new ArmazenamentoLocal($this->resolvedor);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->raiz)) {
            @chmod($this->raiz, 0o755);
            exec('chmod -R u+rwx ' . escapeshellarg($this->raiz) . ' && rm -rf ' . escapeshellarg($this->raiz));
        }
    }

    #[TestDox('o backend local é um materializador, e paraLeitura() devolve um EMPRESTADO — nunca um possuído')]
    public function testContratoDevolveEmprestado(): void
    {
        self::assertInstanceOf(MaterializadorDeArquivo::class, $this->local);

        $tipo = (new \ReflectionMethod(MaterializadorDeArquivo::class, 'paraLeitura'))->getReturnType();
        self::assertSame(ArquivoEmprestado::class, (string) $tipo);
    }

    #[TestDox('cópia zero: o caminho emprestado é o do próprio arquivo persistido, e nada novo nasce no disco')]
    public function testCopiaZero(): void
    {
        $chave = $this->chave('doc.pdf');
        $this->local->gravar($chave, FonteDeConteudo::deTexto('%PDF-1.4 conteudo'));
        $antes = $this->arquivosNaRaiz();

        $emprestado = $this->local->paraLeitura($chave);

        self::assertSame($this->resolvedor->caminhoDe($chave), $emprestado->caminho());
        self::assertSame($antes, $this->arquivosNaRaiz(), 'materializar para leitura não pode criar arquivo');
    }

    /** @return iterable<string, array{string}> */
    public static function nomesLegados(): iterable
    {
        yield 'termina em ponto' => ['9f2a1c-sem-extensao.'];
        yield 'espaço na borda'  => [' com-espaco.pdf '];
    }

    #[DataProvider('nomesLegados')]
    #[TestDox('chave legada é materializada byte a byte (D8)')]
    public function testChaveLegada(string $nome): void
    {
        $chave = $this->chave($nome);
        $this->local->gravar($chave, FonteDeConteudo::deTexto('legado'));

        self::assertSame('legado', file_get_contents($this->local->paraLeitura($chave)->caminho()));
    }

    #[TestDox('arquivo ausente lança ArquivoNaoEncontrado — não FalhaDeArmazenamento (D10)')]
    public function testAusente(): void
    {
        $this->expectException(ArquivoNaoEncontrado::class);

        $this->local->paraLeitura($this->chave('nunca-gravado.pdf'));
    }

    #[TestDox('isolamento físico: a chave de um escritório não materializa o arquivo de outro')]
    public function testNaoMaterializaArquivoDeOutroEscritorio(): void
    {
        $doOutro = new ChaveDeArquivo(EscopoDeArquivo::deTenant(2), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'h.pdf');
        $meu     = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'h.pdf');
        $this->local->gravar($doOutro, FonteDeConteudo::deTexto('do escritório 2'));

        $this->expectException(ArquivoNaoEncontrado::class);

        $this->local->paraLeitura($meu);
    }

    #[TestDox('diretório ancestral ilegível lança FalhaDeArmazenamento — "não consegui olhar" não é "não existe" (D10)')]
    public function testAncestralIlegivel(): void
    {
        $this->pularSeRoot();
        $chave = $this->chave('escondido.pdf');
        $this->local->gravar($chave, FonteDeConteudo::deTexto('x'));

        chmod($this->raiz, 0o000);

        try {
            $this->local->paraLeitura($chave);
            self::fail('deveria ter lançado');
        } catch (ArquivoNaoEncontrado $e) {
            self::fail('ilegível foi confundido com ausente: ' . $e->getMessage());
        } catch (FalhaDeArmazenamento) {
            self::assertTrue(true);
        } finally {
            chmod($this->raiz, 0o755);
        }
    }

    #[TestDox('arquivo presente mas ilegível lança FalhaDeArmazenamento — não 404 disfarçado')]
    public function testArquivoIlegivel(): void
    {
        $this->pularSeRoot();
        $chave   = $this->chave('trancado.pdf');
        $this->local->gravar($chave, FonteDeConteudo::deTexto('x'));
        $caminho = $this->resolvedor->caminhoDe($chave);

        chmod($caminho, 0o000);

        try {
            $this->expectException(FalhaDeArmazenamento::class);
            $this->local->paraLeitura($chave);
        } finally {
            chmod($caminho, 0o644);
        }
    }

    /**
     * INV-9 #4 na cópia zero é quase trivial: `paraLeitura()` não escreve nada. O teste existe para
     * travar essa propriedade — se alguém trocar a cópia zero por uma cópia, ele passa a ter o que
     * provar. A obrigação REAL (disco cheio, origem sumida, falha no meio da cópia) é da
     * `copiaGravavel()`, na E2.6.
     */
    #[TestDox('falha na leitura materializada não afeta o original — nem apaga, nem trunca, nem muda o modo (INV-9 #4, cópia zero)')]
    public function testFalhaNaoAfetaOOriginal(): void
    {
        $this->pularSeRoot();
        $chave   = $this->chave('original.pdf');
        $this->local->gravar($chave, FonteDeConteudo::deTexto('conteudo que nao pode mudar'));
        $caminho = $this->resolvedor->caminhoDe($chave);
        chmod($caminho, 0o640);
        clearstatcache();
        $antes = [fileperms($caminho), filesize($caminho), filemtime($caminho), md5_file($caminho)];

        chmod($this->raiz, 0o000);
        $lancou = false;
        try {
            $this->local->paraLeitura($chave);
        } catch (FalhaDeArmazenamento) {
            $lancou = true;
        } finally {
            chmod($this->raiz, 0o755);
        }

        self::assertTrue($lancou, 'a falha tinha de ser sinalizada, não engolida');

        clearstatcache();
        self::assertSame($antes, [fileperms($caminho), filesize($caminho), filemtime($caminho), md5_file($caminho)]);
    }

    // ------------------------------------------------------------------ apoio

    private function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::CLIENTE_DOCUMENTO, $nome);
    }

    /** @return list<string> */
    private function arquivosNaRaiz(): array
    {
        if (!is_dir($this->raiz)) {
            return [];
        }

        $lista = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->raiz, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            $lista[] = $arquivo->getPathname();
        }
        sort($lista);

        return $lista;
    }

    private function pularSeRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão; o guarda não é observável');
        }
    }
}
