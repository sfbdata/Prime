<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Permission\Permission;
use App\Tenant\Service\GruposDePermissaoDoPerfil;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(GruposDePermissaoDoPerfil::class)]
final class GruposDePermissaoDoPerfilTest extends TestCase
{
    private function permissao(string $codigo, string $grupo, string $descricao = 'desc'): Permission
    {
        $p = new Permission();
        $p->setCode($codigo);
        $p->setGroup($grupo);
        $p->setDescription($descricao);

        return $p;
    }

    #[TestDox('usa os cartões e rótulos do desenho, na ordem do desenho')]
    public function testCartoesDoDesenho(): void
    {
        $cartoes = (new GruposDePermissaoDoPerfil())->agrupar([
            'resources' => [$this->permissao('resources.pasta.delete', 'resources'), $this->permissao('resources.pasta.view', 'resources')],
            'admin'     => [$this->permissao('admin.users.invite', 'admin')],
        ]);

        self::assertSame(['Administração do escritório', 'Recursos · Pasta'], array_column($cartoes, 'rotulo'));
        self::assertSame('Convidar usuários secundários', $cartoes[0]['itens'][0]['rotulo']);
        self::assertSame(['Visualizar', 'Excluir'], array_column($cartoes[1]['itens'], 'rotulo'));
    }

    #[TestDox('código que o desenho não conhece não some: cai no cartão do grupo, com a descrição do banco')]
    public function testCodigoDesconhecidoNaoSome(): void
    {
        $servico = new GruposDePermissaoDoPerfil();
        $cartoes = $servico->agrupar([
            'resources' => [$this->permissao('resources.novo.coisa', 'resources', 'Nova coisa')],
            'modules'   => [$this->permissao('modules.novo.view', 'modules', 'Novo módulo')],
        ]);

        self::assertSame(['Módulos', 'Outros recursos'], array_column($cartoes, 'rotulo'));
        self::assertSame('Nova coisa', $cartoes[1]['itens'][0]['rotulo']);
        self::assertSame(2, $servico->total($cartoes));
    }
}
