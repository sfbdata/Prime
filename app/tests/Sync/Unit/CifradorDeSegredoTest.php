<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Sync\Service\CifradorDeSegredo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CifradorDeSegredo::class)]
final class CifradorDeSegredoTest extends TestCase
{
    private function chave(): string
    {
        // 32 bytes fixos em base64 — só para o teste.
        return base64_encode(str_repeat("\x01", 32));
    }

    #[TestDox('cifrar seguido de decifrar devolve o texto original')]
    public function testCifrarDecifrarRoundtrip(): void
    {
        $cifrador = new CifradorDeSegredo($this->chave());
        $segredo = 'refresh_token_super_secreto_12345';

        $blob = $cifrador->cifrar($segredo);

        self::assertNotSame($segredo, $blob, 'o blob não pode ser o texto puro');
        self::assertNotFalse(base64_decode($blob, true), 'o blob deve ser base64 válido');
        self::assertSame($segredo, $cifrador->decifrar($blob));
    }

    #[TestDox('cada cifragem usa nonce aleatório — blobs diferentes para o mesmo texto')]
    public function testNonceAleatorioProduzBlobsDiferentes(): void
    {
        $cifrador = new CifradorDeSegredo($this->chave());

        $a = $cifrador->cifrar('mesmo-texto');
        $b = $cifrador->cifrar('mesmo-texto');

        self::assertNotSame($a, $b);
        self::assertSame('mesmo-texto', $cifrador->decifrar($a));
        self::assertSame('mesmo-texto', $cifrador->decifrar($b));
    }

    #[TestDox('string vazia também é cifrável e recuperável')]
    public function testCifraStringVazia(): void
    {
        $cifrador = new CifradorDeSegredo($this->chave());

        self::assertSame('', $cifrador->decifrar($cifrador->cifrar('')));
    }

    #[TestDox('chave ausente lança ao cifrar (não no construtor)')]
    public function testChaveAusenteLancaAoUsar(): void
    {
        $cifrador = new CifradorDeSegredo('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SYNC_CRYPTO_KEY não configurada');
        $cifrador->cifrar('x');
    }

    #[TestDox('chave com tamanho errado lança')]
    public function testChaveTamanhoErradoLanca(): void
    {
        $cifrador = new CifradorDeSegredo(base64_encode('curta'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SYNC_CRYPTO_KEY inválida');
        $cifrador->cifrar('x');
    }

    #[TestDox('decifrar com outra chave falha (autenticação Poly1305)')]
    public function testDecifrarComChaveErradaFalha(): void
    {
        $original = new CifradorDeSegredo($this->chave());
        $blob = $original->cifrar('segredo');

        $outro = new CifradorDeSegredo(base64_encode(str_repeat("\x02", 32)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Falha ao decifrar');
        $outro->decifrar($blob);
    }

    #[TestDox('blob adulterado é rejeitado')]
    public function testBlobAdulteradoRejeitado(): void
    {
        $cifrador = new CifradorDeSegredo($this->chave());
        $blob = $cifrador->cifrar('conteudo-integro');

        // Vira os bytes: decodifica, altera o último byte, re-codifica.
        $bruto = base64_decode($blob, true);
        self::assertNotFalse($bruto);
        $bruto[\strlen($bruto) - 1] = $bruto[\strlen($bruto) - 1] === "\x00" ? "\x01" : "\x00";
        $adulterado = base64_encode($bruto);

        $this->expectException(\RuntimeException::class);
        $cifrador->decifrar($adulterado);
    }

    #[TestDox('blob que não é base64 válido é rejeitado')]
    public function testBlobInvalidoRejeitado(): void
    {
        $cifrador = new CifradorDeSegredo($this->chave());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blob cifrado inválido');
        $cifrador->decifrar('!!!nao-e-base64!!!');
    }
}
