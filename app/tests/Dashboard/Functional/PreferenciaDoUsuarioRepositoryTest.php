<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Gravação idempotente (INSERT … ON CONFLICT) e isolamento por (escritório, usuário) no banco de
 * verdade. As contagens são feitas por SQL direto na tabela, sem passar pelo ORM, para não ler a
 * memória do EntityManager.
 */
#[CoversClass(PreferenciaDoUsuarioRepository::class)]
final class PreferenciaDoUsuarioRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PreferenciaDoUsuarioRepository $repo;

    private const CHAVES = ['dashboard.densidade', 'dashboard.animacoes', 'dashboard.setas', 'dashboard.colunas_ocultas'];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant PREF ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('pref_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa Preferencia');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function linhas(Tenant $tenant, User $usuario): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM preferencia_usuario WHERE tenant_id = :t AND user_id = :u',
            ['t' => $tenant->getId(), 'u' => $usuario->getId()],
        );
    }

    #[TestDox('Gravar a mesma chave duas vezes deixa UMA linha, com o último valor, e não lança')]
    public function testGravarEIdempotente(): void
    {
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUser();

        $this->repo->gravar($tenant, $usuario, 'dashboard.densidade', 'confortavel');
        $this->repo->gravar($tenant, $usuario, 'dashboard.densidade', 'confortavel');
        $this->repo->gravar($tenant, $usuario, 'dashboard.densidade', 'compacta');

        self::assertSame(1, $this->linhas($tenant, $usuario));
        self::assertSame(['dashboard.densidade' => 'compacta'], $this->repo->valoresDoUsuario($tenant, $usuario, self::CHAVES));
    }

    #[TestDox('Lista de colunas volta do JSON como lista (não como objeto nem string)')]
    public function testListaVoltaComoLista(): void
    {
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUser();

        $this->repo->gravar($tenant, $usuario, 'dashboard.colunas_ocultas', ['cargo', 'prazos']);

        self::assertSame(
            ['dashboard.colunas_ocultas' => ['cargo', 'prazos']],
            $this->repo->valoresDoUsuario($tenant, $usuario, self::CHAVES),
        );
    }

    #[TestDox('A leitura é do par (escritório, usuário): colega e outro escritório não vazam')]
    public function testLeituraIsoladaPorTenantEUsuario(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->criarUser();
        $colega  = $this->criarUser();

        $this->repo->gravar($tenantA, $eu, 'dashboard.setas', 'desligadas');
        $this->repo->gravar($tenantA, $colega, 'dashboard.densidade', 'confortavel');
        $this->repo->gravar($tenantB, $eu, 'dashboard.animacoes', 'reduzidas');

        self::assertSame(['dashboard.setas' => 'desligadas'], $this->repo->valoresDoUsuario($tenantA, $eu, self::CHAVES));
        self::assertSame(['dashboard.densidade' => 'confortavel'], $this->repo->valoresDoUsuario($tenantA, $colega, self::CHAVES));
        self::assertSame(['dashboard.animacoes' => 'reduzidas'], $this->repo->valoresDoUsuario($tenantB, $eu, self::CHAVES));
        self::assertSame([], $this->repo->valoresDoUsuario($tenantB, $colega, self::CHAVES));
    }

    #[TestDox('A mesma chave pode existir para o mesmo usuário em dois escritórios (o unique inclui o tenant)')]
    public function testMesmaChaveEmDoisEscritorios(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->criarUser();

        $this->repo->gravar($tenantA, $eu, 'dashboard.densidade', 'confortavel');
        $this->repo->gravar($tenantB, $eu, 'dashboard.densidade', 'compacta');

        self::assertSame(['dashboard.densidade' => 'confortavel'], $this->repo->valoresDoUsuario($tenantA, $eu, self::CHAVES));
        self::assertSame(['dashboard.densidade' => 'compacta'], $this->repo->valoresDoUsuario($tenantB, $eu, self::CHAVES));
    }

    #[TestDox('Só as chaves pedidas voltam: chave fora da lista no banco não chega à tela')]
    public function testSoAsChavesPedidas(): void
    {
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUser();

        $this->repo->gravar($tenant, $usuario, 'dashboard.densidade', 'confortavel');
        $this->repo->gravar($tenant, $usuario, 'outra.tela', 'x');

        self::assertSame(['dashboard.densidade' => 'confortavel'], $this->repo->valoresDoUsuario($tenant, $usuario, self::CHAVES));
        self::assertSame([], $this->repo->valoresDoUsuario($tenant, $usuario, []));
    }

    #[TestDox('Apagar do usuário remove só as dele, neste escritório, e só as chaves pedidas')]
    public function testApagarDoUsuarioIsolado(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->criarUser();
        $colega  = $this->criarUser();

        $this->repo->gravar($tenantA, $eu, 'dashboard.densidade', 'confortavel');
        $this->repo->gravar($tenantA, $eu, 'dashboard.setas', 'desligadas');
        $this->repo->gravar($tenantA, $eu, 'outra.tela', 'x');
        $this->repo->gravar($tenantA, $colega, 'dashboard.densidade', 'confortavel');
        $this->repo->gravar($tenantB, $eu, 'dashboard.densidade', 'confortavel');

        self::assertSame(2, $this->repo->apagarDoUsuario($tenantA, $eu, self::CHAVES));

        self::assertSame(1, $this->linhas($tenantA, $eu), 'a chave fora da lista pedida fica');
        self::assertSame(1, $this->linhas($tenantA, $colega));
        self::assertSame(1, $this->linhas($tenantB, $eu));
    }

    #[TestDox('O usuário apagado leva as preferências junto (FK user_id ON DELETE CASCADE)')]
    public function testCascataDoUsuario(): void
    {
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUser();
        $this->repo->gravar($tenant, $usuario, 'dashboard.densidade', 'confortavel');

        $this->em->getConnection()->executeStatement('DELETE FROM "user" WHERE id = :id', ['id' => $usuario->getId()]);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM preferencia_usuario WHERE user_id = :u',
            ['u' => $usuario->getId()],
        ));
    }
}
