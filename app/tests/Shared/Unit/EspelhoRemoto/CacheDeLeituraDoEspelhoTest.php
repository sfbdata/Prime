<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\EspelhoRemoto\CacheDeLeituraDoEspelho;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O cache de leitura do espelho (spec `leitura-pelo-r2-e-liberacao-de-disco.md`, D3, §3.3). */
#[CoversClass(CacheDeLeituraDoEspelho::class)]
final class CacheDeLeituraDoEspelhoTest extends TestCase
{
    private string $diretorio;

    protected function setUp(): void
    {
        $this->diretorio = sys_get_temp_dir() . '/jusprime-teste-cache-unit-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->entradas() as $nome) {
            unlink($this->diretorio . '/' . $nome);
        }
        if (is_dir($this->diretorio)) {
            rmdir($this->diretorio);
        }
    }

    #[TestDox('hidratar grava com o SHA como nome; a segunda hidratação do mesmo conteúdo não baixa de novo')]
    public function testHidrataEReusa(): void
    {
        $cache  = new CacheDeLeituraDoEspelho($this->diretorio, 1000);
        $baixou = 0;

        $caminho = $cache->hidratar(hash('sha256', 'abc'), 3, function ($destino) use (&$baixou): void {
            ++$baixou;
            fwrite($destino, 'abc');
        });
        $deNovo = $cache->hidratar(hash('sha256', 'abc'), 3, function () use (&$baixou): void {
            ++$baixou;
        });

        self::assertSame($this->diretorio . '/' . hash('sha256', 'abc'), $caminho);
        self::assertSame($caminho, $deNovo);
        self::assertSame(1, $baixou);
        self::assertSame('abc', $cache->ler($caminho));
        self::assertSame('abc', stream_get_contents($cache->abrir($caminho)));
        self::assertSame(['.trava-' . hash('sha256', 'abc'), hash('sha256', 'abc')], $this->entradas(), 'nenhum parcial sobrou; a trava fica');
    }

    #[TestDox('conteúdo que não confere (SHA ou tamanho) não é publicado, e o parcial sai')]
    public function testConteudoQueNaoConfereNaoEhPublicado(): void
    {
        $cache = new CacheDeLeituraDoEspelho($this->diretorio, 1000);

        foreach ([['abd', 3], ['abcd', 3]] as [$escrito, $tamanho]) {
            try {
                $cache->hidratar(hash('sha256', 'abc'), $tamanho, static function ($destino) use ($escrito): void {
                    fwrite($destino, $escrito);
                });
                self::fail('devia recusar ' . $escrito);
            } catch (FalhaNoEspelhoRemoto) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame([], $this->publicadosEParciais());
        self::assertNull($cache->caminhoDe(hash('sha256', 'abc'), 3));
    }

    #[TestDox('exceção do download sobe como veio e não deixa arquivo')]
    public function testFalhaDoDownloadNaoDeixaArquivo(): void
    {
        $cache = new CacheDeLeituraDoEspelho($this->diretorio, 1000);

        try {
            $cache->hidratar(hash('sha256', 'abc'), 3, static function ($destino): void {
                fwrite($destino, 'ab');

                throw new FalhaNoEspelhoRemoto('rede caiu');
            });
            self::fail('devia relançar');
        } catch (FalhaNoEspelhoRemoto $e) {
            self::assertSame('rede caiu', $e->getMessage());
        }

        self::assertSame([], $this->publicadosEParciais());
    }

    #[TestDox('teto em bytes: ao publicar, os menos usados (e frios) saem até caber; o recém-publicado fica')]
    public function testTetoELru(): void
    {
        $cache = new CacheDeLeituraDoEspelho($this->diretorio, 25);
        $a     = $this->hidratar($cache, str_repeat('a', 10));
        $b     = $this->hidratar($cache, str_repeat('b', 10));
        touch($a, time() - 7200);
        touch($b, time() - 3600);
        // O A é usado de novo: passa a ser o mais recente, e o B vira o menos usado.
        self::assertSame($a, $cache->caminhoDe(hash('sha256', str_repeat('a', 10)), 10));

        $c = $this->hidratar($cache, str_repeat('c', 10));

        self::assertFileExists($a);
        self::assertFileDoesNotExist($b, 'o menos usado saiu');
        self::assertFileExists($c);
    }

    #[TestDox('arquivo usado nos últimos 30 minutos nunca sai, mesmo passando do teto (o cache passa do teto por um tempo)')]
    public function testUsoRecenteNaoSai(): void
    {
        $cache = new CacheDeLeituraDoEspelho($this->diretorio, 1);
        $a     = $this->hidratar($cache, 'aaaa');
        touch($a, time() - CacheDeLeituraDoEspelho::PROTECAO_DE_USO_RECENTE_EM_SEGUNDOS + 60);
        $b = $this->hidratar($cache, 'bbbb');

        self::assertFileExists($a, 'usado há 29 minutos: fica');
        self::assertFileExists($b);

        touch($a, time() - CacheDeLeituraDoEspelho::PROTECAO_DE_USO_RECENTE_EM_SEGUNDOS - 60);
        $c = $this->hidratar($cache, 'cccc');

        self::assertFileDoesNotExist($a, 'esfriou: sai na próxima gravação');
        self::assertFileExists($b);
        self::assertFileExists($c);
    }

    #[TestDox('os parciais em curso contam no teto (e não são apagados pelo descarte)')]
    public function testParcialContaNoTeto(): void
    {
        $cache = new CacheDeLeituraDoEspelho($this->diretorio, 25);
        $velho = $this->hidratar($cache, str_repeat('v', 10));
        touch($velho, time() - 7200);
        file_put_contents($this->diretorio . '/.parcial-em-curso', str_repeat('p', 10));

        $this->hidratar($cache, str_repeat('n', 10));

        self::assertFileDoesNotExist($velho, '10 + 10 do parcial + 10 novos passa de 25: o frio sai');
        self::assertFileExists($this->diretorio . '/.parcial-em-curso');
    }

    #[TestDox('arquivo maior que o teto sozinho é publicado e entregue; os frios saem')]
    public function testArquivoMaiorQueOTeto(): void
    {
        $cache   = new CacheDeLeituraDoEspelho($this->diretorio, 5);
        $pequeno = $this->hidratar($cache, 'xy');
        touch($pequeno, time() - 3600);

        $grande = $this->hidratar($cache, str_repeat('g', 50));

        self::assertFileExists($grande);
        self::assertFileDoesNotExist($pequeno);
    }

    #[TestDox('parcial órfão com mais de uma hora é limpo na próxima gravação; o recente fica')]
    public function testParcialOrfaoEhLimpo(): void
    {
        mkdir($this->diretorio, 0o750, true);
        file_put_contents($this->diretorio . '/.parcial-velho', 'x');
        touch($this->diretorio . '/.parcial-velho', time() - CacheDeLeituraDoEspelho::IDADE_DO_PARCIAL_ORFAO_EM_SEGUNDOS - 10);
        file_put_contents($this->diretorio . '/.parcial-recente', 'y');

        $this->hidratar(new CacheDeLeituraDoEspelho($this->diretorio, 1000), 'novo');

        self::assertFileDoesNotExist($this->diretorio . '/.parcial-velho');
        self::assertFileExists($this->diretorio . '/.parcial-recente', 'pode ser um download em curso de outro processo');
    }

    #[TestDox('duas hidratações simultâneas da mesma chave (dois processos): baixa UMA vez, a outra espera a trava e lê do cache')]
    public function testHidratacoesSimultaneasBaixamUmaVez(): void
    {
        $conteudo = str_repeat('mesmo conteúdo ', 100);
        $sha      = hash('sha256', $conteudo);
        $sinal    = $this->diretorio . '-sinal';
        $processo = $this->outroProcessoHidratando($sha, $conteudo, $sinal);

        try {
            $this->esperarArquivo($sinal);
            $baixou  = 0;
            $caminho = (new CacheDeLeituraDoEspelho($this->diretorio, 1_000_000))->hidratar($sha, \strlen($conteudo), function ($destino) use (&$baixou, $conteudo): void {
                ++$baixou;
                fwrite($destino, $conteudo);
            });
        } finally {
            $saida = stream_get_contents($processo['saida']) . stream_get_contents($processo['erro']);
            $codigo = proc_close($processo['proc']);
            @unlink($sinal);
        }

        self::assertSame(0, $codigo, $saida);
        self::assertSame(0, $baixou, 'esperou a trava e achou o arquivo publicado pelo outro processo');
        self::assertSame($conteudo, file_get_contents($caminho));
        self::assertSame([$sha], $this->publicadosEParciais());
    }

    #[TestDox('arquivo no cache com tamanho diferente do esperado não é entregue')]
    public function testTamanhoDiferenteNaoEhEntregue(): void
    {
        $cache = new CacheDeLeituraDoEspelho($this->diretorio, 1000);
        $this->hidratar($cache, 'abc');

        self::assertNull($cache->caminhoDe(hash('sha256', 'abc'), 4));
    }

    #[TestDox('abrir, ler e copiar só aceitam arquivo publicado deste cache')]
    public function testSoArquivoDoCache(): void
    {
        $cache = new CacheDeLeituraDoEspelho($this->diretorio, 1000);

        $this->expectException(\InvalidArgumentException::class);
        $cache->ler('/etc/passwd');
    }

    /**
     * Um processo PHP de verdade que hidrata o mesmo conteúdo e, já com a trava, avisa pelo `$sinal` e
     * demora para escrever: é a janela em que a outra hidratação chega.
     *
     * @return array{proc: resource, saida: resource, erro: resource}
     */
    private function outroProcessoHidratando(string $sha, string $conteudo, string $sinal): array
    {
        $codigo = sprintf(
            'require %s; (new App\\Shared\\EspelhoRemoto\\CacheDeLeituraDoEspelho(%s, 1000000))->hidratar(%s, %d, function ($d) { file_put_contents(%s, "1"); usleep(700000); fwrite($d, %s); });',
            var_export(\dirname(__DIR__, 4) . '/vendor/autoload.php', true),
            var_export($this->diretorio, true),
            var_export($sha, true),
            \strlen($conteudo),
            var_export($sinal, true),
            var_export($conteudo, true),
        );
        $proc = proc_open([\PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);

        return ['proc' => $proc, 'saida' => $pipes[1], 'erro' => $pipes[2]];
    }

    private function esperarArquivo(string $caminho): void
    {
        for ($i = 0; $i < 200 && !is_file($caminho); ++$i) {
            usleep(50_000);
        }
        self::assertFileExists($caminho, 'o outro processo não chegou a baixar');
    }

    /** @return list<string> o que ocupa espaço no cache (sem as travas, que são vazias) */
    private function publicadosEParciais(): array
    {
        return array_values(array_filter($this->entradas(), static fn (string $nome): bool => !str_starts_with($nome, '.trava-')));
    }

    private function hidratar(CacheDeLeituraDoEspelho $cache, string $conteudo): string
    {
        return $cache->hidratar(hash('sha256', $conteudo), \strlen($conteudo), static function ($destino) use ($conteudo): void {
            fwrite($destino, $conteudo);
        });
    }

    /** @return list<string> */
    private function entradas(): array
    {
        if (!is_dir($this->diretorio)) {
            return [];
        }

        $entradas = array_values(array_diff(scandir($this->diretorio) ?: [], ['.', '..']));
        sort($entradas);

        return $entradas;
    }
}
