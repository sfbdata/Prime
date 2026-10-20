<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Http;

use App\Shared\Http\NomeSeguroDeArquivo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;

#[CoversClass(NomeSeguroDeArquivo::class)]
final class NomeSeguroDeArquivoTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function nomes(): iterable
    {
        yield 'barra vira hífen' => ['Contrato 01/2026.pdf', 'Contrato 01-2026.pdf'];
        yield 'contrabarra vira hífen' => ['Ata 3\\2025.docx', 'Ata 3-2025.docx'];
        yield 'caractere de controle sai' => ["Peti\x00ção\n inicial\x7F.pdf", 'Petição inicial.pdf'];
        yield 'acento e porcentagem ficam' => ['Ação 50%.pdf', 'Ação 50%.pdf'];
        yield 'nome comum fica igual' => ['Procuração.pdf', 'Procuração.pdf'];
    }

    #[DataProvider('nomes')]
    #[TestDox('"$entrada" vira "$esperado"')]
    public function testSanear(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, NomeSeguroDeArquivo::sanear($entrada));
    }

    #[TestDox('o nome saneado sempre passa pelo Content-Disposition do Symfony (com o fallback ASCII que ele gera)')]
    public function testPassaPeloContentDisposition(): void
    {
        foreach (['a/b.pdf', 'a\\b.pdf', "a\nb.pdf", 'ação 50%.pdf', '/'] as $nome) {
            $seguro = NomeSeguroDeArquivo::paraDownload($nome);
            $fallback = (string) preg_replace(['/[^\x20-\x7E]/', '/%/'], '_', $seguro);
            self::assertNotSame('', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $seguro, $fallback), $nome);
        }
    }

    #[TestDox('UTF-8 inválido não derruba o nome inteiro: só o controle sai')]
    public function testUtf8InvalidoNaoZeraONome(): void
    {
        self::assertSame("Contrato\xC3 01.pdf", NomeSeguroDeArquivo::sanear("Contrato\xC3 01\x01.pdf"));
    }

    #[TestDox('para o download, nome que fica vazio vira "arquivo"')]
    public function testParaDownloadNuncaVazio(): void
    {
        self::assertSame('arquivo', NomeSeguroDeArquivo::paraDownload("\x01\x02"));
        self::assertSame('-', NomeSeguroDeArquivo::paraDownload('/'));
    }
}
