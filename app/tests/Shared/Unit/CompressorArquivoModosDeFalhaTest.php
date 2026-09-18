<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Service\CompressorArquivo;
use App\Shared\Service\ExecucaoDoGhostscript;
use App\Shared\Service\ResultadoCompressao;
use App\Shared\Service\ValidadorDeArquivoComprimido;
use App\Shared\Service\ValidadorDeArquivoComprimidoInterface;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * D4 e INV-7 — os modos de falha do compressor, exigidos ANTES de qualquer consumidor migrar (D28).
 *
 * Em todos: o arquivo original fica byte a byte igual (conteúdo, tamanho e inode), o resultado diz
 * "não comprimido" com o tamanho real do disco, nada é lançado (a interface promete best-effort) e
 * o temporário `.compress_*` não sobra.
 *
 * O Ghostscript é o falso de {@see GhostscriptDeTeste}: a compressão faz o que cada teste manda, e a
 * releitura de validação é a do binário real. O teste {@see testSaidaValidaEMenorSubstitui()} é o
 * controle — sem ele, todo teste daqui passaria com um falso que nunca chegasse a substituir nada.
 */
#[CoversClass(CompressorArquivo::class)]
final class CompressorArquivoModosDeFalhaTest extends TestCase
{
    private string $dir;
    private string $original;
    private string $menor;
    private string $maior;
    private LoggerEmMemoria $logger;

    protected function setUp(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript indisponível neste ambiente.');
        }

        $this->dir = sys_get_temp_dir() . '/compressor-falha-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
        mkdir($this->dir . '/documentos', 0o700);

        // O "comprimido" que os falsos entregam: um PDF real de 3 páginas.
        $this->menor = $this->dir . '/menor.pdf';
        GhostscriptDeTeste::pdfReal($this->menor, 3);

        // O original: o mesmo PDF engordado depois do %%EOF, para ser maior que o "comprimido".
        $this->original = $this->dir . '/documentos/original.pdf';
        file_put_contents($this->original, file_get_contents($this->menor) . str_repeat("% enchimento\n", 4000));

        // Uma saída VÁLIDA e maior que o original: o mesmo PDF, engordado antes do %%EOF.
        $pdf         = (string) file_get_contents($this->menor);
        $this->maior = $this->dir . '/maior.pdf';
        file_put_contents($this->maior, substr($pdf, 0, (int) strrpos($pdf, '%%EOF')) . str_repeat("% enchimento\n", 5000) . "%%EOF\n");

        $this->logger = new LoggerEmMemoria();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            @chmod($this->dir . '/documentos', 0o700);
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function compressor(string $binario, int $timeout = 120, ?ValidadorDeArquivoComprimidoInterface $validador = null): CompressorArquivo
    {
        return new CompressorArquivo($this->logger, $binario, $validador, $timeout);
    }

    private function falso(string $aoComprimir): string
    {
        return GhostscriptDeTeste::falso($this->dir, $aoComprimir);
    }

    /** @return array{string, int, int} */
    private function retrato(string $caminho): array
    {
        clearstatcache(true, $caminho);

        return [(string) md5_file($caminho), (int) filesize($caminho), (int) fileinode($caminho)];
    }

    /** @return list<string> */
    private function temporariosQueSobraram(): array
    {
        return glob($this->dir . '/documentos/.compress_*') ?: [];
    }

    /**
     * Os avisos que contêm um trecho — "houve algum warning" aceitaria o aviso de OUTRO motivo e
     * deixaria passar a troca do caminho que o teste diz estar provando.
     *
     * @return list<string>
     */
    private function mensagensDeAviso(string $trecho): array
    {
        return array_values(array_filter(
            array_column($this->logger->doNivel('warning'), 'mensagem'),
            static fn (string $mensagem): bool => str_contains($mensagem, $trecho),
        ));
    }

    private function assertOriginalPreservado(array $antes, ResultadoCompressao $resultado, ?string $caminho = null): void
    {
        self::assertSame($antes, $this->retrato($caminho ?? $this->original), 'o original mudou (INV-7)');
        self::assertFalse($resultado->comprimido);
        self::assertSame($antes[1], $resultado->tamanhoOriginal);
        self::assertSame($antes[1], $resultado->tamanhoFinal, 'o tamanho informado não é o do disco');
    }

