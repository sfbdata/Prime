<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Cenário dos testes funcionais do cadeado: escritório com primário, colaboradores com as permissões de
 * pasta, pasta e cadeado gravados no banco (DAMA desfaz). O CSRF fica fixo em `TOKEN_<id>`.
 */
trait CriaCenarioDoCadeadoFuncionalTrait
{
    private KernelBrowser $client;

    private function prepararCliente(): void
    {
        $this->client = static::createClient();
        // Várias requisições por teste: sem reiniciar o kernel, o armazenamento de CSRF continua valendo.
        $this->client->disableReboot();
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

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Tenant} o primário e o escritório */
    private function criarEscritorio(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório do cadeado ' . uniqid());
        $this->em()->persist($tenant);

        $primario = $this->novoUsuario('Paula Primária');
        $this->vincular($primario, $tenant, true, []);

        return [$primario, $tenant];
    }

    /** @param list<string> $permissoes */
    private function criarColaborador(Tenant $tenant, string $nome = 'Ana Equipe', array $permissoes = ['modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit']): User
    {
        $user = $this->novoUsuario($nome);
        $this->vincular($user, $tenant, false, $permissoes);

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $cliente = 'MARIA DA SILVA'): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('CAD' . strtoupper(substr(uniqid(), -6)));
        $pasta->setNomeCliente($cliente);
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** @param list<array{0: User, 1: NivelDeAcesso}> $grade vazia = "Ocultar para todos" */
    private function trancar(Pasta $pasta, User $por, array $grade = []): CadeadoDaPasta
    {
        $cadeado = new CadeadoDaPasta($pasta->getTenant(), $pasta, $grade === [] ? ModoDoCadeado::Todos : ModoDoCadeado::PorPessoa, $por, new \DateTimeImmutable());
        if ($grade !== []) {
            $cadeado->definirPorPessoa($grade, $por, new \DateTimeImmutable());
        }
        $this->em()->persist($cadeado);
        $this->em()->flush();

        return $cadeado;
    }

    private function entrar(User $user, Tenant $tenant): void
    {
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $permissoes */
    private function vincular(User $user, Tenant $tenant, bool $primario, array $permissoes): void
    {
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName(($primario ? 'Administrador ' : 'Colaborador ') . uniqid());
        $role->setIsSystem($primario);
        $this->em()->persist($role);

        foreach ($permissoes as $codigo) {
            $perm = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $this->em()->persist($perm);
            }
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($role);
            $ligacao->setPermission($perm);
            $this->em()->persist($ligacao);
            $role->getTenantRolePermissions()->add($ligacao);
        }

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em()->persist($vinculo);
        $this->em()->flush();
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user->setEmail('cadeado_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }
}
