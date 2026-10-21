<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cenário funcional do "ocultar aba": escritório com um primário (perfil de sistema), um colaborador
 * comum que VÊ e EDITA a pasta, a pasta (com o processo do Push) e as regras gravadas direto no banco.
 */
trait CriaCenarioDeAbaOcultaTrait
{
    use CriaLinkExternoDoPushTrait;
    use CriaUsuarioComPermissoesTrait;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function criarPrimario(Tenant $tenant, string $nome = 'Paula Primária'): User
    {
        $em = $this->em();
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Primário ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $user = new User();
        $user->setEmail('primario_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    private function criarColaborador(Tenant $tenant, string $nome = 'Ana Colaboradora'): User
    {
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $user->setFullName($nome);
        $this->em()->flush();

        return $user;
    }

    /** @param list<User>|null $usuarios null = toda a equipe */
    private function ocultarAba(Pasta $pasta, AbaDaPasta $aba, User $quem, ?array $usuarios = null): void
    {
        $tenant = $pasta->getTenant();
        self::assertNotNull($tenant);
        $agora = new \DateTimeImmutable();
        $regra = $usuarios === null
            ? AbaOcultaDaPasta::paraTodaAEquipe($tenant, $pasta, $aba, $quem, $agora)
            : AbaOcultaDaPasta::paraAlguns($tenant, $pasta, $aba, array_map(static fn (User $u): int => (int) $u->getId(), $usuarios), $quem, $agora);
        $this->em()->persist($regra);
        $this->em()->flush();
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
