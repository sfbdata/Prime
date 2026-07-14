<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Entity\Tenant\Tenant;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Repository\TenantDriveConexaoRepository;
use App\Sync\Service\CifradorDeSegredo;
use App\Sync\Service\GoogleDriveClientFactory;
use App\Sync\Service\GoogleDriveClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(GoogleDriveClientFactory::class)]
final class GoogleDriveClientFactoryTest extends TestCase
{
    private function cifrador(): CifradorDeSegredo
    {
        return new CifradorDeSegredo(base64_encode(str_repeat("\x07", 32)));
    }

    #[TestDox('tenant conectado → devolve client autenticado e a pasta-raiz do tenant')]
    public function testParaTenantConectado(): void
    {
        $cifrador = $this->cifrador();

        $conexao = new TenantDriveConexao(new Tenant());
        $conexao->registrarCredenciais($cifrador->cifrar('refresh-real'), 'dono@x.com', 'drive', new \App\Entity\Auth\User());
        $conexao->definirRootFolder('root-do-tenant');

        $repo = $this->createMock(TenantDriveConexaoRepository::class);
        $repo->method('findAtivaDoTenant')->willReturn($conexao);

        $factory = new GoogleDriveClientFactory($repo, $cifrador, 'client-id', 'client-secret');

        $drive = $factory->paraTenant(new Tenant());

        self::assertInstanceOf(GoogleDriveClientInterface::class, $drive->client);
        self::assertSame('root-do-tenant', $drive->rootFolderId);
    }

    #[TestDox('tenant sem conexão ativa → TenantSemDriveException')]
    public function testParaTenantSemConexaoLanca(): void
    {
        $repo = $this->createMock(TenantDriveConexaoRepository::class);
        $repo->method('findAtivaDoTenant')->willReturn(null);

        $factory = new GoogleDriveClientFactory($repo, $this->cifrador(), 'client-id', 'client-secret');

        $this->expectException(TenantSemDriveException::class);
        $factory->paraTenant(new Tenant());
    }
}
