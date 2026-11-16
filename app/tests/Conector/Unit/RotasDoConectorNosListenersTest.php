<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Auth\Service\DestinoPosLogin;
use App\Conector\Service\ServidorDeAutorizacao;
use App\EventListener\TenantContextValidatorListener;
use App\EventListener\TermoAceiteListener;
use App\Shared\EventListener\TenantFilterListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As rotas do servidor de autorização nas listas dos listeners de `kernel.request` (spec mcp-remoto-oauth 8), pelas
 * constantes do {@see ServidorDeAutorizacao}: se o nome de uma rota mudar, a lista tem de mudar junto.
 *
 * - validador de escritório da sessão: as três do servidor de autorização, o `/mcp` e as duas do metadado do recurso
 *   (senão 302 para `tenant_selecionar`);
 * - Termos de Uso: token, metadados e `/mcp` (no `/mcp` quem confere é o portão, 5.2), NÃO o authorize (quem não
 *   aceitou aceita antes de conectar);
 * - filtro de escritório da sessão: o authorize, o token e o `/mcp` ficam sem ele (no `/mcp`, quem liga é o portão);
 * - destino do login: o authorize.
 */
#[CoversNothing]
final class RotasDoConectorNosListenersTest extends TestCase
{
    /** O `/mcp` e o metadado do recurso nas duas URLs (PR 5). */
    private const ROTAS_DO_RECURSO = [
        ServidorDeAutorizacao::ROTA_MCP,
        ServidorDeAutorizacao::ROTA_METADADO_DO_RECURSO,
        ServidorDeAutorizacao::ROTA_METADADO_DO_RECURSO_RAIZ,
    ];

    /** @return list<string> */
    private static function lista(string $classe, string $constante): array
    {
        /** @var list<string> */
        return (new \ReflectionClassConstant($classe, $constante))->getValue();
    }

    #[TestDox('o validador de escritório da sessão ignora as três rotas do servidor de autorização, o /mcp e as duas do metadado do recurso')]
    public function testValidadorDeEscritorio(): void
    {
        $lista = self::lista(TenantContextValidatorListener::class, 'ROTAS_IGNORADAS');

        foreach ([ServidorDeAutorizacao::ROTA_AUTORIZAR, ServidorDeAutorizacao::ROTA_TOKEN, ServidorDeAutorizacao::ROTA_METADADO, ...self::ROTAS_DO_RECURSO] as $rota) {
            self::assertContains($rota, $lista);
        }
    }

    #[TestDox('o portão dos Termos ignora token, metadados e /mcp, mas confere o authorize')]
    public function testTermosDeUso(): void
    {
        $lista = self::lista(TermoAceiteListener::class, 'ROTAS_IGNORADAS');

        self::assertContains(ServidorDeAutorizacao::ROTA_TOKEN, $lista);
        self::assertContains(ServidorDeAutorizacao::ROTA_METADADO, $lista);
        foreach (self::ROTAS_DO_RECURSO as $rota) {
            self::assertContains($rota, $lista);
        }
        self::assertNotContains(ServidorDeAutorizacao::ROTA_AUTORIZAR, $lista);
    }

    #[TestDox('só o authorize, o token e o /mcp rodam sem o filtro de escritório da sessão')]
    public function testFiltroDaSessao(): void
    {
        self::assertSame(
            [ServidorDeAutorizacao::ROTA_AUTORIZAR, ServidorDeAutorizacao::ROTA_TOKEN, ServidorDeAutorizacao::ROTA_MCP],
            self::lista(TenantFilterListener::class, 'ROTAS_SEM_FILTRO'),
        );
    }

    #[TestDox('o authorize é destino válido do login')]
    public function testDestinoDoLogin(): void
    {
        self::assertContains(ServidorDeAutorizacao::ROTA_AUTORIZAR, self::lista(DestinoPosLogin::class, 'PAGINAS'));
    }
}
