<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\RemocaoDaSedeDoPonto;
use App\Ponto\Service\SedesDaCercaDoPonto;
use App\Repository\SedeRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O "Remover" da página "Sedes do ponto" (Ponto E10, decisão do Coordenador de 09/11): a batida gravada é só inserção,
 * então a sede sem batida é apagada e a com batida é encerrada (`removida_em`). A encerrada some da lista e da cerca,
 * não se edita nem se remove de novo, e as batidas antigas continuam apontando para ela.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(RemocaoDaSedeDoPonto::class)]
#[CoversClass(SedeRepository::class)]
final class RemoverSedeComBatidaControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Sede sem batida: o Remover apaga a sede')]
    public function testSedeSemBatidaEApagada(): void
    {
        [$client, $tenant] = $this->entrarComoAdmin();
        $sede = $this->criarSede($tenant, 'Sede Vazia');
        $sedeId = (int) $sede->getId();

        $this->remover($client, $tenant, $sedeId);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/sedes");
        self::assertFalse($this->conexao()->fetchOne('SELECT 1 FROM sede WHERE id = ?', [$sedeId]));
    }

    #[TestDox('Sede com batida: o Remover encerra a sede, a batida fica com ela e a lista não a mostra')]
    public function testSedeComBatidaEEncerrada(): void
    {
        [$client, $tenant, $admin] = $this->entrarComoAdmin();
        $sede = $this->criarSede($tenant, 'Sede Com Historico');
        $sedeId = (int) $sede->getId();
        $batidaId = $this->criarBatida($tenant, $admin, $sede);

        $this->remover($client, $tenant, $sedeId);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/sedes");
        self::assertNotNull($this->conexao()->fetchOne('SELECT removida_em FROM sede WHERE id = ?', [$sedeId]));
        self::assertSame($sedeId, (int) $this->conexao()->fetchOne('SELECT sede_id FROM registro_ponto WHERE id = ?', [$batidaId]));

        $client->followRedirect();
        $pagina = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('As batidas já feitas nesta sede continuam no histórico com o nome dela.', $pagina);
        self::assertStringNotContainsString('Sede Com Historico', $pagina);
    }

    #[TestDox('Sede encerrada: editar e remover de novo dão 404')]
    public function testSedeEncerradaNaoSeEditaNemSeRemove(): void
    {
        [$client, $tenant, $admin] = $this->entrarComoAdmin();
        $sede = $this->criarSede($tenant, 'Sede Encerrada');
        $sedeId = (int) $sede->getId();
        $this->criarBatida($tenant, $admin, $sede);
        $this->remover($client, $tenant, $sedeId);

        $client->request('POST', "/tenant/{$tenant->getId()}/sedes/{$sedeId}/edit", ['sede' => ['nome' => 'Volta']], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);

        $this->remover($client, $tenant, $sedeId);
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->conexao()->fetchOne('SELECT removida_em FROM sede WHERE id = ?', [$sedeId]));
    }

    #[TestDox('Sede encerrada sai da cerca do ponto; a ativa continua')]
    public function testSedeEncerradaSaiDaCerca(): void
    {
        [$client, $tenant, $admin] = $this->entrarComoAdmin();
        $encerrada = $this->criarSede($tenant, 'Sede Fechada');
        $this->criarSede($tenant, 'Sede Aberta');
        $this->criarBatida($tenant, $admin, $encerrada);
        $this->remover($client, $tenant, (int) $encerrada->getId());

        $cerca = static::getContainer()->get(SedesDaCercaDoPonto::class)->doEscritorio($tenant);

        self::assertSame(['Sede Aberta'], array_column($cerca, 'nome'));
    }

    /** @return array{0: KernelBrowser, 1: Tenant, 2: User} */
    private function entrarComoAdmin(): array
    {
        $client = static::createClient();
        // Sem reiniciar o kernel entre requisições: o armazém de CSRF trocado vale para todas.
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant REMOVER SEDE ' . uniqid());
        $em->persist($tenant);

        $admin = new User();
        $admin->setEmail('admin_remover_sede_' . uniqid() . '@test.com');
        $admin->setFullName('Admin Remover Sede');
        $admin->setRoles(['ROLE_USER']);
        $admin->setIsActive(true);
        $admin->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($admin, 'senha123'));
        $em->persist($admin);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($admin, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        $this->logarComTenant($client, $admin, $tenant);

        return [$client, $tenant, $admin];
    }

    private function criarSede(Tenant $tenant, string $nome): Sede
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sede = (new Sede())
            ->setNome($nome)
            ->setLatitude('-22.90680000')
            ->setLongitude('-43.17290000')
            ->setRaioPermitido(100)
            ->setTimezone('America/Sao_Paulo')
            ->setTenant($tenant);
        $em->persist($sede);
        $em->flush();

        return $sede;
    }

    private function criarBatida(Tenant $tenant, User $pessoa, Sede $sede): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $batida = (new RegistroPonto())
            ->setTenant($tenant)
            ->setUser($pessoa)
            ->setSede($sede)
            ->setSedeNomeSnapshot((string) $sede->getNome())
            ->setTipo(RegistroPonto::TIPO_ENTRADA)
            ->setDataHora(new \DateTime('2026-10-21 08:00:00'));
        $em->persist($batida);
        $em->flush();

        return (int) $batida->getId();
    }

    private function remover(KernelBrowser $client, Tenant $tenant, int $sedeId): void
    {
        $client->request('POST', "/tenant/{$tenant->getId()}/sedes/{$sedeId}/delete", ['_token' => 'TOKEN_delete_sede_' . $sedeId]);
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
