<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\NomesDeEntradaDoZip;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os nomes das entradas do .zip (D5): o que vem do usuário vira caminho na máquina de quem extrai,
 * então travessia, separador, controle e colisão são tratados AQUI — antes de qualquer `addFile`.
 */
#[CoversClass(NomesDeEntradaDoZip::class)]
final class NomesDeEntradaDoZipTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function nomesMaliciosos(): iterable
    {
        yield 'travessia unix'          => ['../../etc/passwd', '_._etc_passwd'];
        yield 'só dois pontos'          => ['..', NomesDeEntradaDoZip::SEM_NOME];
        yield 'só um ponto'             => ['.', NomesDeEntradaDoZip::SEM_NOME];
        yield 'vazio'                   => ['', NomesDeEntradaDoZip::SEM_NOME];
        yield 'byte nulo'               => ["a\x00b.pdf", 'ab.pdf'];
        yield 'quebra de linha'         => ["linha\nquebrada.pdf", 'linhaquebrada.pdf'];
        yield 'caminho windows'         => ['C:\\x\\y.pdf', 'C:_x_y.pdf'];
        yield 'barra inicial'           => ['/absoluto.pdf', '_absoluto.pdf'];
        yield 'dois pontos no meio'     => ['arquivo..pdf', 'arquivo.pdf'];
        yield 'ponto no fim'            => ['nome.', 'nome'];
        yield 'espaços nas bordas'      => ['  espaços  .pdf', 'espaços  .pdf'];
        yield 'nome legítimo com acento' => ['relatório final (v2).pdf', 'relatório final (v2).pdf'];
    }

    #[TestDox('sanitizar: $_dataName')]
    #[DataProvider('nomesMaliciosos')]
    public function testSanitizar(string $entrada, string $esperado): void
    {
        $saida = NomesDeEntradaDoZip::sanitizar($entrada);

        self::assertSame($esperado, $saida);
        self::assertStringNotContainsString('..', $saida);
        self::assertStringNotContainsString('/', $saida);
        self::assertStringNotContainsString('\\', $saida);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $saida);
        self::assertNotSame('', $saida);
    }

    #[TestDox('nomes repetidos no mesmo diretório ganham (2), (3)… antes da extensão, sem distinguir caixa')]
    public function testUnicosPorDiretorio(): void
    {
        $nomes = new NomesDeEntradaDoZip();

        self::assertSame('rel.pdf', $nomes->reservar('', 'rel.pdf'));
        self::assertSame('rel (2).pdf', $nomes->reservar('', 'rel.pdf'));
        self::assertSame('REL (3).PDF', $nomes->reservar('', 'REL.PDF'), 'Windows extrai sem distinguir caixa');
        self::assertSame('rel.pdf', $nomes->reservar('Outra', 'rel.pdf'), 'outro diretório, outro espaço de nomes');
    }

    #[TestDox('subpasta repetida vira "Nome (2)" — sem procurar extensão em nome de pasta')]
    public function testSubpastas(): void
    {
        $nomes = new NomesDeEntradaDoZip();

        self::assertSame('Docs', $nomes->reservar('', 'Docs', ehPasta: true));
        self::assertSame('Docs (2)', $nomes->reservar('', 'Docs', ehPasta: true));
        self::assertSame('2024.01', $nomes->reservar('', '2024.01', ehPasta: true));
        self::assertSame('2024.01 (2)', $nomes->reservar('', '2024.01', ehPasta: true), 'pasta: o ".01" não é extensão');
    }

    #[TestDox('a extensão só é separada quando é curta e alfanumérica')]
    public function testExtensao(): void
    {
        $nomes = new NomesDeEntradaDoZip();

        $nomes->reservar('', 'arquivo.tar.gz');
        self::assertSame('arquivo.tar (2).gz', $nomes->reservar('', 'arquivo.tar.gz'));

        $nomes->reservar('', 'sem extensão');
        self::assertSame('sem extensão (2)', $nomes->reservar('', 'sem extensão'));

        $nomes->reservar('', '.htaccess');
        self::assertSame('htaccess (2)', $nomes->reservar('', '.htaccess'), 'o ponto inicial sai na sanitização; sem extensão, o sufixo vai no fim');

        $nomes->reservar('', 'versão.rev 2');
        self::assertSame('versão.rev 2 (2)', $nomes->reservar('', 'versão.rev 2'), '".rev 2" não é extensão');

        self::assertSame(['nome', '.PDF'], NomesDeEntradaDoZip::separarExtensao('nome.PDF'));
        self::assertSame(['nome.versão final', ''], NomesDeEntradaDoZip::separarExtensao('nome.versão final'));
    }

    #[TestDox('o LEIA-ME reservado antes fica com o nome; o arquivo do usuário com o mesmo nome vira (2)')]
    public function testLeiaMeReservadoAntes(): void
    {
        $nomes = new NomesDeEntradaDoZip();

        self::assertSame('LEIA-ME.txt', $nomes->reservar('', 'LEIA-ME.txt'));
        self::assertSame('leia-me (2).txt', $nomes->reservar('', 'leia-me.txt'));
    }

    #[TestDox('nome que vira travessia é sanitizado ANTES de ficar único: "../x" duas vezes não colide com nada de fora')]
    public function testSanitizaAntesDeUnificar(): void
    {
        $nomes = new NomesDeEntradaDoZip();

        self::assertSame('_x.pdf', $nomes->reservar('', '../x.pdf'));
        self::assertSame('_x (2).pdf', $nomes->reservar('', '../x.pdf'));
    }
}
