<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * D29 — a política de temporário privado (DT-7) mora no núcleo e vale para todo temporário que
 * carrega conteúdo de cliente: o upload à espera da gravação e a cópia gravável do compressor.
 *
 * "Privado" é conferido, não suposto: diretório de verdade (nem link, nem arquivo), do uid efetivo,
 * sem permissão para grupo e outros — e o temporário tem de nascer nele, porque o `tempnam()` cai em
 * silêncio no temporário do sistema quando não consegue escrever onde pediram.
 */
#[CoversClass(DiretorioTemporarioPrivado::class)]
final class DiretorioTemporarioPrivadoTest extends TestCase
{
    /** @var list<string> */
    private array $criados = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->criados) as $caminho) {
            if (is_link($caminho) || is_file($caminho)) {
                @unlink($caminho);
                continue;
            }
            if (is_dir($caminho)) {
                @chmod($caminho, 0o700);
                exec('rm -rf ' . escapeshellarg($caminho));
            }
        }
    }

    private function finalidadeUnica(): string
    {
        return 'teste' . bin2hex(random_bytes(4));
    }

    private function diretorio(int $modo = 0o700): string
    {
        $caminho = sys_get_temp_dir() . '/dtp-' . bin2hex(random_bytes(6));
        mkdir($caminho);
        chmod($caminho, $modo);
        $this->criados[] = $caminho;

        return $caminho;
    }

    #[TestDox('o diretório do processo é <temp>/jusprime-<finalidade>-<uid>, criado com 0700')]
    public function testDoProcessoCriaODiretorioDoUid(): void
    {
        $finalidade = $this->finalidadeUnica();
        $esperado   = sys_get_temp_dir() . '/jusprime-' . $finalidade . '-' . posix_geteuid();
        $this->criados[] = $esperado;

        $caminho = DiretorioTemporarioPrivado::doProcesso($finalidade)->caminho();

        self::assertSame($esperado, $caminho);
        clearstatcache(true, $caminho);
        self::assertTrue(is_dir($caminho) && !is_link($caminho));
        self::assertSame(0o700, fileperms($caminho) & 0o777);
        self::assertSame(posix_geteuid(), fileowner($caminho));
    }

    /** @return iterable<string, array{string}> */
    public static function finalidadesInvalidas(): iterable
    {
        yield 'vazia' => [''];
        yield 'com barra' => ['a/b'];
        yield 'travessia' => ['..'];
        yield 'maiúscula' => ['Upload'];
        yield 'começando com número' => ['1copia'];
        yield 'com hífen' => ['copia-x'];
    }

    #[DataProvider('finalidadesInvalidas')]
    #[TestDox('finalidade que não é um nome simples ($_dataName) é recusada')]
    public function testFinalidadeInvalidaEhRecusada(string $finalidade): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DiretorioTemporarioPrivado::doProcesso($finalidade);
    }

    #[TestDox('diretório do processo que já existe exposto é recusado — e não é corrigido em silêncio')]
    public function testDiretorioDoProcessoExpostoEhRecusado(): void
    {
        $finalidade = $this->finalidadeUnica();
        $caminho    = sys_get_temp_dir() . '/jusprime-' . $finalidade . '-' . posix_geteuid();
        mkdir($caminho);
        chmod($caminho, 0o755);
        $this->criados[] = $caminho;

        try {
            DiretorioTemporarioPrivado::doProcesso($finalidade)->caminho();
            self::fail('Diretório exposto foi aceito.');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('não é privado', $e->getMessage());
        }

        clearstatcache(true, $caminho);
        self::assertSame(0o755, fileperms($caminho) & 0o777, 'a permissão foi mexida em silêncio');
    }

    #[TestDox('diretório existente e privado é aceito')]
    public function testExistentePrivadoEhAceito(): void
    {
        $caminho = $this->diretorio();

        self::assertSame($caminho, DiretorioTemporarioPrivado::existente($caminho . '/')->caminho());
    }

    /** @return iterable<string, array{int}> */
    public static function modosExpostos(): iterable
    {
        yield 'leitura para outros' => [0o704];
        yield 'escrita para o grupo' => [0o720];
        yield 'tudo aberto' => [0o777];
    }

    #[DataProvider('modosExpostos')]
    #[TestDox('diretório existente com $_dataName é recusado')]
    public function testExistenteExpostoEhRecusado(int $modo): void
    {
        $this->expectException(FalhaDeArmazenamento::class);
        $this->expectExceptionMessage('não é privado');

        DiretorioTemporarioPrivado::existente($this->diretorio($modo))->caminho();
    }

    /** @return iterable<string, array{int}> */
    public static function modosInutilizaveis(): iterable
    {
        yield 'sem leitura para o dono' => [0o300];
        yield 'sem travessia para o dono' => [0o600];
        yield 'fechado para todos' => [0o000];
    }

    /**
     * Privado sem ser utilizável ainda é um problema: ninguém mais vê, mas nós também não. Antes
     * disso, um diretório assim passava na prova e envenenava o processo — todo `tempnam` caía fora,
     * em silêncio, até alguém reparar no diretório.
     */
    #[DataProvider('modosInutilizaveis')]
    #[TestDox('diretório existente $_dataName é recusado')]
    public function testExistenteInutilizavelEhRecusado(int $modo): void
    {
        $this->pularSeRoot();

        $this->expectException(FalhaDeArmazenamento::class);
        $this->expectExceptionMessage('não é privado');

        DiretorioTemporarioPrivado::existente($this->diretorio($modo))->caminho();
    }

    /**
     * O `mkdir(0700)` sofre o umask: com `umask(0277)` o diretório nasceria `0400` — privado, e
     * inútil. Quem cria corrige o que acabou de criar; diretório preexistente exposto continua
     * sendo recusado (é o que o teste acima prova).
     */
    #[TestDox('umask que tira bits do dono não estraga o diretório criado por nós')]
    public function testUmaskNaoEstragaODiretorioCriado(): void
    {
        $finalidade      = $this->finalidadeUnica();
        $esperado        = sys_get_temp_dir() . '/jusprime-' . $finalidade . '-' . posix_geteuid();
        $this->criados[] = $esperado;
        $umaskAnterior   = umask(0o277);

        try {
            $caminho = DiretorioTemporarioPrivado::doProcesso($finalidade)->caminho();

            clearstatcache(true, $caminho);
            self::assertSame(0o700, fileperms($caminho) & 0o777);

            $arquivo = DiretorioTemporarioPrivado::doProcesso($finalidade)->novoArquivo('copia-');
            self::assertSame($caminho, \dirname($arquivo->caminho()), 'o temporário caiu fora do diretório');
            $arquivo->liberar();
        } finally {
            umask($umaskAnterior);
        }
    }

    private function pularSeRoot(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root lê e atravessa diretório sem respeitar as permissões retiradas');
        }
    }

    #[TestDox('diretório privado de OUTRO usuário é recusado')]
    public function testDiretorioDeOutroUsuarioEhRecusado(): void
    {
        clearstatcache();
        $info = @stat('/root');
        if (posix_geteuid() === 0 || $info === false || $info['uid'] === posix_geteuid() || ($info['mode'] & 0o077) !== 0) {
            self::markTestSkipped('precisa de /root como diretório 0700 de outro usuário');
        }

        $this->expectException(FalhaDeArmazenamento::class);
        $this->expectExceptionMessage('não é privado');

        DiretorioTemporarioPrivado::existente('/root')->caminho();
    }

    #[TestDox('link simbólico para um diretório privado é recusado')]
    public function testLinkSimbolicoEhRecusado(): void
    {
        $alvo = $this->diretorio();
        $link = sys_get_temp_dir() . '/dtp-link-' . bin2hex(random_bytes(6));
        symlink($alvo, $link);
        $this->criados[] = $link;

        $this->expectException(FalhaDeArmazenamento::class);

        DiretorioTemporarioPrivado::existente($link)->caminho();
    }

    #[TestDox('arquivo regular no lugar do diretório é recusado, mesmo com modo 0600')]
    public function testArquivoNoLugarEhRecusado(): void
    {
        $arquivo = sys_get_temp_dir() . '/dtp-arquivo-' . bin2hex(random_bytes(6));
        touch($arquivo);
        chmod($arquivo, 0o600);
        $this->criados[] = $arquivo;

        $this->expectException(FalhaDeArmazenamento::class);

        DiretorioTemporarioPrivado::existente($arquivo)->caminho();
    }

    #[TestDox('diretório existente que não existe é recusado')]
    public function testExistenteAusenteEhRecusado(): void
    {
        $this->expectException(FalhaDeArmazenamento::class);

        DiretorioTemporarioPrivado::existente(sys_get_temp_dir() . '/dtp-nao-existe-' . bin2hex(random_bytes(6)))->caminho();
    }

    #[TestDox('o arquivo novo é possuído, nasce dentro do diretório e só com permissão do dono')]
    public function testNovoArquivoNasceDentro(): void
    {
        $caminho = $this->diretorio();

        $arquivo = DiretorioTemporarioPrivado::existente($caminho)->novoArquivo('copia-');

        self::assertInstanceOf(ArquivoTemporarioPossuido::class, $arquivo);
        self::assertSame($caminho, \dirname($arquivo->caminho()));
        self::assertStringStartsWith('copia-', basename($arquivo->caminho()));
        self::assertSame(0o600, fileperms($arquivo->caminho()) & 0o777);

        $nome = $arquivo->caminho();
        $arquivo->liberar();
        self::assertFileDoesNotExist($nome);
    }

    #[TestDox('temporário que não nasce dentro do diretório é recusado, e o que caiu fora é apagado')]
    public function testTempnamQueCaiForaEhRecusado(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root escreve em diretório sem permissão; o desvio do tempnam não acontece');
        }

        $semEscrita = $this->diretorio(0o500);
        $prefixo    = 'desvio' . bin2hex(random_bytes(4)) . '-';

        try {
            DiretorioTemporarioPrivado::existente($semEscrita)->novoArquivo($prefixo);
            self::fail('Temporário fora do diretório privado foi aceito.');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('não nasceu', $e->getMessage());
        }

        self::assertSame([], glob(sys_get_temp_dir() . '/' . $prefixo . '*') ?: [], 'o temporário desviado ficou para trás');
    }
}
