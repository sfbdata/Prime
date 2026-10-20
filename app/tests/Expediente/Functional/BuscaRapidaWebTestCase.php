<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures da Busca rápida (Ctrl + K): escritório, pessoa com papel de sistema ou com permissões exatas,
 * pasta, acesso próprio a uma pasta e o cadeado da pasta (spec pasta-cadeado), como os testes do #120.
 */
abstract class BuscaRapidaWebTestCase extends JusPrimeWebTestCase
{
    private int $seq = 0;

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Busca Rapida ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** Administrador do escritório (primário): papel de sistema, vê tudo, inclusive pasta com cadeado. */
    protected function criarAdministrador(Tenant $tenant): User
    {
        $role = $this->criarPapel($tenant, true);

        return $this->criarUsuario($tenant, $role);
    }

    /**
     * Papel comum (sem bypass) com exatamente estas permissões.
     *
     * @param list<string> $codigos
     */
    protected function criarUsuarioComPermissoes(Tenant $tenant, array $codigos): User
    {
        $em   = $this->em();
        $role = $this->criarPapel($tenant, false);
        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }

            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        return $this->criarUsuario($tenant, $role);
    }

    protected function criarPasta(Tenant $tenant, ?string $nomeAcao = null, ?string $nomeCliente = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup(self::nupSemDigitos(++$this->seq));
        $pasta->setTenant($tenant);
        $pasta->setNomeAcao($nomeAcao);
        $pasta->setNomeCliente($nomeCliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /**
     * NUP único e SEM DÍGITOS. A busca casa os dígitos do termo (3 ou mais) com CPF e número de processo; um NUP
     * com dígitos aleatórios (o `uniqid` de antes) às vezes casava com o CPF ou o processo do cenário e trazia
     * uma ocorrência a mais (teste instável na suíte paralela).
     */
    private static function nupSemDigitos(int $seq): string
    {
        $letras = '';
        foreach (str_split(str_pad((string) $seq, 3, '0', STR_PAD_LEFT) . bin2hex(random_bytes(5))) as $c) {
            $letras .= chr(ord('A') + hexdec($c));
        }

        return 'BRF' . $letras;
    }

    protected function darAcessoProprio(User $user, Tenant $tenant, Pasta $pasta, bool $podeVer = true): void
    {
        $acesso = (new ResourceAccess())
            ->setUser($user)
            ->setTenant($tenant)
            ->setResourceType('pasta')
            ->setResourceId((int) $pasta->getId())
            ->setCanView($podeVer);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    /**
     * Cadeado gravado no banco, como o `CriaCenarioDoCadeadoFuncionalTrait` do #120.
     *
     * @param list<array{0: User, 1: NivelDeAcesso}> $grade vazia = "Ocultar para todos"
     */
    protected function trancar(Pasta $pasta, User $por, array $grade = []): CadeadoDaPasta
    {
        $cadeado = new CadeadoDaPasta($pasta->getTenant(), $pasta, $grade === [] ? ModoDoCadeado::Todos : ModoDoCadeado::PorPessoa, $por, new \DateTimeImmutable());
        if ($grade !== []) {
            $cadeado->definirPorPessoa($grade, $por, new \DateTimeImmutable());
        }
        $this->em()->persist($cadeado);
        $this->em()->flush();

        return $cadeado;
    }

    private function criarPapel(Tenant $tenant, bool $sistema): TenantRole
    {
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel Busca Rapida ' . uniqid());
        $role->setIsSystem($sistema);
        $this->em()->persist($role);

        return $role;
    }

    private function criarUsuario(Tenant $tenant, TenantRole $role): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('busca_rapida_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa Busca Rapida');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }
}
