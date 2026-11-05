<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Service;

use App\Shared\Service\ConferenteDeSegredos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Conferente do `app:conferir-segredos` (auditoria 4.11): recusa APP_SECRET curto ou igual ao do modelo
 * (.env.prod.example) e chave de cifra que não decodifica para 32 bytes. Nenhum problema traz o valor conferido.
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
}