    #[TestDox('controle: saída válida, menor e com as mesmas páginas substitui o original')]
    public function testSaidaValidaEMenorSubstitui(): void
    {
        $gs = $this->falso(sprintf('cp %s "$out"; echo "Processing pages 1 through 3."; exit 0', escapeshellarg($this->menor)));

        $resultado = $this->compressor($gs)->comprimir($this->original, 'application/pdf');

        self::assertTrue($resultado->comprimido, 'o falso não chegou a substituir: os outros testes não provam nada');
        self::assertSame(md5_file($this->menor), md5_file($this->original));
        self::assertSame((int) filesize($this->original), $resultado->tamanhoFinal);
        self::assertSame([], $this->temporariosQueSobraram());
    }

    /**
     * O mesmo controle com o binário REAL nas duas pontas: a compressão tem de anunciar as páginas
     * (sem `-dQUIET`) e a validação de verdade tem de aprovar o que ela produziu. Com o falso, as
     * duas coisas são o teste que diz, não o Ghostscript.
     */
    #[TestDox('controle com o Ghostscript real: o PDF é comprimido e continua com as mesmas páginas')]
    public function testGhostscriptRealComprimeEValida(): void
    {
        $tamanhoAntes = (int) filesize($this->original);

        $resultado = $this->compressor(GhostscriptDeTeste::BINARIO_REAL)->comprimir($this->original, 'application/pdf');

        self::assertTrue($resultado->comprimido, 'o Ghostscript real não comprimiu: ' . json_encode($this->logger->doNivel('warning')));
        self::assertSame($tamanhoAntes, $resultado->tamanhoOriginal);
        self::assertSame((int) filesize($this->original), $resultado->tamanhoFinal);
        self::assertLessThan($tamanhoAntes, $resultado->tamanhoFinal);
        self::assertTrue((new ValidadorDeArquivoComprimido(GhostscriptDeTeste::BINARIO_REAL))->pdfValido($this->original, 3));
        self::assertSame([], $this->temporariosQueSobraram());
    }

    #[TestDox('Ghostscript ausente: original intacto')]
    public function testGhostscriptAusente(): void
    {
        $antes = $this->retrato($this->original);

        $resultado = $this->compressor('/inexistente/gs')->comprimir($this->original, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado);
        self::assertSame([], $this->temporariosQueSobraram());
    }

    #[TestDox('saída vazia: original intacto e sem temporário')]
    public function testSaidaVazia(): void
    {
        $antes = $this->retrato($this->original);
        $gs    = $this->falso(': > "$out"; echo "Processing pages 1 through 3."; exit 0');

        $resultado = $this->compressor($gs)->comprimir($this->original, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado);
        self::assertSame([], $this->temporariosQueSobraram());
    }

    #[TestDox('saída maior que o original: original intacto e sem temporário')]
    public function testSaidaMaior(): void
    {
        // Controle: a saída passaria na validação — o tamanho é o único motivo da recusa.
        self::assertGreaterThan((int) filesize($this->original), (int) filesize($this->maior));
        self::assertTrue((new ValidadorDeArquivoComprimido(GhostscriptDeTeste::BINARIO_REAL))->pdfValido($this->maior, 3));

        $antes = $this->retrato($this->original);
        $gs    = $this->falso(sprintf('cp %s "$out"; echo "Processing pages 1 through 3."; exit 0', escapeshellarg($this->maior)));

        $resultado = $this->compressor($gs)->comprimir($this->original, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado);
        self::assertSame([], $this->temporariosQueSobraram());
    }

    #[TestDox('compressão que estoura o tempo: original intacto, temporário parcial apagado, sem exceção')]
    public function testTimeout(): void
    {
        $antes  = $this->retrato($this->original);
        $gs     = $this->falso('printf "%%PDF-1.4 parcial" > "$out"; sleep 5; exit 0');
        $inicio = microtime(true);

        $resultado = $this->compressor($gs, timeout: 1)->comprimir($this->original, 'application/pdf');

        self::assertLessThan(4.0, microtime(true) - $inicio, 'o timeout injetado não foi respeitado');
        $this->assertOriginalPreservado($antes, $resultado);
        self::assertSame([], $this->temporariosQueSobraram(), 'o parcial do Ghostscript sobrou no volume');
        self::assertNotSame([], $this->logger->doNivel('warning'), 'a falha tinha de ficar no log');
    }

    #[TestDox('processo morto no meio da escrita: original intacto e sem temporário')]
    public function testProcessoMorto(): void
    {
        $antes = $this->retrato($this->original);
        $gs    = $this->falso('printf "%%PDF-1.4 parcial" > "$out"; kill -9 $$');

        $resultado = $this->compressor($gs)->comprimir($this->original, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado);
        self::assertSame([], $this->temporariosQueSobraram());
        self::assertNotSame([], $this->mensagensDeAviso('Falha ao comprimir'), 'morte por sinal tem de ficar no log (D26)');
    }

