<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Conta do Master (spec perfis-1.2-contas C5, Q2 e Q4): a regra centralizada de suspender e excluir. */
#[CoversClass(RegraDosMasters::class)]
final class RegraDosMastersContaTest extends TestCase
{
    #[TestDox('conta criada por Master (primário ou secundário): recusa suspender e excluir')]
    public function testContaDoMaster(): void
    {
        $regra = new RegraDosMasters();

        foreach ([['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], ['ROLE_SUPER_ADMIN']] as $papeis) {
            $conta = (new Tenant())->setCriadoPor((new User())->setRoles($papeis));
            self::assertTrue($regra->ehContaDoMaster($conta));
            self::assertSame(RegraDosMasters::RECUSA_SUSPENDER_CONTA_DO_MASTER, $regra->recusaParaSuspender($conta));
            self::assertSame(RegraDosMasters::RECUSA_EXCLUIR_CONTA_DO_MASTER, $regra->recusaParaExcluir($conta));
        }
    }

    #[TestDox('conta de usuário comum, sem criador ou de quem só tem o papel de primário sem ser Master: sem recusa')]
    public function testContaComum(): void
    {
        $regra = new RegraDosMasters();

        foreach ([(new User())->setRoles(['ROLE_USER']), (new User())->setRoles(['ROLE_MASTER_PRIMARIO']), null] as $criador) {
            $conta = (new Tenant())->setCriadoPor($criador);
            self::assertFalse($regra->ehContaDoMaster($conta));
            self::assertNull($regra->recusaParaSuspender($conta));
            self::assertNull($regra->recusaParaExcluir($conta));
        }
    }

    #[TestDox('as mensagens de recusa não têm travessão')]
    public function testSemTravessao(): void
    {
        foreach ([RegraDosMasters::RECUSA_SUSPENDER_CONTA_DO_MASTER, RegraDosMasters::RECUSA_EXCLUIR_CONTA_DO_MASTER] as $mensagem) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $mensagem);
        }
    }
}
