<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Controller\AjusteDeDataController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Entity\Feriado;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(AjusteDeDataController::class)]
final class AjusteDeDataControllerTest extends JusPrimeWebTestCase
{
    private const PERIODO = '/agenda/datas?de=2026-11-01&ate=2026-11-30';

    #[TestDox('Acrescentar, renomear, ocultar e restaurar mudam só a Agenda; a tabela feriado do Ponto fica igual a cada passo')]
    public function testAjustesNaoTocamOPonto(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $this->criarFeriadoDoPonto($tenant, 'Finados', '2000-11-02');
        $this->criarFeriadoDoPonto($tenant, 'Padroeira', '2000-11-28');
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);
        $ponto = $this->fotoDoPonto();

        $x = $this->postar($client, '/agenda/datas/acrescentar', ['data' => '2026-11-10', 'titulo' => 'Feriado municipal', 'calendario' => 'fer', 'fonte' => 'Lei municipal 1/2026']);
        self::assertSame($ponto, $this->fotoDoPonto(), 'acrescentar');
        $this->postar($client, '/agenda/datas/renomear', ['id' => 'fer-2026-11-15-proclamacao-da-republica', 'titulo' => 'Proclamação']);
        self::assertSame($ponto, $this->fotoDoPonto(), 'renomear');
        $this->postar($client, '/agenda/datas/ocultar', ['id' => 'fer-2026-11-02-finados']);
        $this->postar($client, '/agenda/datas/ocultar', ['id' => 'esc-2026-11-28']);
        self::assertSame($ponto, $this->fotoDoPonto(), 'ocultar feriado que conta no ponto');

        $datas = $this->datas($client);
        self::assertSame('Feriado municipal', $datas[$x['id']]['titulo'] ?? null);
        self::assertSame('Proclamação', $datas['fer-2026-11-15-proclamacao-da-republica']['titulo']);
        self::assertTrue($datas['fer-2026-11-15-proclamacao-da-republica']['editado']);
        self::assertArrayNotHasKey('fer-2026-11-02-finados', $datas);
        self::assertArrayNotHasKey('esc-2026-11-28', $datas);

        $this->postar($client, '/agenda/datas/restaurar', []);
        self::assertSame($ponto, $this->fotoDoPonto(), 'restaurar');
        $datas = $this->datas($client);
        self::assertArrayHasKey('fer-2026-11-02-finados', $datas);
        self::assertSame('Proclamação da República', $datas['fer-2026-11-15-proclamacao-da-republica']['titulo']);
        self::assertArrayHasKey($x['id'], $datas, 'acrescentada continua depois de restaurar');

