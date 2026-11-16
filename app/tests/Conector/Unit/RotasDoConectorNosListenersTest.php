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
 * - validador de escritório da sessão: as três (senão 302 para `tenant_selecionar`);
 * - Termos de Uso: token e metadado, NÃO o authorize (quem não aceitou aceita antes de conectar);
 * - filtro de escritório da sessão: só o authorize fica sem ele;
 * - destino do login: o authorize.
 */
#[CoversNothing]
final class RotasDoConectorNosListenersTest extends TestCase
{
    /** @return list<string> */
    private static function lista(string $classe, string $constante): array
    {
        /** @var list<string> */
        return (new \ReflectionClassConstant($classe, $constante))->getValue();
    }

    #[TestDox('o validador de escritório da sessão ignora as três rotas do servidor de autorização')]
    public function testValidadorDeEscritorio(): void
    {
        $lista = self::lista(TenantContextValidatorListener::class, 'ROTAS_IGNORADAS');

        foreach ([ServidorDeAutorizacao::ROTA_AUTORIZAR, ServidorDeAutorizacao::ROTA_TOKEN, ServidorDeAutorizacao::ROTA_METADADO] as $rota) {
            self::assertContains($rota, $lista);
        }
    }

    #[TestDox('o portão dos Termos ignora token e metadado, mas confere o authorize')]
    public function testTermosDeUso(): void
    {
        $lista = self::lista(TermoAceiteListener::class, 'ROTAS_IGNORADAS');

        self::assertContains(ServidorDeAutorizacao::ROTA_TOKEN, $lista);
        self::assertContains(ServidorDeAutorizacao::ROTA_METADADO, $lista);
        self::assertNotContains(ServidorDeAutorizacao::ROTA_AUTORIZAR, $lista);
    }

    #[TestDox('só o authorize roda sem o filtro de escritório da sessão')]
    public function testFiltroDaSessao(): void
    {
        self::assertSame([ServidorDeAutorizacao::ROTA_AUTORIZAR], self::lista(TenantFilterListener::class, 'ROTAS_SEM_FILTRO'));
    }

    #[TestDox('o authorize é destino válido do login')]
    public function testDestinoDoLogin(): void
    {
        self::assertContains(ServidorDeAutorizacao::ROTA_AUTORIZAR, self::lista(DestinoPosLogin::class, 'PAGINAS'));
    }
}
