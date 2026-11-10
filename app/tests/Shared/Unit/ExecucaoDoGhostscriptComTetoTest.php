<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Service\ExecucaoDoGhostscript;
use App\Shared\Service\SaidaDoGhostscript;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `rodarComTeto`: a saída fica no teto, o tempo para o processo, o código de saída volta, e em
 * nenhum caso sobra filho vivo depois do retorno.
 *
 * Os "binários" são scripts de shell que gravam o próprio PID e trocam de corpo com `exec`: o PID
 * gravado é o do processo que o Process roda até o fim, e `/proc/<pid>` some só quando ele terminou
 * e foi recolhido (zumbi também conta como sobra).
 */
#[CoversClass(ExecucaoDoGhostscript::class)]
#[CoversClass(SaidaDoGhostscript::class)]
final class ExecucaoDoGhostscriptComTetoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gs-com-teto-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[TestDox('Saída menor que o teto volta inteira, com código zero e sem marcas')]
    public function testSaidaInteira(): void
    {
        $saida = ExecucaoDoGhostscript::rodarComTeto([$this->falso("printf 'um\\ndois\\n'")], 10.0, 100);

        self::assertSame("um\ndois\n", $saida->saida);
        self::assertSame(0, $saida->codigo);
        self::assertTrue($saida->terminouBem());
        $this->assertSemFilhoVivo();
    }

    #[TestDox('Saída sem fim para no teto, volta cortada no teto e o processo não sobra')]
    public function testParaNoTeto(): void
    {
        $inicio = microtime(true);
        $saida  = ExecucaoDoGhostscript::rodarComTeto([$this->falso('exec yes 0123456789')], 30.0, 5000);

        self::assertTrue($saida->tetoEstourado);
        self::assertFalse($saida->tempoEstourado);
        self::assertFalse($saida->terminouBem());
        self::assertSame(5000, \strlen($saida->saida));
        self::assertStringStartsWith("0123456789\n0123456789\n", $saida->saida);
        self::assertLessThan(5.0, microtime(true) - $inicio, 'o teto não esperou o tempo acabar');
        $this->assertSemFilhoVivo();
    }

    #[TestDox('Produtor rápido sem fim respeita o teto sem acumular a saída na memória do leitor')]
    public function testProdutorRapidoComPoucaMemoria(): void
    {
        $autoload = var_export(\dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        $programa = 'require ' . $autoload . '; ' . <<<'PHP'
            for ($i = 0; $i < 50; ++$i) {
                $saida = App\Shared\Service\ExecucaoDoGhostscript::rodarComTeto(['/usr/bin/yes', '0123456789'], 10.0, 5000);
                if (!$saida->tetoEstourado || strlen($saida->saida) !== 5000) {
                    exit(2);
                }
            }
            echo memory_get_peak_usage(true);
            PHP;
        $processo = new Process([PHP_BINARY, '-d', 'memory_limit=32M', '-r', $programa]);
        $processo->setTimeout(15.0);
        $processo->run();

        self::assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());
        self::assertLessThan(8 * 1024 * 1024, (int) $processo->getOutput(), 'Inclui o autoload e os temporários das 50 execuções.');
    }

    #[TestDox('Processo parado no tempo volta com a marca de tempo e não sobra')]
    public function testParaNoTempo(): void
    {
        $inicio = microtime(true);
        $saida  = ExecucaoDoGhostscript::rodarComTeto([$this->falso("printf 'antes\\n'; exec sleep 30")], 0.5, 100);

        self::assertTrue($saida->tempoEstourado);
        self::assertFalse($saida->tetoEstourado);
        self::assertFalse($saida->terminouBem());
        self::assertLessThan(5.0, microtime(true) - $inicio);
        $this->assertSemFilhoVivo();
    }

    #[TestDox('O código de saída diferente de zero volta sem virar exceção')]
    public function testCodigoDeSaida(): void
    {
        $saida = ExecucaoDoGhostscript::rodarComTeto([$this->falso("printf 'parcial'; exit 3")], 10.0, 100);

        self::assertSame(3, $saida->codigo);
        self::assertSame('parcial', $saida->saida);
        self::assertFalse($saida->terminouBem());
        $this->assertSemFilhoVivo();
    }

    #[TestDox('Binário que não existe volta código 127, sem exceção')]
    public function testBinarioInexistente(): void
    {
        $saida = ExecucaoDoGhostscript::rodarComTeto([$this->dir . '/nao-existe'], 10.0, 100);

        self::assertSame(127, $saida->codigo);
        self::assertSame('', $saida->saida);
    }

    #[TestDox('A saída de erro é descartada e não conta para o teto')]
    public function testSaidaDeErroNaoConta(): void
    {
        $saida = ExecucaoDoGhostscript::rodarComTeto([$this->falso("head -c 300000 /dev/zero >&2; printf 'ok'")], 10.0, 10);

        self::assertSame('ok', $saida->saida);
        self::assertTrue($saida->terminouBem());
    }

    #[TestDox('O processo roda com TMPDIR privado, apagado depois do retorno')]
    public function testTmpdirPrivado(): void
    {
        $privado = DiretorioTemporarioPrivado::doProcesso(ExecucaoDoGhostscript::FINALIDADE_DO_DIRETORIO_PRIVADO)->caminho();
        $saida   = ExecucaoDoGhostscript::rodarComTeto([$this->falso(': > "$TMPDIR/gs_lixo"; printf "%s" "$TMPDIR"')], 10.0, 4096);

        self::assertStringStartsWith($privado . '/', $saida->saida);
        self::assertDirectoryDoesNotExist($saida->saida);
    }

    /** Escreve um script que grava o próprio PID e depois roda `$corpo`. */
    private function falso(string $corpo): string
    {
        $caminho = $this->dir . '/gs-falso-' . bin2hex(random_bytes(4));
        $pid     = escapeshellarg($this->dir . '/pid');
        file_put_contents($caminho, "#!/bin/sh\necho \$\$ > {$pid}\n{$corpo}\n");
        chmod($caminho, 0o700);

        return $caminho;
    }

    private function assertSemFilhoVivo(): void
    {
        $pid = (int) trim((string) file_get_contents($this->dir . '/pid'));

        self::assertGreaterThan(0, $pid);
        self::assertDirectoryDoesNotExist('/proc/' . $pid, 'o processo do gs sobrou (vivo ou zumbi)');
    }
}
