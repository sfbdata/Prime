<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * D-DOC-RO, fora do prefixo `pasta_`: as duas rotas que recebem o id cru da PRÓPRIA pasta
 * (`#[PastaPorId]`) — marcadores do Expediente e meta criada pela pasta — recebem a mesma recusa
 * das demais na pasta excluída (lápide), sem efeito no banco; na pasta viva funcionam como sempre;
 * e pasta de outro escritório continua respondendo o 404 da action, sem a mensagem da lápide (que
 * confirmaria a pasta alheia).
 *
 * O efeito é conferido por SQL cru (DBAL): uma releitura pelo EM compartilhado poderia mostrar o
 * identity map, não o banco.
 */
#[CoversClass(PastaSomenteLeituraListener::class)]
final class PastaSomenteLeituraPorIdTest extends JusPrimeWebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    // ── expediente_pasta_marcadores ──────────────────────────────────────────

    #[TestDox('marcadores: pasta riscada recusa (403 JSON) e os marcadores não mudam')]
    public function testMarcadoresNaPastaRiscadaRecusa(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $pasta   = $this->criarPasta($tenant, $user, true);
        $antigo  = $this->criarMarcador($tenant, $user, 'Antigo');
        $novo    = $this->criarMarcador($tenant, $user, 'Novo');
        $this->vincular($pasta, $antigo);
        $id      = (int) $pasta->getId();
        $this->em()->clear();

        $client->request('POST', "/expediente/pasta/{$id}/marcadores", [
            '_token'     => $this->csrf('pasta_marcadores_' . $id),
            'marcadores' => [(string) $novo->getId()],
        ], [], self::XHR);

        $this->assertRecusaDaLapide($client);
        self::assertSame([(int) $antigo->getId()], $this->marcadoresDaPasta($id));
    }

    #[TestDox('marcadores: pasta viva continua sincronizando')]
    public function testMarcadoresNaPastaVivaFunciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $pasta  = $this->criarPasta($tenant, $user, false);
        $antigo = $this->criarMarcador($tenant, $user, 'Antigo');
        $novo   = $this->criarMarcador($tenant, $user, 'Novo');
        $this->vincular($pasta, $antigo);
        $id     = (int) $pasta->getId();
        $this->em()->clear();

        $client->request('POST', "/expediente/pasta/{$id}/marcadores", [
            '_token'     => $this->csrf('pasta_marcadores_' . $id),
            'marcadores' => [(string) $novo->getId()],
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertSame([(int) $novo->getId()], $this->marcadoresDaPasta($id));
    }

    #[TestDox('marcadores em pasta riscada de OUTRO escritório: 404 da action, sem a recusa da lápide, nada muda')]
    public function testMarcadoresDeOutroEscritorioNaoVazaALapide(): void
    {
        [$client, , $tenant] = $this->preparar();
        [$donoB, $tenantB]   = $this->criarUsuarioAdmin();
        $pastaB  = $this->criarPasta($tenantB, $donoB, true);
        $marcaB  = $this->criarMarcador($tenantB, $donoB, 'Alheio');
        $this->vincular($pastaB, $marcaB);
        $id      = (int) $pastaB->getId();
        $this->em()->clear();

        $client->request('POST', "/expediente/pasta/{$id}/marcadores", [
            '_token'     => $this->csrf('pasta_marcadores_' . $id),
            'marcadores' => [],
        ], [], self::XHR);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('somente para leitura', (string) $client->getResponse()->getContent());
        self::assertSame([(int) $marcaB->getId()], $this->marcadoresDaPasta($id));
    }

    // ── tarefa_criar_para_pasta ──────────────────────────────────────────────

    #[TestDox('criar meta: pasta riscada recusa (403 JSON) e nenhuma meta nasce')]
    public function testCriarMetaNaPastaRiscadaRecusa(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $id = (int) $this->criarPasta($tenant, $user, true)->getId();
        $this->em()->clear();

        $client->request('POST', "/tarefas/pasta/{$id}/criar", $this->corpoDaMeta($id), [], self::XHR);

        $this->assertRecusaDaLapide($client);
        self::assertSame(0, $this->metasDaPasta($id));
    }

    #[TestDox('criar meta sem JS: pasta riscada devolve para a pasta com o aviso, sem gravar')]
    public function testCriarMetaNaPastaRiscadaRecusaSemXhr(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $id = (int) $this->criarPasta($tenant, $user, true)->getId();
        $this->em()->clear();

        $client->request('POST', "/tarefas/pasta/{$id}/criar", $this->corpoDaMeta($id));

        self::assertResponseRedirects('/pasta/' . $id);
        self::assertSame(0, $this->metasDaPasta($id));
    }

    #[TestDox('criar meta: pasta viva continua criando')]
    public function testCriarMetaNaPastaVivaFunciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $id = (int) $this->criarPasta($tenant, $user, false)->getId();
        $this->em()->clear();

        $client->request('POST', "/tarefas/pasta/{$id}/criar", $this->corpoDaMeta($id), [], self::XHR);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($dados['sucesso'] ?? false);
        self::assertSame(1, $this->metasDaPasta($id));
    }

    #[TestDox('criar meta em pasta riscada de OUTRO escritório: 404 da action, sem a recusa da lápide, nenhuma meta nasce')]
    public function testCriarMetaDeOutroEscritorioNaoVazaALapide(): void
    {
        [$client] = $this->preparar();
        [$donoB, $tenantB] = $this->criarUsuarioAdmin();
        $id = (int) $this->criarPasta($tenantB, $donoB, true)->getId();
        $this->em()->clear();

        $client->request('POST', "/tarefas/pasta/{$id}/criar", $this->corpoDaMeta($id), [], self::XHR);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('somente para leitura', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->metasDaPasta($id));
    }

    // ── apoio ────────────────────────────────────────────────────────────────

    private function assertRecusaDaLapide(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(403);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('erro', $dados['status'] ?? null);
        self::assertStringContainsString('somente para leitura', (string) ($dados['mensagem'] ?? ''));
    }

    /** @return array<string, string> */
    private function corpoDaMeta(int $pastaId): array
    {
        return [
            '_token'    => $this->csrf('tarefa_criar_' . $pastaId),
            'titulo'    => 'Meta da lápide',
            'descricao' => 'Não deveria nascer em pasta excluída',
        ];
    }

    /** @return array{KernelBrowser, User, Tenant} */
    private function preparar(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $user, $tenant];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return list<int> */
    private function marcadoresDaPasta(int $pastaId): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT marcador_id FROM pasta_marcador WHERE pasta_id = :id ORDER BY marcador_id',
            ['id' => $pastaId],
        );

        return array_map('intval', $ids);
    }

    private function metasDaPasta(int $pastaId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM tarefa WHERE pasta_id = :id',
            ['id' => $pastaId],
        );
    }

    /**
     * Usuário com papel de sistema (administrador) no escritório: o módulo Expediente exige
     * acesso, e o `criarParaPasta` exige que o criador da pasta seja do escritório.
     *
     * @return array{User, Tenant}
     */
    private function criarUsuarioAdmin(): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lapide PorId ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('lapide_porid_' . uniqid() . '@test.com');
        $user->setFullName('Admin Lapide');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, User $criador, bool $excluida): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup(($excluida ? 'LAPIDE-' : 'VIVA-') . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);

        if ($excluida) {
            $pasta->marcarExcluida($criador, new \DateTimeImmutable());
        }

        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarMarcador(Tenant $tenant, User $criadoPor, string $nome): Marcador
    {
        $marcador = new Marcador($nome . ' ' . uniqid(), $tenant, $criadoPor);
        $this->em()->persist($marcador);
        $this->em()->flush();

        return $marcador;
    }

    private function vincular(Pasta $pasta, Marcador $marcador): void
    {
        $pasta->addMarcador($marcador);
        $this->em()->flush();
    }

    private function csrf(string $id): string
    {
        return 'TOKEN_' . $id;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
