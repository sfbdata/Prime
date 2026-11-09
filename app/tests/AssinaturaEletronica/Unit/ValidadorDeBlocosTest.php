<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\ValidadorDeBlocos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidadorDeBlocos::class)]
final class ValidadorDeBlocosTest extends TestCase
{
    #[TestDox('guarda só os campos conhecidos de cada tipo de bloco')]
    public function testSoCamposConhecidos(): void
    {
        $blocos = ValidadorDeBlocos::normalizar([
            ['k' => 't', 'x' => 'Título', 'full' => true, 'style' => 'color:red'],
            ['k' => 'p', 'x' => 'Parágrafo'],
            ['k' => 'n', 'x' => 12],
            ['k' => 'a', 'i' => 0, 'r' => 'Contratante', 'img' => 'data:...'],
            ['k' => 'a', 'i' => 3],
            ['k' => 'pg', 'n' => 2, 'src' => 'data:image/png;base64,AAAA'],
        ], true, 2);

        self::assertSame([
            ['k' => 't', 'x' => 'Título', 'full' => 1],
            ['k' => 'p', 'x' => 'Parágrafo', 'full' => 0],
            ['k' => 'n', 'x' => '12', 'full' => 0],
            ['k' => 'a', 'i' => 0, 'r' => 'Contratante'],
            ['k' => 'a', 'i' => 3, 'r' => ''],
            ['k' => 'pg', 'n' => 2],
        ], $blocos);
    }

    #[TestDox('todos os tipos de texto do contrato passam: t, s, h, l, p, d, n')]
    public function testTiposDeTexto(): void
    {
        $blocos = array_map(static fn (string $k): array => ['k' => $k, 'x' => 'a'], ['t', 's', 'h', 'l', 'p', 'd', 'n']);

        self::assertCount(7, ValidadorDeBlocos::normalizar($blocos, false, null));
    }

    #[TestDox('no limite: 2.000 blocos, texto de 20.000 caracteres, papel do bloco de 500 e página até 2.000 sem contagem')]
    public function testNoLimite(): void
    {
        self::assertCount(1, ValidadorDeBlocos::normalizar([['k' => 'a', 'i' => 0, 'r' => str_repeat('é', 500)]], false, null));
        self::assertCount(2000, ValidadorDeBlocos::normalizar(array_fill(0, 2000, ['k' => 'l', 'x' => '']), false, null));
        self::assertCount(1, ValidadorDeBlocos::normalizar([['k' => 'p', 'x' => str_repeat('á', 20000)]], false, null));
        self::assertCount(1, ValidadorDeBlocos::normalizar([['k' => 'pg', 'n' => 2000]], true, null));
        self::assertCount(1, ValidadorDeBlocos::normalizar([['k' => 'pg', 'n' => 1]], true, 1));
    }

    /** @return iterable<string, array{array<mixed>, bool, ?int, string}> */
    public static function recusados(): iterable
    {
        yield '2.001 blocos' => [array_fill(0, 2001, ['k' => 'l', 'x' => '']), false, null, 'blocos_demais'];
        yield 'texto com 20.001' => [[['k' => 'p', 'x' => str_repeat('a', 20001)]], false, null, 'bloco_texto_longo'];
        yield 'tipo desconhecido' => [[['k' => 'img', 'x' => 'a']], false, null, 'bloco_invalido'];
        yield 'sem tipo' => [[['x' => 'a']], false, null, 'bloco_invalido'];
        yield 'bloco que não é objeto' => [['texto'], false, null, 'bloco_invalido'];
        yield 'texto em lista' => [[['k' => 'p', 'x' => ['a']]], false, null, 'bloco_invalido'];
        yield 'índice negativo' => [[['k' => 'a', 'i' => -1]], false, null, 'bloco_invalido'];
        yield 'índice em texto' => [[['k' => 'a', 'i' => '1']], false, null, 'bloco_invalido'];
        yield 'sem índice' => [[['k' => 'a']], false, null, 'bloco_invalido'];
        yield 'papel do bloco com 501' => [[['k' => 'a', 'i' => 0, 'r' => str_repeat('a', 501)]], false, null, 'bloco_invalido'];
        yield 'página sem PDF' => [[['k' => 'pg', 'n' => 1]], false, null, 'pagina_sem_arquivo'];
        yield 'página 0' => [[['k' => 'pg', 'n' => 0]], true, 3, 'pagina_invalida'];
        yield 'página além do PDF' => [[['k' => 'pg', 'n' => 4]], true, 3, 'pagina_invalida'];
        yield 'página 2.001 sem contagem' => [[['k' => 'pg', 'n' => 2001]], true, null, 'pagina_invalida'];
        yield 'blocos que não são lista' => [['a' => ['k' => 'p', 'x' => '']], false, null, 'dados_invalidos'];
    }

    /** @param array<mixed> $blocos */
    #[DataProvider('recusados')]
    #[TestDox('recusa: $_dataName')]
    public function testRecusa(array $blocos, bool $temArquivo, ?int $paginas, string $erro): void
    {
        try {
            ValidadorDeBlocos::normalizar($blocos, $temArquivo, $paginas);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.' . $erro, $e->getMessage());
        }
    }

    #[TestDox('o papel do lugar de assinatura aceita dado do cliente maior que o papel do signatário (razão social de 150, por exemplo)')]
    public function testPapelDoBlocoComDadoDoCliente(): void
    {
        $papel = mb_substr('Contratante: ' . str_repeat('Razão Social Ltda ', 20), 0, 300);
        self::assertSame(300, mb_strlen($papel));

        $blocos = ValidadorDeBlocos::normalizar([['k' => 'a', 'i' => 0, 'r' => $papel]], false, null);

        self::assertSame(['k' => 'a', 'i' => 0, 'r' => $papel], $blocos[0]);
    }

    #[TestDox('o erro do bloco diz a posição, a partir de 1')]
    public function testPosicaoNaMensagem(): void
    {
        try {
            ValidadorDeBlocos::normalizar([['k' => 'p', 'x' => ''], ['k' => 'zz']], false, null);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame(['posicao' => 2], $e->parametros);
        }
    }
}