    #[TestDox('D27: saída corrompida, menor e com código 0 NÃO substitui o original')]
    public function testSaidaCorrompidaMenorNaoSubstitui(): void
    {
        $antes = $this->retrato($this->original);
        $gs    = $this->falso('printf "%%PDF-1.4\nlixo\n%%%%EOF\n" > "$out"; echo "Processing pages 1 through 3."; exit 0');

        $resultado = $this->compressor($gs)->comprimir($this->original, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado);
        self::assertSame([], $this->temporariosQueSobraram());
        self::assertNotSame([], $this->logger->doNivel('warning'), 'a falha tinha de ficar no log');
    }

    #[TestDox('D27: saída válida mas com MENOS páginas NÃO substitui o original')]
    public function testSaidaComMenosPaginasNaoSubstitui(): void
    {
        $umaPagina = $this->dir . '/uma.pdf';
        GhostscriptDeTeste::pdfReal($umaPagina, 1);
        $antes = $this->retrato($this->original);
        $gs    = $this->falso(sprintf('cp %s "$out"; echo "Processing pages 1 through 3."; exit 0', escapeshellarg($umaPagina)));

        $resultado = $this->compressor($gs)->comprimir($this->original, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado);
        self::assertSame([], $this->temporariosQueSobraram());
    }

    #[TestDox('D27: compressão que não informa quantas páginas leu NÃO substitui o original')]
    public function testSaidaSemContagemDePaginasNaoSubstitui(): void
    {
        $antes = $this->retrato($this->original);
        $gs    = $this->falso(sprintf('cp %s "$out"; exit 0', escapeshellarg($this->menor)));

        $resultado = $this->compressor($gs)->comprimir($this->original, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado);
        self::assertSame([], $this->temporariosQueSobraram());
    }

    #[TestDox('falha na troca do arquivo: original intacto, sem aviso nem exceção')]
    public function testFalhaNaTroca(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root troca o arquivo mesmo sem permissão de escrita no diretório');
        }

        $antes = $this->retrato($this->original);
        $gs    = $this->falso(sprintf(
            'cp %s "$out"; echo "Processing pages 1 through 3."; chmod a-w "$(dirname "$out")"; exit 0',
            escapeshellarg($this->menor),
        ));

        try {
            $resultado = $this->compressor($gs)->comprimir($this->original, 'application/pdf');
        } finally {
            chmod($this->dir . '/documentos', 0o700);
        }