        $this->postar($client, '/agenda/datas/ocultar', ['id' => $x['id']]);
        self::assertSame($ponto, $this->fotoDoPonto(), 'excluir acrescentada');
        self::assertArrayNotHasKey($x['id'], $this->datas($client));
    }

    #[TestDox('Sem a permissão de Feriados e Folgas não ajusta (403) e não vê "podeAjustar"; outro escritório não vê os ajustes')]
    public function testPermissaoEIsolamento(): void
    {
        $client = $this->cliente();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $comum = $this->criarUsuario($tenantA, false, ['modules.agenda.view']);
        $gestorA = $this->criarUsuario($tenantA, true);
        $gestorB = $this->criarUsuario($tenantB, true);
        $this->logarComTenant($client, $comum, $tenantA);

        $this->postar($client, '/agenda/datas/ocultar', ['id' => 'fer-2026-11-02-finados']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', self::PERIODO);
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['podeAjustar']);

        $this->logarComTenant($client, $gestorA, $tenantA);
        $this->postar($client, '/agenda/datas/ocultar', ['id' => 'fer-2026-11-02-finados']);
        self::assertResponseIsSuccessful();
        $this->logarComTenant($client, $gestorB, $tenantB);
        self::assertArrayHasKey('fer-2026-11-02-finados', $this->datas($client), 'ajuste de A não vale para B');
    }

    #[TestDox('Escritório B não renomeia nem exclui a data acrescentada de A; temAjustes acende só com renome ou ocultação')]
    public function testAcrescentadaDeOutroEscritorioETemAjustes(): void
    {
        $client = $this->cliente();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarUsuario($tenantA, true);
        $gestorB = $this->criarUsuario($tenantB, true);

        $this->logarComTenant($client, $gestorA, $tenantA);
        $x = $this->postar($client, '/agenda/datas/acrescentar', ['data' => '2026-11-10', 'titulo' => 'Municipal', 'calendario' => 'fer']);
        $client->request('GET', self::PERIODO);
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['temAjustes'], 'só acrescentada não liga "Restaurar"');

        $this->logarComTenant($client, $gestorB, $tenantB);
        $this->postar($client, '/agenda/datas/renomear', ['id' => $x['id'], 'titulo' => 'Invadido']);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, '/agenda/datas/ocultar', ['id' => $x['id']]);
        self::assertResponseStatusCodeSame(422);

        $this->logarComTenant($client, $gestorA, $tenantA);
        self::assertSame('Municipal', $this->datas($client)[$x['id']]['titulo']);
        $this->postar($client, '/agenda/datas/ocultar', ['id' => 'fer-2026-11-02-finados']);
        $client->request('GET', self::PERIODO);
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['temAjustes']);
        $client->request('GET', '/agenda/datas?de=2026-12-01&ate=2026-12-31');
        self::assertArrayNotHasKey($x['id'], array_column(json_decode((string) $client->getResponse()->getContent(), true)['datas'], null, 'id'), 'acrescentada fora do período não vem');
    }

    #[TestDox('Sem CSRF dá 403; com a permissão de configurações mas sem o módulo agenda também dá 403')]
    public function testCsrfESemModulo(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $soConfig = $this->criarUsuario($tenant, false, ['admin.tenant.settings.manage']);
        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('POST', '/agenda/datas/restaurar', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $soConfig, $tenant);
        $this->postar($client, '/agenda/datas/restaurar', []);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Dados inválidos dão 422 com a mensagem')]
    public function testValidacao(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);

        $r = $this->postar($client, '/agenda/datas/acrescentar', ['data' => '2026-02-31', 'titulo' => 'x', 'calendario' => 'fer']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Data inválida.', $r['erro']);
        $this->postar($client, '/agenda/datas/acrescentar', ['data' => '2026-11-10', 'titulo' => 'x', 'calendario' => 'aniv']);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, '/agenda/datas/ocultar', ['id' => 'qualquer coisa']);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, '/agenda/datas/ocultar', ['id' => 'x-9999999999']);
        self::assertResponseStatusCodeSame(422, 'id maior que INT não pode virar 500');
    }

    /** @return list<string> cada linha da tabela feriado (todos os escritórios), para comparar antes e depois */
    private function fotoDoPonto(): array
    {
        return array_map(
            static fn (array $l): string => implode('|', $l),
            $this->em()->getConnection()->fetchAllAssociative('SELECT id, tenant_id, nome, data, recorrente FROM feriado ORDER BY id'),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function datas(KernelBrowser $client): array
    {
        $client->request('GET', self::PERIODO);
        self::assertResponseIsSuccessful();

        return array_column(json_decode((string) $client->getResponse()->getContent(), true)['datas'], null, 'id');
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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

        return $client;
    }

    /**
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    private function postar(KernelBrowser $client, string $url, array $corpo): array
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], (string) json_encode($corpo));

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Tenant AJU ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarFeriadoDoPonto(Tenant $tenant, string $nome, string $data): void
    {
        $f = (new Feriado())->setNome($nome)->setData(new \DateTime($data))->setRecorrente(true);
        $f->setTenant($tenant);
        $this->em()->persist($f);
        $this->em()->flush();
    }

    /** @param list<string> $permissoes */
    private function criarUsuario(Tenant $tenant, bool $gestor, array $permissoes = []): User
    {
        $em = $this->em();
        $user = new User();
        $user->setEmail('aju_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $role = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($gestor);
        $em->persist($role);
        foreach ($permissoes as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]) ?? (new Permission())->setCode($codigo)->setDescription($codigo)->setGroup(explode('.', $codigo)[0]);
            $em->persist($perm);
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }
}
