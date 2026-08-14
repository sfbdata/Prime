<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Sync\Service\GoogleDriveOAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `createAuthUrl()` do google/apiclient monta a URL localmente (sem rede) — dá para provar que o
 * state e os parâmetros exigidos saem na URL, o que blindou o bug de callback-sempre-403 (B1).
 */
#[CoversClass(GoogleDriveOAuth::class)]
final class GoogleDriveOAuthTest extends TestCase
{
    #[TestDox('a URL de autorização carrega o state, o redirect, offline e consent')]
    public function testUrlCarregaStateEParametros(): void
    {
        $oauth = new GoogleDriveOAuth('client-id-x', 'client-secret-y');

        $url = $oauth->criarUrlDeAutorizacao('https://app.exemplo.com/sync/drive/conexao/callback', 'STATE_ABC_123');

        self::assertStringContainsString('accounts.google.com', $url);
        self::assertStringContainsString('state=STATE_ABC_123', $url, 'sem o state o callback rejeitaria sempre (B1)');
        self::assertStringContainsString('access_type=offline', $url);
        // `select_account` junto do `consent`: sem ele, com o navegador logado na conta antiga
        // o Google pula o seletor e o usuário reconecta a MESMA conta achando que trocou.
        // O apiclient codifica o espaço entre os dois valores, daí o `+`/`%20`.
        self::assertStringContainsString('prompt=', $url);
        self::assertMatchesRegularExpression('/prompt=select_account(\+|%20)consent/', $url);
        self::assertStringContainsString('client_id=client-id-x', $url);
        self::assertStringContainsString('redirect_uri=' . rawurlencode('https://app.exemplo.com/sync/drive/conexao/callback'), $url);
    }

    #[TestDox('sem client_id/secret configurados, lança erro claro')]
    public function testSemCredenciaisLanca(): void
    {
        $oauth = new GoogleDriveOAuth('', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OAuth do Drive incompleto');
        $oauth->criarUrlDeAutorizacao('https://app.exemplo.com/callback', 'S');
    }
}
