<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClienteDoConector::class)]
final class ClienteDoConectorTest extends TestCase
{
    use CenarioDoConector;

    #[TestDox('Recusa identificador vazio, cliente sem endereço de retorno e endereço vazio')]
    public function testRecusas(): void
    {
        $recusas = 0;
        foreach ([['  ', ['https://claude.ai/api/mcp/auth_callback']], ['https://claude.ai/x', []], ['https://claude.ai/x', ['']]] as [$identificador, $redirects]) {
            try {
                new ClienteDoConector($identificador, TipoDeClienteDoConector::Cimd, 'Claude', $redirects, self::em('now'));
            } catch (\InvalidArgumentException) {
                ++$recusas;
            }
        }

        self::assertSame(3, $recusas);
    }

    #[TestDox('Renovar os metadados troca nome e redirects e grava a validade do cache; desativar fica registrado')]
    public function testRenovarMetadadosEDesativar(): void
    {
        $cliente = self::clienteCimd();
        self::assertTrue($cliente->isAtivo());

        $cliente->renovarMetadados('Claude Code novo', ['http://localhost/callback'], self::em('2026-11-16 11:00'), self::em('2026-11-17 11:00'));
        $cliente->desativar();

        self::assertSame('Claude Code novo', $cliente->getNome());
        self::assertSame(['http://localhost/callback'], $cliente->getRedirectUris());
        self::assertEquals(self::em('2026-11-16 11:00'), $cliente->getMetadadosBuscadosEm());
        self::assertEquals(self::em('2026-11-17 11:00'), $cliente->getMetadadosValidosAte());
        self::assertFalse($cliente->isAtivo());
    }
}
