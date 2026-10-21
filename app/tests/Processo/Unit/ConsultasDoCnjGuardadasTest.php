<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Service\ConsultasDoCnjGuardadas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(ConsultasDoCnjGuardadas::class)]
final class ConsultasDoCnjGuardadasTest extends TestCase
{
    #[TestDox('a consulta só volta para o mesmo escritório, pessoa, sessão e número')]
    public function testChavePresaAoEscritorioPessoaSessaoENumero(): void
    {
        $guarda = new ConsultasDoCnjGuardadas(new ArrayAdapter());
        [$tenant, $outroTenant] = [self::comId(new Tenant(), 1), self::comId(new Tenant(), 2)];
        [$ana, $bruno] = [self::comId(new User(), 10), self::comId(new User(), 11)];

        $guarda->guardar($tenant, $ana, 'sessao-a', '00000010020248070001', ['id' => 'x']);

        self::assertSame(['id' => 'x'], $guarda->buscar($tenant, $ana, 'sessao-a', '00000010020248070001'));
        self::assertNull($guarda->buscar($outroTenant, $ana, 'sessao-a', '00000010020248070001'));
        self::assertNull($guarda->buscar($tenant, $bruno, 'sessao-a', '00000010020248070001'));
        self::assertNull($guarda->buscar($tenant, $ana, 'sessao-b', '00000010020248070001'));
        self::assertNull($guarda->buscar($tenant, $ana, 'sessao-a', '00000020020248070001'));
    }

    #[TestDox('a consulta vale por 30 minutos')]
    public function testValidade(): void
    {
        self::assertSame(1800, ConsultasDoCnjGuardadas::VALIDADE_EM_SEGUNDOS);
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private static function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
