<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\ChaveDoSelo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O código de validação do selo (contrato da etapa 3, seção 2, item 3) com valores fixos: a fórmula não pode mudar sem
 * mudar os códigos que os documentos novos recebem. Os valores esperados saíram de um HMAC-SHA256 calculado fora do PHP
 * (Python, `hmac` + `hashlib`).
 */
#[CoversClass(ChaveDoSelo::class)]
final class ChaveDoSeloTest extends TestCase
{
    private const SEGREDO = 'selo-de-teste-com-32-bytes-ou-mais-0123456';
    private const FORMATO = '/^BJA-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/';

    #[TestDox('código = HMAC(segredo, v1|{id}|{hash}|{tentativa}), 12 bytes % 32 no alfabeto, em BJA-XXXX-XXXX-XXXX')]
    public function testValorFixo(): void
    {
        $chave = new ChaveDoSelo(self::SEGREDO);

        self::assertSame('BJA-7VPC-4FUF-GW4S', $chave->codigo(1, str_repeat('f', 64)));
        self::assertSame('BJA-QNET-7RFZ-Z6QJ', $chave->codigo(1, str_repeat('f', 64), 1));
        self::assertSame('BJA-LZLN-M6C4-DXFA', $chave->codigo(2, str_repeat('f', 64)));
        self::assertSame('BJA-VQA8-RAVV-4X64', $chave->codigo(1, str_repeat('e', 64)));
    }

    #[TestDox('formato BJA-XXXX-XXXX-XXXX (18 caracteres) só com o alfabeto do desenho: sem I, O, 0 nem 1')]
    public function testFormatoEAlfabeto(): void
    {
        $chave = new ChaveDoSelo(self::SEGREDO);
        self::assertSame(32, \strlen(ChaveDoSelo::ALFABETO));
        self::assertSame(32, \count(array_unique(str_split(ChaveDoSelo::ALFABETO))), 'símbolos repetidos enviesariam o código');
        self::assertDoesNotMatchRegularExpression('/[IO01]/', ChaveDoSelo::ALFABETO);

        $usados = '';
        for ($id = 1; $id <= 200; ++$id) {
            $codigo = $chave->codigo($id, hash('sha256', (string) $id));
            self::assertSame(18, \strlen($codigo));
            self::assertMatchesRegularExpression(self::FORMATO, $codigo);
            $usados .= str_replace(['BJA', '-'], '', $codigo);
        }
        self::assertCount(32, array_unique(str_split($usados)), 'em 2.400 símbolos todo o alfabeto aparece');
    }

    #[TestDox('determinístico: os mesmos dados dão o mesmo código; outra tentativa, outro hash ou outro segredo mudam o código')]
    public function testDeterminismo(): void
    {
        $chave = new ChaveDoSelo(self::SEGREDO);
        $hash = str_repeat('a', 64);

        self::assertSame($chave->codigo(7, $hash), (new ChaveDoSelo(self::SEGREDO))->codigo(7, $hash));
        self::assertSame($chave->codigo(7, $hash), $chave->codigo(7, $hash, 0), 'a tentativa padrão é a zero');
        $tentativas = array_map(static fn (int $t): string => $chave->codigo(7, $hash, $t), range(0, 4));
        self::assertCount(5, array_unique($tentativas), 'cada tentativa da colisão dá um código novo');
        self::assertNotSame($chave->codigo(7, $hash), $chave->codigo(7, str_repeat('b', 64)));
        self::assertNotSame($chave->codigo(7, $hash), (new ChaveDoSelo('outro-segredo-do-selo-com-32-bytes-ou-mais'))->codigo(7, $hash));
    }

    #[TestDox('segredo vazio ou com menos de 32 bytes (sem contar as pontas): sem chave configurada e o código recusa')]
    public function testSegredoCurto(): void
    {
        self::assertTrue((new ChaveDoSelo(str_repeat('x', 32)))->configurada());
        self::assertTrue((new ChaveDoSelo(str_repeat('x', 32) . "\n"))->configurada());

        foreach (['' => 'vazio', str_repeat('x', 31) => '31 bytes', str_repeat('x', 31) . "\n" => 'quebra de linha não conta', ' ' . str_repeat('x', 31) => 'espaço não conta'] as $segredo => $caso) {
            $chave = new ChaveDoSelo((string) $segredo);
            self::assertFalse($chave->configurada(), $caso);
            try {
                $chave->codigo(1, str_repeat('f', 64));
                self::fail('deveria recusar: ' . $caso);
            } catch (AcaoRecusada $e) {
                self::assertSame('assinatura_eletronica.erro.selo_sem_chave', $e->getMessage());
            }
        }
    }
}
