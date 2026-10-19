<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Master\Service\RotuloDeAuditoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RotuloDeAuditoria::class)]
final class RotuloDeAuditoriaTest extends TestCase
{
    #[TestDox('Alteração de senha mostra só o nome do campo, nunca o valor')]
    public function testNaoExpoeValor(): void
    {
        $r = RotuloDeAuditoria::traduzir('update', User::class, '{"diff":{"changes":{"password":{"from":"$2y$antigo","to":"$2y$novo"},"updatedAt":{"from":1,"to":2}}}}');

        self::assertSame('Usuário alterado', $r['acao']);
        self::assertSame('Campos alterados: senha', $r['detalhe']);
        self::assertStringNotContainsString('$2y$', $r['acao'] . $r['detalhe']);
    }

    #[TestDox('Situação da conta vira "Conta suspensa" / "Conta reativada"')]
    public function testSuspensao(): void
    {
        self::assertSame('Conta suspensa', RotuloDeAuditoria::traduzir('update', Tenant::class, '{"diff":{"changes":{"isActive":{"from":true,"to":false}}}}')['acao']);
        self::assertSame('Conta reativada', RotuloDeAuditoria::traduzir('update', Tenant::class, '{"diff":{"changes":{"isActive":{"from":false,"to":true}}}}')['acao']);
    }

    #[TestDox('Troca de papel vira "Promovido a Master" / "Removido de Master" (spec perfis-1.2-masters M7)')]
    public function testPromocaoERemocaoDeMaster(): void
    {
        self::assertSame('Promovido a Master', RotuloDeAuditoria::traduzir('update', User::class, '{"diff":{"changes":{"roles":{"from":[],"to":["ROLE_SUPER_ADMIN"]}}}}')['acao']);
        self::assertSame('Removido de Master', RotuloDeAuditoria::traduzir('update', User::class, '{"diff":{"changes":{"roles":{"from":["ROLE_SUPER_ADMIN"],"to":[]}}}}')['acao']);
        self::assertSame('Papel no sistema alterado', RotuloDeAuditoria::traduzir('update', User::class, '{"diff":{"changes":{"roles":{"from":["ROLE_SUPER_ADMIN"],"to":["ROLE_SUPER_ADMIN","ROLE_MASTER_PRIMARIO"]}}}}')['acao']);
    }

    #[TestDox('Rótulos de criação, exclusão, troca de perfil e purga')]
    public function testRotulos(): void
    {
        self::assertSame('Permissão concedida ao perfil', RotuloDeAuditoria::traduzir('create', TenantRolePermission::class, null)['acao']);
        self::assertSame('Usuário removido do escritório', RotuloDeAuditoria::traduzir('delete', UserTenant::class, null)['acao']);
        self::assertSame('Perfil do usuário alterado', RotuloDeAuditoria::traduzir('update', UserTenant::class, '{"diff":{"changes":{"tenantRole":{"from":null,"to":{"id":"3"}}}}}')['acao']);
        self::assertSame('Conta excluída definitivamente', RotuloDeAuditoria::traduzir('purge', Tenant::class, '{"nome":"X","motivo":"quarentena_expirada"}')['acao']);
        self::assertSame('Alteração sem detalhe registrado', RotuloDeAuditoria::traduzir('update', User::class, 'json inválido')['detalhe']);
    }

    #[TestDox('Tela ou função ligada/desligada pelo Master vira "Desativou X" / "Reativou X" (spec perfis-1.2-telas-e-recursos R7)')]
    public function testRecursoDoSistema(): void
    {
        $criado = RotuloDeAuditoria::traduzir('create', RecursoEstado::class, '{"diff":{"after":{"chave":"dashboard","ativo":false,"motivo":"Revisão ética","ate":"2026-10-25T00:00:00+00:00"}}}', 'dashboard');
        self::assertSame(['acao' => 'Desativou Dashboard', 'detalhe' => 'Motivo: Revisão ética · volta em 25/10/2026'], $criado);

        $semMotivo = RotuloDeAuditoria::traduzir('create', RecursoEstado::class, '{"diff":{"after":{"chave":"metas","ativo":false,"motivo":null,"ate":null}}}');
        self::assertSame(['acao' => 'Desativou Minhas Metas', 'detalhe' => 'Sem motivo informado'], $semMotivo, 'sem a chave da tabela, usa a do registro de criação');

        $reativou = RotuloDeAuditoria::traduzir('update', RecursoEstado::class, '{"diff":{"changes":{"ativo":{"from":false,"to":true},"motivo":{"from":"x","to":null}}}}', 'contribua');
        self::assertSame(['acao' => 'Reativou Contribua', 'detalhe' => 'Liberado para todos os usuários'], $reativou);

        $desativou = RotuloDeAuditoria::traduzir('update', RecursoEstado::class, '{"diff":{"changes":{"ativo":{"from":true,"to":false},"motivo":{"from":null,"to":"Correção de falha"}}}}', 'peticionar');
        self::assertSame(['acao' => 'Desativou Peticionar', 'detalhe' => 'Motivo: Correção de falha'], $desativou);
    }
}
