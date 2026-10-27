<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Fixtures dos testes funcionais do Trabalho Externo, criadas à mão pelo EntityManager (mesmo
 * padrão do Djen). Os usuários comuns têm papel NÃO-sistema com exatamente a permissão pedida:
 * o bypass de administrador não esconde furo de permissão.
 */
trait CriaFixturesTrabalhoExternoTrait
{
    private const PERMISSAO = 'modules.trabalho_externo.view';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(string $nome = 'Escritório'): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $permissoes */
    private function criarUsuario(Tenant $tenant, array $permissoes = [self::PERMISSAO], array $roles = ['ROLE_USER'], string $nome = 'Usuário'): User
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        // O EntityManager é trocado entre requisições: o escritório tem de vir do EM atual.
        $tenant = $em->contains($tenant) ? $tenant : $em->find(Tenant::class, $tenant->getId());

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach ($permissoes as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup('modules');
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        $user = new User();
        $user->setEmail('te_' . uniqid() . '@test.com');
        $user->setFullName($nome . ' ' . uniqid());
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    /** O master global (D1): super admin, com vínculo comum no escritório e SEM a permissão do módulo. */
    private function criarMaster(Tenant $tenant): User
    {
        return $this->criarUsuario($tenant, [], ['ROLE_USER', 'ROLE_SUPER_ADMIN'], 'Master');
    }

    /** O termo de voluntariado vem desligado: os testes que o usam ligam (ou desligam) como o Master. */
    private function ligarTermo(bool $ligado = true): void
    {
        $repo = static::getContainer()->get(ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        $cfg->definirTermoLigado($ligado);
        $repo->salvar($cfg, flush: true);
    }

    private function definirSede(?Tenant $sede): void
    {
        $repo = static::getContainer()->get(ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        // O EntityManager é trocado entre requisições: a sede tem de vir do EM atual.
        $cfg->definirSede($sede !== null ? $this->em()->find(Tenant::class, $sede->getId()) : null);
        $repo->salvar($cfg, flush: true);
    }

    /**
     * Token CSRF previsível ('TOKEN_ajax'), como em PreferenciaDoDashboardControllerTest. Sem
     * reboot do kernel entre requisições (senão o serviço trocado se perde), como em
     * PastaPagamentoControllerTest.
     */
    private function instalarCsrf(KernelBrowser $client): void
    {
        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
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
        });
    }

    /** @param array<string, mixed> $corpo */
    private function postarJson(KernelBrowser $client, string $url, array $corpo = [], string $token = 'TOKEN_ajax'): array
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode($corpo, JSON_THROW_ON_ERROR));

        return $this->json($client);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        return is_array($dados) ? $dados : [];
    }

    /** @return array<string, mixed> */
    private static function solicitacaoValida(array $extra = []): array
    {
        return $extra + [
            'escritorio' => 'Escritório Solicitante', 'email' => 'contato@solicitante.test', 'telefone' => '(61) 99999-0000',
            'ufOrigem' => 'AL', 'cidadeOrigem' => 'Maceió', 'advogado' => 'Dra. Teste', 'oab' => '12345', 'oabUf' => 'AL',
            'ufExecucao' => 'DF', 'cidadeExecucao' => 'Brasília', 'orgao' => 'TJDFT', 'processo' => '0000000-00.2026.8.07.0001',
            'tipoServico' => 'Audiência', 'prioridade' => 'Normal', 'prazoFinal' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'descricao' => 'Comparecer à audiência de conciliação.',
        ];
    }

    /**
     * Envia (ou salva como rascunho) uma solicitação pelo endpoint real e devolve o código.
     *
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $arquivos
     */
    private function solicitar(KernelBrowser $client, array $dados, bool $enviar = true, array $arquivos = [], string $rascunho = ''): array
    {
        $client->request(
            'POST',
            '/trabalho-externo/api/solicitacoes',
            ['dados' => json_encode($dados, JSON_THROW_ON_ERROR), 'enviar' => $enviar ? '1' : '0', 'rascunho' => $rascunho],
            $arquivos,
            ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
        );

        return $this->json($client);
    }
}
