<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\UseCase\EnviarRelatorioSemProcessoUseCase;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Escritório para o relatório de pastas sem processo: perfis (primário e secundário com as
 * permissões pedidas), pessoas (com ou sem Controladoria), pastas com responsável, registros e
 * processo vinculado, acesso item a item e a leitura dos avisos gravados.
 */
trait CriaEscritorioSemProcessoTrait
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function novoEscritorio(string $nome = 'Escritório SP'): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $permissoes códigos (`modules.expediente.view`, `resources.pasta.edit`…) */
    private function novoPerfil(Tenant $tenant, bool $sistema, array $permissoes = []): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($tenant);
        $perfil->setName(($sistema ? 'Administrador do Escritório' : 'Secundário') . ' ' . uniqid());
        $perfil->setIsSystem($sistema);
        $this->em()->persist($perfil);

        foreach ($permissoes as $codigo) {
            $perm = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $this->em()->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($perfil);
            $vinculo->setPermission($perm);
            $this->em()->persist($vinculo);
            $perfil->getTenantRolePermissions()->add($vinculo);
        }
        $this->em()->flush();

        return $perfil;
    }

    private function novaPessoa(Tenant $tenant, string $nome, ?TenantRole $perfil, bool $controladoria = false, bool $vinculoAtivo = true): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(uniqid('sp_') . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($perfil);
        $vinculo->setControladoria($controladoria);
        if (!$vinculoAtivo) {
            (function (): void {
                $this->isActive = false;
            })->call($vinculo);
        }
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $user;
    }

    private function novaPasta(Tenant $tenant, string $nup, ?User $responsavel = null, bool $administrativa = false): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $pasta->setNomeCliente('Cliente ' . $nup);
        $pasta->setResponsavel($responsavel);
        $pasta->setAdministrativa($administrativa);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function registrar(Pasta $pasta, User $autor, string $texto): void
    {
        $mensagem = new PastaMensagem();
        $mensagem->setPasta($pasta);
        $mensagem->setAutor($autor);
        $mensagem->setTenant($pasta->getTenant());
        $mensagem->setConteudo('<p>' . $texto . '</p>');
        $this->em()->persist($mensagem);
        $this->em()->flush();
    }

    private function vincularProcessoNovo(Pasta $pasta, string $numero): void
    {
        $processo = new Processo();
        $processo->setTenant($pasta->getTenant());
        $processo->setNumeroProcesso($numero);
        $this->em()->persist($processo);
        $pasta->vincularProcesso($processo);
        $this->em()->flush();
    }

    private function darAcessoAPasta(User $user, Pasta $pasta): void
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($pasta->getTenant());
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    /**
     * Os avisos do relatório gravados no escritório, por destinatário (id => mensagens).
     *
     * @return array<int, list<Notificacao>>
     */
    private function avisosDoRelatorio(Tenant $tenant): array
    {
        $this->em()->clear();
        $avisos = $this->em()->getRepository(Notificacao::class)->findBy(
            ['tenant' => $tenant->getId(), 'tipo' => EnviarRelatorioSemProcessoUseCase::TIPO_NOTIFICACAO],
            ['id' => 'ASC'],
        );

        $porPessoa = [];
        foreach ($avisos as $aviso) {
            $porPessoa[(int) $aviso->getUsuario()?->getId()][] = $aviso;
        }

        return $porPessoa;
    }

    /** Token CSRF previsível: "TOKEN_<id>" (vale no container da próxima requisição). */
    private function csrfPrevisivel(): void
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