        $this->assertOriginalPreservado($antes, $resultado);
        self::assertNotSame([], $this->mensagensDeAviso('trocar o arquivo'), 'o log não fala da troca: ' . json_encode($this->logger->doNivel('warning')));
    }

    #[TestDox('D27: imagem que o validador recusa NÃO substitui o original')]
    public function testImagemRecusadaPeloValidadorNaoSubstitui(): void
    {
        $jpeg   = $this->dir . '/documentos/foto.jpg';
        $imagem = imagecreatetruecolor(300, 300);
        for ($x = 0; $x < 300; $x += 3) {
            for ($y = 0; $y < 300; $y += 3) {
                imagesetpixel($imagem, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        imagejpeg($imagem, $jpeg, 100);
        $antes     = $this->retrato($jpeg);
        $recusante = new class () implements ValidadorDeArquivoComprimidoInterface {
            /** @var list<array{string, string, int, int}> */
            public array $perguntas = [];

            public function pdfValido(string $caminho, int $paginasEsperadas, ?float $timeoutSegundos = null): bool
            {
                return false;
            }

            public function imagemValida(string $caminho, string $mimeType, int $largura, int $altura): bool
            {
                $this->perguntas[] = [$caminho, $mimeType, $largura, $altura];

                return false;
            }
        };

        $resultado = $this->compressor(GhostscriptDeTeste::BINARIO_REAL, validador: $recusante)->comprimir($jpeg, 'image/jpeg');

        self::assertSame($antes, $this->retrato($jpeg));
        self::assertFalse($resultado->comprimido);
        self::assertSame($antes[1], $resultado->tamanhoFinal);
        self::assertCount(1, $recusante->perguntas, 'o validador não foi consultado');
        self::assertSame(['image/jpeg', 300, 300], \array_slice($recusante->perguntas[0], 1));
        self::assertSame([], glob($this->dir . '/documentos/.compress_*') ?: []);
    }

    /**
     * O gs "repara" um PDF danificado em silêncio (`This file had errors that were repaired or
     * ignored`) e a saída reparada passa na validação — trocaria o arquivo que o cliente enviou por
     * uma reconstrução. Com `-dPDFSTOPONERROR` na compressão, ele desiste e o original fica.
     */
    #[TestDox('PDF danificado NÃO é trocado pela versão "reparada" pelo Ghostscript')]
    public function testPdfDanificadoNaoEhReparadoPorCima(): void
    {
        $bom       = (string) file_get_contents($this->menor);
        $danificado = $this->dir . '/documentos/danificado.pdf';
        file_put_contents(
            $danificado,
            substr_replace($bom, str_repeat('X', 60), 400, 60) . str_repeat("% enchimento\n", 4000),
        );
        $antes = $this->retrato($danificado);

        $resultado = $this->compressor(GhostscriptDeTeste::BINARIO_REAL)->comprimir($danificado, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado, $danificado);
        self::assertSame([], glob($this->dir . '/documentos/.compress_*') ?: []);
        self::assertNotSame([], $this->mensagensDeAviso('Ghostscript não comprimiu'));
    }

    /**
     * PDF com senha de usuário: o gs sai com código 0 e escreve uma saída de 1 página (uma folha
     * dizendo que precisa de senha). Menor que o original, e válida — só a contagem de páginas
     * impede a troca. Aqui isso é provado com o binário REAL, não com o falso.
     */
    #[TestDox('PDF protegido por senha NÃO é substituído pela saída de 1 página do Ghostscript')]
    public function testPdfComSenhaNaoEhSubstituido(): void
    {
        $comSenha = $this->dir . '/documentos/com-senha.pdf';
        $cifrar   = new Process([
            GhostscriptDeTeste::BINARIO_REAL, '-q', '-dBATCH', '-dNOPAUSE', '-dSAFER', '-sDEVICE=pdfwrite',
            '-dEncryptionR=3', '-dKeyLength=128', '-sOwnerPassword=dono', '-sUserPassword=segredo',
            '-sOutputFile=' . $comSenha, $this->original,
        ]);
        $cifrar->run();
        if (!$cifrar->isSuccessful() || !is_file($comSenha)) {
            self::markTestSkipped('este Ghostscript não gera PDF cifrado: ' . $cifrar->getErrorOutput());
        }

        $antes = $this->retrato($comSenha);

        $resultado = $this->compressor(GhostscriptDeTeste::BINARIO_REAL)->comprimir($comSenha, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado, $comSenha);
        self::assertSame([], glob($this->dir . '/documentos/.compress_*') ?: []);
    }

    /** Os `gs_*` que o Ghostscript escreve enquanto trabalha não podem sobrar quando ele é morto. */
    #[TestDox('os temporários do próprio Ghostscript saem junto, mesmo com timeout')]
    public function testTemporariosDoGhostscriptSaemJunto(): void
    {
        $privado = DiretorioTemporarioPrivado::doProcesso(ExecucaoDoGhostscript::FINALIDADE_DO_DIRETORIO_PRIVADO)->caminho();
        // O diretório privado é do PROCESSO e sobrevive entre testes: o que se compara é a
        // diferença, não o conteúdo total (que pode trazer resíduo de outra execução da máquina).
        $antesDoPrivado = glob($privado . '/*') ?: [];
        $gs             = $this->falso('echo "$TMPDIR" > ' . escapeshellarg($this->dir . '/tmpdir.txt') . '; : > "$TMPDIR/gs_lixo"; sleep 5; exit 0');
        $antes          = $this->retrato($this->original);

        $resultado = $this->compressor($gs, timeout: 1)->comprimir($this->original, 'application/pdf');

        $this->assertOriginalPreservado($antes, $resultado);
        $tmpdir = trim((string) file_get_contents($this->dir . '/tmpdir.txt'));
        self::assertStringStartsWith($privado . '/', $tmpdir, 'o gs escreveu fora do temporário privado');
        self::assertFileDoesNotExist($tmpdir, 'o diretório do gs sobrou depois do timeout');
        self::assertSame($antesDoPrivado, glob($privado . '/*') ?: [], 'esta compressão deixou temporário do gs para trás');
    }

    #[TestDox('orçamento de tempo menor que 1 s é recusado no construtor — zero seria "sem limite"')]
    public function testOrcamentoZeroEhRecusado(): void
    {
        foreach ([0, -5] as $invalido) {
            try {
                $this->compressor(GhostscriptDeTeste::BINARIO_REAL, timeout: $invalido);
                self::fail('Orçamento ' . $invalido . ' foi aceito; no Symfony, 0 significa esperar para sempre.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('1 segundo', $e->getMessage());
            }
        }
    }

    /**
     * Decodificar imagem que não cabe no `memory_limit` é **Fatal error**: nenhum `catch`, nenhum
     * `finally`, o worker morre no meio do upload. O teste roda num processo filho com limite
     * apertado — sem a guarda, o filho sai com 255 e nada é impresso.
     */
    #[TestDox('imagem grande demais para a memória não é decodificada — e o processo sobrevive')]
    public function testImagemGrandeDemaisNaoMataOProcesso(): void
    {
        $png    = $this->dir . '/documentos/enorme.png';
        $imagem = imagecreatetruecolor(4000, 4000);
        imagefilledrectangle($imagem, 0, 0, 3999, 3999, 0x2244AA);
        imagepng($imagem, $png, 1);
        imagedestroy($imagem);
        $antes = $this->retrato($png);

        $script = $this->dir . '/comprimir.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[1];
            use App\Shared\Service\CompressorArquivo;
            use Psr\Log\NullLogger;
            $resultado = (new CompressorArquivo(new NullLogger(), '/usr/bin/gs'))->comprimir($argv[2], 'image/png');
            echo $resultado->comprimido ? 'COMPRIMIU' : 'MANTEVE', ' ', $resultado->tamanhoFinal;
            PHP);

        // 64 MB: a matriz de 4000x4000 pediria 64 MB só de pixels.
        $processo = new Process([\PHP_BINARY, '-d', 'memory_limit=64M', $script, \dirname(__DIR__, 3) . '/vendor/autoload.php', $png]);
        $processo->run();

        self::assertSame(0, $processo->getExitCode(), 'o processo morreu: ' . $processo->getErrorOutput() . $processo->getOutput());
        self::assertSame('MANTEVE ' . $antes[1], trim($processo->getOutput()));
        self::assertSame($antes, $this->retrato($png));
        self::assertSame([], glob($this->dir . '/documentos/.compress_*') ?: []);
    }

    /**
     * O orçamento é da operação inteira, não de cada processo: a validação recebe o que sobrou da
     * compressão. Com um timeout próprio de 120 s em cada lado, o pior caso passava dos 120 s em que
     * o nginx corta a requisição — e o usuário via 504 com o PHP ainda trabalhando.
     */
    #[TestDox('a validação recebe o que SOBROU do orçamento, não o orçamento inteiro')]
    public function testValidacaoRecebeOPrazoRestante(): void
    {
        $gs       = $this->falso(sprintf('sleep 2; cp %s "$out"; echo "Processing pages 1 through 3."; exit 0', escapeshellarg($this->menor)));
        $relogio  = new class () implements ValidadorDeArquivoComprimidoInterface {
            /** @var list<?float> */
            public array $prazos = [];

            public function pdfValido(string $caminho, int $paginasEsperadas, ?float $timeoutSegundos = null): bool
            {
                $this->prazos[] = $timeoutSegundos;

                return true;
            }

            public function imagemValida(string $caminho, string $mimeType, int $largura, int $altura): bool
            {
                return true;
            }
        };

        $this->compressor($gs, timeout: 10, validador: $relogio)->comprimir($this->original, 'application/pdf');

        self::assertCount(1, $relogio->prazos);
        self::assertNotNull($relogio->prazos[0], 'a validação ficou com o próprio timeout, fora do orçamento');
        self::assertLessThan(9.0, $relogio->prazos[0], 'os 2 s da compressão não saíram do orçamento');
        self::assertGreaterThan(0.0, $relogio->prazos[0]);
    }

    #[TestDox('arquivo com mime de imagem que o GD não decodifica: no-op')]
    public function testImagemIlegivelEhNoOp(): void
    {
        $falsa = $this->dir . '/documentos/falsa.jpg';
        file_put_contents($falsa, str_repeat('nao sou jpeg', 100));
        $antes = $this->retrato($falsa);

        $resultado = $this->compressor(GhostscriptDeTeste::BINARIO_REAL)->comprimir($falsa, 'image/jpeg');

        self::assertSame($antes, $this->retrato($falsa));
        self::assertFalse($resultado->comprimido);
        self::assertSame($antes[1], $resultado->tamanhoFinal);
    }
}
