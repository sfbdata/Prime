<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PastaChecklistEstadoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\AlterarEstadoDoChecklistUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Interruptor "Ativo" do checklist (`pasta_checklist_estado`, DOC-73).
 *
 * Guardas: pasta do escritório (404), CSRF `checklist_pasta_<id>` (403), permissão de EDITAR a
 * pasta (403) e motivo entre os quatro (422). O cross-tenant usa SUPER_ADMIN de propósito — ele
 * passa por `canAccessResource` em qualquer pasta, então o 404 só pode vir da conferência de dono.
 * A permissão por pasta é provada com a pasta IRMÃ: o mesmo usuário, com acesso de edição a uma
 * pasta só, consegue nela e leva 403 na outra do mesmo escritório.
 *
 * Tudo é conferido no BANCO (SQL cru): o EntityManager do teste não relê a entidade.
 */
#[CoversClass(PastaChecklistEstadoController::class)]
#[CoversClass(AlterarEstadoDoChecklistUseCase::class)]
#[Group('pasta')]
final class PastaChecklistEstadoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** @param array<string, string> $campos */
    private function enviar(KernelBrowser $client, Pasta $pasta, array $campos, ?string $token = null): void
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/checklist/estado', [
            '_token' => $token ?? 'TOKEN_checklist_pasta_' . $pasta->getId(),
        ] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /** @return array{desativado_em: ?string, por: ?int, motivo: ?string} */
    private function estadoNoBanco(Pasta $pasta): array
    {
        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT checklist_desativado_em AS desativado_em, checklist_desativado_por_id AS por, checklist_motivo AS motivo FROM pasta WHERE id = :id',
            ['id' => $pasta->getId()],
        );
        self::assertIsArray($linha);

        return [
            'desativado_em' => $linha['desativado_em'] !== null ? (string) $linha['desativado_em'] : null,
            'por'           => $linha['por'] !== null ? (int) $linha['por'] : null,
            'motivo'        => $linha['motivo'] !== null ? (string) $linha['motivo'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Papel comum SEM permissão de papel; acesso de VER e EDITAR concedido só a UMA pasta. */
    private function criarEditorDeUmaPasta(Tenant $tenant, Pasta $pasta): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel sem nada ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $user = new User();
        $user->setEmail('ck_estado_editor_' . uniqid() . '@test.com');
        $user->setFullName('Editor de uma pasta');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);

        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $acesso->setCanEdit(true);
        $em->persist($acesso);
        $em->flush();

        return $user;
    }

    #[TestDox('Desativar com motivo grava quem, quando e o motivo; reativar limpa os três')]
    public function testDesativaEReativa(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->enviar($client, $pasta, ['ativo' => '0', 'motivo' => 'administrativa_consultiva']);
        self::assertResponseIsSuccessful();
        self::assertSame(['sucesso' => true, 'mudou' => true, 'ativo' => false], $this->json($client));

        $estado = $this->estadoNoBanco($pasta);
        self::assertNotNull($estado['desativado_em']);
        self::assertSame($user->getId(), $estado['por']);
        self::assertSame('administrativa_consultiva', $estado['motivo']);

        $this->enviar($client, $pasta, ['ativo' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['sucesso' => true, 'mudou' => true, 'ativo' => true], $this->json($client));
        self::assertSame(['desativado_em' => null, 'por' => null, 'motivo' => null], $this->estadoNoBanco($pasta));
    }

    #[TestDox('Desativar de novo com outro motivo não muda nada (mudou=false) e o motivo original fica')]
    public function testDesativarDeNovoNaoSobrescreve(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->enviar($client, $pasta, ['ativo' => '0', 'motivo' => 'encerrada']);
        self::assertResponseIsSuccessful();
        $this->enviar($client, $pasta, ['ativo' => '0', 'motivo' => 'nao_se_aplica']);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json($client)['mudou']);
        self::assertSame('encerrada', $this->estadoNoBanco($pasta)['motivo']);
    }

    #[TestDox('A desativação entra no audit_log da pasta, pela rota, com os campos do checklist no diff')]
    public function testRegistraNaAuditoria(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->enviar($client, $pasta, ['ativo' => '0', 'motivo' => 'encerrada']);
        self::assertResponseIsSuccessful();

        $linha = $this->em()->getConnection()->fetchAssociative(
            "SELECT action, actor_email, route, changes::text AS changes
               FROM audit_log
              WHERE entity_class = ? AND entity_id = ?
              ORDER BY id DESC LIMIT 1",
            [Pasta::class, (string) $pasta->getId()],
        );

        self::assertNotFalse($linha, 'Desativar precisa deixar linha na auditoria.');
        self::assertSame('update', $linha['action']);
        self::assertSame($user->getEmail(), $linha['actor_email']);
        self::assertSame('pasta_checklist_estado', $linha['route']);
        self::assertStringContainsString('checklistDesativadoEm', (string) $linha['changes']);
        self::assertStringContainsString('checklistMotivo', (string) $linha['changes']);
    }

    #[TestDox('Motivo fora dos quatro (ou ausente) dá 422 e não grava nada')]
    public function testMotivoInvalidoDa422(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        foreach ([['ativo' => '0', 'motivo' => 'porque sim'], ['ativo' => '0'], ['ativo' => '0', 'motivo' => 'Pasta encerrada']] as $campos) {
            $this->enviar($client, $pasta, $campos);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('erro', $this->json($client));
        }

        self::assertNull($this->estadoNoBanco($pasta)['desativado_em']);
    }

    #[TestDox('"ativo" que não é booleano dá 422 e não grava nada')]
    public function testAtivoInvalidoDa422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->enviar($client, $pasta, ['ativo' => 'talvez', 'motivo' => 'encerrada']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->estadoNoBanco($pasta)['desativado_em']);
    }

    #[TestDox('Token CSRF inválido dá 403 e não grava nada')]
    public function testCsrfInvalido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->enviar($client, $pasta, ['ativo' => '0', 'motivo' => 'encerrada'], 'token_invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->estadoNoBanco($pasta)['desativado_em']);
    }

    #[TestDox('Token de OUTRA pasta (checklist_pasta_<outra>) também dá 403')]
    public function testTokenDeOutraPasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outra           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->enviar($client, $pasta, ['ativo' => '0', 'motivo' => 'encerrada'], 'TOKEN_checklist_pasta_' . $outra->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->estadoNoBanco($pasta)['desativado_em']);
    }

    #[TestDox('Pasta de OUTRO escritório dá 404, mesmo para quem passa em qualquer permissão, e não grava nada')]
    public function testOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);
        $this->enviar($client, $pastaB, ['ativo' => '0', 'motivo' => 'encerrada']);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->estadoNoBanco($pastaB)['desativado_em']);
    }

    #[TestDox('Usuário do MESMO escritório que só pode VER a pasta leva 403 e não grava nada')]
    public function testSoVerDa403(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $leitor     = $this->criarUsuarioSemPermissaoDoModulo($tenant); // só resources.pasta.view
        $pasta      = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $leitor, $tenant);
        $this->enviar($client, $pasta, ['ativo' => '0', 'motivo' => 'encerrada']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->estadoNoBanco($pasta)['desativado_em']);
    }

    #[TestDox('Pasta IRMÃ: quem edita só a pasta A desativa nela e leva 403 na pasta B do mesmo escritório')]
    public function testPastaIrma(): void
    {
        $client     = static::createClient();
        $client->disableReboot();
        [, $tenant] = $this->criarAdmin();
        $pastaA     = $this->criarPasta($tenant);
        $pastaB     = $this->criarPasta($tenant);
        $editor     = $this->criarEditorDeUmaPasta($tenant, $pastaA);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $editor, $tenant);

        $this->enviar($client, $pastaB, ['ativo' => '0', 'motivo' => 'encerrada']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->estadoNoBanco($pastaB)['desativado_em']);

        // Recurso irmão: o MESMO usuário, a MESMA requisição, na pasta que ele pode editar.
        $this->enviar($client, $pastaA, ['ativo' => '0', 'motivo' => 'encerrada']);
        self::assertResponseIsSuccessful('na pasta liberada o mesmo pedido passa — o 403 acima foi a permissão por pasta');
        self::assertSame('encerrada', $this->estadoNoBanco($pastaA)['motivo']);
    }

    #[TestDox('Pasta excluída (lápide) é somente-leitura: o POST é recusado e nada é gravado')]
    public function testPastaExcluidaNaoGrava(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->enviar($client, $pasta, ['ativo' => '0', 'motivo' => 'encerrada']);

        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        self::assertNull($this->estadoNoBanco($pasta)['desativado_em']);
    }
}
