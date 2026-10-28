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
        self::assertSame([hash('sha256', 'abc')], $this->entradas(), 'nenhum parcial sobrou');
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

        self::assertSame([], $this->entradas());
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

        self::assertSame([], $this->entradas());
    }

    #[TestDox('teto em bytes: ao publicar, os menos usados saem até caber; o recém-publicado fica')]
    public function testTetoELru(): void
    {
        $cache = new CacheDeLeituraDoEspelho($this->diretorio, 25);
        $a     = $this->hidratar($cache, str_repeat('a', 10));
        $b     = $this->hidratar($cache, str_repeat('b', 10));
        touch($a, time() - 300);
        touch($b, time() - 100);
        // O A é usado de novo: passa a ser o mais recente, e o B vira o menos usado.
        self::assertSame($a, $cache->caminhoDe(hash('sha256', str_repeat('a', 10)), 10));

        $c = $this->hidratar($cache, str_repeat('c', 10));

        self::assertFileExists($a);
        self::assertFileDoesNotExist($b, 'o menos usado saiu');
        self::assertFileExists($c);
    }

    #[TestDox('arquivo maior que o teto sozinho é publicado e entregue; os outros saem')]
    public function testArquivoMaiorQueOTeto(): void
    {
        $cache   = new CacheDeLeituraDoEspelho($this->diretorio, 5);
        $pequeno = $this->hidratar($cache, 'xy');
        touch($pequeno, time() - 100);

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

    #[TestDox('duas hidratações simultâneas da mesma chave terminam num único arquivo íntegro')]
    public function testHidratacoesSimultaneas(): void
    {
        $cache    = new CacheDeLeituraDoEspelho($this->diretorio, 1000);
        $conteudo = 'mesmo conteúdo';
        $sha      = hash('sha256', $conteudo);

        // A segunda hidratação começa e termina enquanto a primeira ainda está escrevendo o parcial dela.
        $caminho = $cache->hidratar($sha, \strlen($conteudo), function ($destino) use ($cache, $conteudo, $sha): void {
            fwrite($destino, substr($conteudo, 0, 5));
            $cache->hidratar($sha, \strlen($conteudo), static function ($outro) use ($conteudo): void {
                fwrite($outro, $conteudo);
            });
            fwrite($destino, substr($conteudo, 5));
        });

        self::assertSame([$sha], $this->entradas());
        self::assertSame($conteudo, file_get_contents($caminho));
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
