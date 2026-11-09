<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Service;

use App\Shared\Service\ConferenteDeSegredos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Conferente do `app:conferir-segredos` (auditoria 4.11): recusa APP_SECRET curto ou igual ao do modelo
 * (.env.prod.example) e chave de cifra que não decodifica para 32 bytes. Na Assinatura de documentos, o segredo do link
 * e do código e a chave da selfie, ausentes, fora do formato ou iguais aos do modelo. Nenhum problema traz o valor
 * conferido.
 */
#[CoversClass(ConferenteDeSegredos::class)]
final class ConferenteDeSegredosTest extends TestCase
{
    private string $segredo;
    private string $chaveSync;
    private string $chaveContribua;
    private string $segredoDoModelo;
    private string $modelo;

    protected function setUp(): void
    {
        $this->segredo = bin2hex(random_bytes(16));
        $this->chaveSync = base64_encode(random_bytes(32));
        $this->chaveContribua = base64_encode(random_bytes(32));
        $this->segredoDoModelo = 'modelo_' . bin2hex(random_bytes(16));
        $this->modelo = "# comentário\nAPP_ENV=prod\nAPP_SECRET=\"{$this->segredoDoModelo}\"\nSYNC_CRYPTO_KEY=\n";
    }

    /** @return list<string> */
    private function conferir(?string $segredo = null, ?string $sync = null, ?string $contribua = null): array
    {
        return (new ConferenteDeSegredos())->conferir(
            $segredo ?? $this->segredo,
            $sync ?? $this->chaveSync,
            $contribua ?? $this->chaveContribua,
            $this->modelo,
        );
    }

    #[TestDox('Tudo certo: nenhum problema')]
    public function testTudoOk(): void
    {
        self::assertSame([], $this->conferir());
    }

    #[TestDox('APP_SECRET com menos de 32 caracteres é recusado, sem mostrar o valor')]
    public function testAppSecretCurto(): void
    {
        $curto = bin2hex(random_bytes(15)) . 'a';

        $problemas = $this->conferir(segredo: $curto);

        self::assertCount(1, $problemas);
        self::assertStringContainsString('APP_SECRET', $problemas[0]);
        self::assertStringContainsString('32', $problemas[0]);
        self::assertStringNotContainsString($curto, implode("\n", $problemas));
    }

    #[TestDox('APP_SECRET vazio é recusado')]
    public function testAppSecretVazio(): void
    {
        self::assertCount(1, $this->conferir(segredo: ''));
    }

    #[TestDox('APP_SECRET igual ao do modelo (.env.prod.example) é recusado, sem mostrar o valor')]
    public function testAppSecretIgualAoDoModelo(): void
    {
        $problemas = $this->conferir(segredo: $this->segredoDoModelo);

        self::assertCount(1, $problemas);
        self::assertStringContainsString('.env.prod.example', $problemas[0]);
        self::assertStringNotContainsString($this->segredoDoModelo, implode("\n", $problemas));
    }

    #[TestDox('Chave do sync que não decodifica para 32 bytes é recusada')]
    public function testChaveDoSyncInvalida(): void
    {
        $invalida = base64_encode(random_bytes(16));

        $problemas = $this->conferir(sync: $invalida);

        self::assertCount(1, $problemas);
        self::assertStringContainsString('SYNC_CRYPTO_KEY', $problemas[0]);
        self::assertStringNotContainsString($invalida, implode("\n", $problemas));
    }

    #[TestDox('Chave do Contribua que não é base64 é recusada')]
    public function testChaveDoContribuaInvalida(): void
    {
        $problemas = $this->conferir(contribua: 'isto nao e base64!');

        self::assertCount(1, $problemas);
        self::assertStringContainsString('CONTRIBUA_CRYPTO_KEY', $problemas[0]);
    }

    #[TestDox('Chave de cifra ausente é recusada')]
    public function testChaveAusente(): void
    {
        self::assertCount(2, $this->conferir(sync: '', contribua: ''));
    }

