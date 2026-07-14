<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Sync\Entity\TenantDriveConexao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantDriveConexao::class)]
final class TenantDriveConexaoTest extends TestCase
{
    private function novaConexao(): TenantDriveConexao
    {
        return new TenantDriveConexao(new Tenant());
    }

    #[TestDox('recém-criada não está pronta (sem token nem pasta)')]
    public function testNovaNaoEstaPronta(): void
    {
        $conexao = $this->novaConexao();

        self::assertFalse($conexao->isAtivo());
        self::assertFalse($conexao->estaPronta());
        self::assertSame('', $conexao->getRefreshTokenCifrado());
        self::assertNull($conexao->getRootFolderId());
    }

    #[TestDox('registrar credenciais sozinho não ativa — falta a pasta-raiz')]
    public function testCredenciaisSemPastaNaoAtiva(): void
    {
        $conexao = $this->novaConexao();

        $conexao->registrarCredenciais('blob-cifrado', 'dono@escritorio.com', 'drive', new User());

        self::assertSame('blob-cifrado', $conexao->getRefreshTokenCifrado());
        self::assertSame('dono@escritorio.com', $conexao->getContaEmail());
        self::assertFalse($conexao->isAtivo(), 'sem pasta-raiz não pode ativar');
        self::assertFalse($conexao->estaPronta());
    }

    #[TestDox('token + pasta-raiz → conexão ativa e pronta')]
    public function testTokenMaisPastaAtiva(): void
    {
        $conexao = $this->novaConexao();

        $conexao->registrarCredenciais('blob-cifrado', 'dono@escritorio.com', 'drive', new User());
        $conexao->definirRootFolder('1WBY-folder-id');

        self::assertTrue($conexao->isAtivo());
        self::assertTrue($conexao->estaPronta());
        self::assertSame('1WBY-folder-id', $conexao->getRootFolderId());
    }

    #[TestDox('definir pasta sem token não ativa')]
    public function testPastaSemTokenNaoAtiva(): void
    {
        $conexao = $this->novaConexao();

        $conexao->definirRootFolder('1WBY-folder-id');

        self::assertFalse($conexao->isAtivo());
        self::assertFalse($conexao->estaPronta());
    }

    #[TestDox('desconectar limpa token/pasta e desativa, preservando quem conectou')]
    public function testDesconectar(): void
    {
        $conexao = $this->novaConexao();
        $por = new User();
        $conexao->registrarCredenciais('blob-cifrado', 'dono@escritorio.com', 'drive', $por);
        $conexao->definirRootFolder('1WBY-folder-id');

        $conexao->desconectar();

        self::assertFalse($conexao->isAtivo());
        self::assertFalse($conexao->estaPronta());
        self::assertSame('', $conexao->getRefreshTokenCifrado());
        self::assertNull($conexao->getRootFolderId());
        self::assertSame($por, $conexao->getConectadoPor(), 'histórico de quem conectou é preservado');
    }
}
