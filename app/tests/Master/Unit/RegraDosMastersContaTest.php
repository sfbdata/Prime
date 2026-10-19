<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Conta do Master (spec perfis-1.2-contas C5, Q2 e Q4): a regra centralizada de suspender e excluir. */
#[CoversClass(RegraDosMasters::class)]
final class RegraDosMastersContaTest extends TestCase
{
    #[TestDox('conta criada por Master (primário ou secundário): recusa suspender e excluir, sem consultar vínculo')]
    public function testContaDoMaster(): void
    {
        $contas = $this->createMock(ContasDoSistemaRepository::class);
        $contas->expects($this->never())->method('existeMasterComVinculoAtivo');
        $regra = new RegraDosMasters($contas);

        foreach ([['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], ['ROLE_SUPER_ADMIN']] as $papeis) {
            $conta = (new Tenant())->setCriadoPor((new User())->setRoles($papeis));
            self::assertTrue($regra->ehContaDoMaster($conta));
            self::assertSame(RegraDosMasters::RECUSA_SUSPENDER_CONTA_DO_MASTER, $regra->recusaParaSuspender($conta));
            self::assertSame(RegraDosMasters::RECUSA_EXCLUIR_CONTA_DO_MASTER, $regra->recusaParaExcluir($conta));
        }
    }

    #[TestDox('criador comum (ou só com o papel de primário sem ser Master): sem recusa, mesmo com Master vinculado')]
    public function testContaComum(): void
    {
        $contas = $this->createStub(ContasDoSistemaRepository::class);
        $contas->method('existeMasterComVinculoAtivo')->willReturn(true);
        $regra = new RegraDosMasters($contas);

        foreach ([(new User())->setRoles(['ROLE_USER']), (new User())->setRoles(['ROLE_MASTER_PRIMARIO'])] as $criador) {
            $conta = self::comId((new Tenant())->setCriadoPor($criador));
            self::assertFalse($regra->ehContaDoMaster($conta), 'havendo criador, só ele decide');
            self::assertNull($regra->recusaParaSuspender($conta));
            self::assertNull($regra->recusaParaExcluir($conta));
        }
    }

    #[TestDox('sem criador: é conta do Master só quando algum Master tem vínculo ativo com ela')]
    public function testSemCriadorUsaOVinculo(): void
    {
        foreach ([true, false] as $temMaster) {
            $contas = $this->createMock(ContasDoSistemaRepository::class);
            $contas->method('existeMasterComVinculoAtivo')->with(7)->willReturn($temMaster);
            $regra = new RegraDosMasters($contas);

            self::assertSame($temMaster, $regra->ehContaDoMaster(self::comId(new Tenant())));
        }
    }

    private static function comId(Tenant $conta): Tenant
    {
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($conta, 7);

        return $conta;
    }

    #[TestDox('as mensagens de recusa não têm travessão')]
    public function testSemTravessao(): void
    {
        foreach ([RegraDosMasters::RECUSA_SUSPENDER_CONTA_DO_MASTER, RegraDosMasters::RECUSA_EXCLUIR_CONTA_DO_MASTER] as $mensagem) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $mensagem);
        }
    }
}