    #[TestDox('Chave de cifra igual à do modelo é recusada mesmo com 32 bytes')]
    public function testChaveIgualADoModelo(): void
    {
        $this->modelo .= "CONTRIBUA_CRYPTO_KEY={$this->chaveContribua}\n";

        $problemas = $this->conferir();

        self::assertCount(1, $problemas);
        self::assertStringContainsString('CONTRIBUA_CRYPTO_KEY', $problemas[0]);
        self::assertStringNotContainsString($this->chaveContribua, implode("\n", $problemas));
    }

    #[TestDox('Assinatura: segredo de 32 caracteres e chave da selfie de 64 hexadecimais, nenhum problema')]
    public function testAssinaturaOk(): void
    {
        self::assertSame([], (new ConferenteDeSegredos())->conferirAssinatura(str_repeat('s', 32), str_repeat('ab', 32), $this->modelo));
        self::assertSame([], (new ConferenteDeSegredos())->conferirAssinatura(str_repeat('s', 40) . "\n", strtoupper(str_repeat('ab', 32)), $this->modelo), 'o segredo longo com quebra de linha é o que o sistema aceita');
    }

    /** @return iterable<string, array{string, string, string}> o segredo, a chave da selfie e o nome recusado */
    public static function assinaturaRecusada(): iterable
    {
        $chave = str_repeat('ab', 32);
        yield 'segredo ausente' => ['', $chave, 'ASSINATURA_SEGREDO'];
        yield 'segredo com 31 caracteres' => [str_repeat('s', 31), $chave, 'ASSINATURA_SEGREDO'];
        yield 'segredo curto com quebra de linha' => [str_repeat('s', 31) . "\n", $chave, 'ASSINATURA_SEGREDO'];
        yield 'chave da selfie ausente' => [str_repeat('s', 32), '', 'ASSINATURA_SELFIE_CHAVE'];
        yield 'chave da selfie com 63 caracteres' => [str_repeat('s', 32), substr($chave, 0, 63), 'ASSINATURA_SELFIE_CHAVE'];
        yield 'chave da selfie com quebra de linha' => [str_repeat('s', 32), $chave . "\n", 'ASSINATURA_SELFIE_CHAVE'];
        yield 'chave da selfie com espaço antes' => [str_repeat('s', 32), ' ' . $chave, 'ASSINATURA_SELFIE_CHAVE'];
        yield 'chave da selfie que não é hexadecimal' => [str_repeat('s', 32), 'g' . substr($chave, 1), 'ASSINATURA_SELFIE_CHAVE'];
    }

    #[DataProvider('assinaturaRecusada')]
    #[TestDox('Assinatura recusa: $_dataName, só com o nome e sem o valor')]
    public function testAssinaturaRecusada(string $segredo, string $chaveDaSelfie, string $nome): void
    {
        $problemas = (new ConferenteDeSegredos())->conferirAssinatura($segredo, $chaveDaSelfie, $this->modelo);

        self::assertCount(1, $problemas);
        self::assertStringContainsString($nome, $problemas[0]);
        foreach (array_filter([trim($segredo), trim($chaveDaSelfie)]) as $valor) {
            self::assertStringNotContainsString($valor, $problemas[0]);
        }
    }

    #[TestDox('Assinatura: segredo e chave da selfie iguais aos do modelo são recusados, sem mostrar os valores')]
    public function testAssinaturaIgualAoModelo(): void
    {
        $segredo = 'modelo_assinatura_' . bin2hex(random_bytes(16));
        $chave = bin2hex(random_bytes(32));
        $this->modelo .= "ASSINATURA_SEGREDO={$segredo}\nASSINATURA_SELFIE_CHAVE='{$chave}'\n";

        $problemas = (new ConferenteDeSegredos())->conferirAssinatura($segredo, $chave, $this->modelo);

        self::assertCount(2, $problemas);
        self::assertStringContainsString('ASSINATURA_SEGREDO', $problemas[0]);
        self::assertStringContainsString('ASSINATURA_SELFIE_CHAVE', $problemas[1]);
        self::assertStringNotContainsString($segredo, implode("\n", $problemas));
        self::assertStringNotContainsString($chave, implode("\n", $problemas));
    }
}
