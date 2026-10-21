<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Contribua\DTO\Solicitante;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Service\NumeroDaConta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Número da conta (spec perfis-1.2-permissoes-e-hierarquia A1 e A2): o id do escritório com 6 dígitos, num ponto
 * comum do domínio Tenant; a Contribua continua com o mesmo formato.
 */
#[CoversClass(NumeroDaConta::class)]
#[CoversClass(Solicitante::class)]
final class NumeroDaContaTest extends TestCase
{
    #[TestDox('o id com 6 dígitos, zeros à esquerda; id maior que 6 dígitos sai inteiro')]
    public function testFormato(): void
    {
        self::assertSame('000123', NumeroDaConta::doId(123));
        self::assertSame('000007', NumeroDaConta::doId(7));
        self::assertSame('1234567', NumeroDaConta::doId(1234567));
    }

    #[TestDox('a Contribua usa o ponto comum, sem mudar o formato do "conta Nº"')]
    public function testContribuaInalterada(): void
    {
        $conta = new Tenant();
        $id = new \ReflectionProperty(Tenant::class, 'id');
        $id->setValue($conta, 42);

        self::assertSame('000042', NumeroDaConta::daConta($conta));
        self::assertSame('000042', (new Solicitante(new User(), $conta, false, true))->numeroDaConta());
    }
}
