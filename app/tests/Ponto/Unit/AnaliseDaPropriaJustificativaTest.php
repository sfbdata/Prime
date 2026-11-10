<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * P43 a do desenho do Ponto: o primário e o Master analisam a própria justificativa; quem gere pelo
 * perfil e não é primário nem Master, não. A de outra pessoa não passa por esta regra.
 */
#[CoversClass(AnaliseDaPropriaJustificativa::class)]
final class AnaliseDaPropriaJustificativaTest extends TestCase
{
    #[TestDox('Primário ativo analisa a própria')]
    public function testPrimarioAnalisaAPropria(): void
    {
        $eu = $this->usuario(10);

        self::assertTrue($this->regra(primario: true)->permite($eu, $eu, new Tenant()));
    }

    #[TestDox('Master analisa a própria, mesmo sem perfil de primário')]
    public function testMasterAnalisaAPropria(): void
    {
        $master = $this->usuario(11, ['ROLE_SUPER_ADMIN']);

        self::assertTrue($this->regra(primario: false)->permite($master, $master, new Tenant()));
    }

    #[TestDox('Quem gere pelo perfil e não é primário nem Master não analisa a própria')]
    public function testGestorSecundarioNaoAnalisaAPropria(): void
    {
        $eu = $this->usuario(12);

        self::assertFalse($this->regra(primario: false)->permite($eu, $eu, new Tenant()));
    }

    #[TestDox('A própria vale pelo id, não só pela mesma instância')]
    public function testMesmaPessoaPorId(): void
    {
        self::assertFalse($this->regra(primario: false)->permite($this->usuario(13), $this->usuario(13), new Tenant()));
    }

    #[TestDox('Justificativa de outra pessoa: a regra não se mete (fica a de hoje)')]
    public function testOutraPessoaNaoPassaPelaRegra(): void
    {
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->expects(self::never())->method('findAtivoPorUserETenant');
        $regra = new AnaliseDaPropriaJustificativa(new RegraDosPrimarios($vinculos));

        self::assertTrue($regra->permite($this->usuario(14), $this->usuario(15), new Tenant()));
    }

    private function regra(bool $primario): AnaliseDaPropriaJustificativa
    {
        $papel    = (new TenantRole())->setIsSystem($primario);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            static fn (User $u, Tenant $t): UserTenant => (new UserTenant($u, $t))->setTenantRole($papel),
        );

        return new AnaliseDaPropriaJustificativa(new RegraDosPrimarios($vinculos));
    }

    /** @param list<string> $papeis */
    private function usuario(int $id, array $papeis = ['ROLE_USER']): User
    {
        $user = (new User())->setRoles($papeis);
        $user->setIsActive(true);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
