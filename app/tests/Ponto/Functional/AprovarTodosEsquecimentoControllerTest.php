<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Aprovar todos" do lote de justificativas (admin): o esquecimento de registro grava a batida
 * exatamente como a aprovação individual (spec `fix-aprovar-todos-esquecimento.md`).
 * O empregado alvo tem vínculo em A e B para exercitar o isolamento pelo escritório da URL.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(RegistrarBatidaDoEsquecimentoUseCase::class)]
final class AprovarTodosEsquecimentoControllerTest extends JusPrimeWebTestCase
{
    private const OBSERVACAO = 'Criado por aprovação de justificativa (Esquecimento de Registro)';

    #[TestDox('Aprovar todos grava as batidas dos esquecimentos e abona a justificativa comum sem batida')]
    public function testAprovarTodosGravaAsBatidasDosEsquecimentos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $alvo    = $this->criarUsuario('alvo');
        $this->vincular($alvo, $tenantA);
        $this->vincular($alvo, $tenantB);
        $admin = $this->criarUsuario('admin');
        $this->vincular($admin, $tenantA);

        $lote = 'lote-' . uniqid();
        $entrada = $this->criarJustificativa($alvo, $tenantA, $lote, '2026-03-10', 'esquecimento_registro', 'entrada', '08:07');
        $saida   = $this->criarJustificativa($alvo, $tenantA, $lote, '2026-03-11', 'esquecimento_registro', 'saida', '18:42');
        $comum   = $this->criarJustificativa($alvo, $tenantA, $lote, '2026-03-12', 'atestado_medico', null, null);
        // mesmo lote e mesma pessoa, mas em outro escritório: o aprovar todos de A não pode tocar
        $deB     = $this->criarJustificativa($alvo, $tenantB, $lote, '2026-03-13', 'esquecimento_registro', 'entrada', '09:00');
        $ids = array_map(static fn (JustificativaPonto $j): int => (int) $j->getId(), [$entrada, $saida, $comum, $deB]);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenantA);
        $this->limpar();

        $client->request('POST', "/tenant/{$tenantA->getId()}/user/{$alvo->getId()}/justificativa/batch/{$lote}/aprovar-todos", [
            '_token' => 'TOKEN_justificativa_aprovar_todos_' . $lote,
        ]);
        self::assertResponseStatusCodeSame(302);

        $em = $this->emSemFiltro();
        [$idEntrada, $idSaida, $idComum, $idDeB] = $ids;
        self::assertSame('abonado', $em->find(JustificativaPonto::class, $idEntrada)?->getStatus());
        self::assertSame('abonado', $em->find(JustificativaPonto::class, $idSaida)?->getStatus());
        self::assertSame('abonado', $em->find(JustificativaPonto::class, $idComum)?->getStatus(), 'a justificativa comum segue sendo abonada');
        self::assertSame('pendente', $em->find(JustificativaPonto::class, $idDeB)?->getStatus(), 'pedido de outro escritório não é tocado');

        $batidasA = $this->batidas($alvo, $tenantA);
        self::assertSame([
            ['2026-03-10 08:07:00', 'entrada', self::OBSERVACAO],
            ['2026-03-11 18:42:00', 'saida', self::OBSERVACAO],
        ], $batidasA, 'só os dois esquecimentos viram batida; a justificativa comum não grava nada');
        self::assertSame([], $this->batidas($alvo, $tenantB), 'nenhuma batida gravada no outro escritório');
    }

    #[TestDox('A aprovação individual grava a mesma batida que o aprovar todos')]
    public function testAprovacaoIndividualGravaAMesmaBatida(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $alvo   = $this->criarUsuario('alvo');
        $this->vincular($alvo, $tenant);
        $admin = $this->criarUsuario('admin');
        $this->vincular($admin, $tenant);

        $just = $this->criarJustificativa($alvo, $tenant, 'lote-' . uniqid(), '2026-03-10', 'esquecimento_registro', 'entrada', '08:07');
        $id   = (int) $just->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenant);
        $this->limpar();

        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/justificativa/{$id}/aprovar", [
            '_token' => 'TOKEN_justificativa_aprovar_' . $id,
        ]);
        self::assertResponseStatusCodeSame(302);

        self::assertSame('abonado', $this->emSemFiltro()->find(JustificativaPonto::class, $id)?->getStatus());
        self::assertSame([['2026-03-10 08:07:00', 'entrada', self::OBSERVACAO]], $this->batidas($alvo, $tenant));
    }

    // ----------------------------------------------------------------- helpers

    /** @return list<array{string, string|null, string|null}> */
    private function batidas(User $user, Tenant $tenant): array
    {
        $registros = $this->emSemFiltro()->getRepository(RegistroPonto::class)
            ->findBy(['user' => $user->getId(), 'tenant' => $tenant->getId()], ['dataHora' => 'ASC']);

        return array_map(static fn (RegistroPonto $r): array => [
            (string) $r->getDataHora()?->format('Y-m-d H:i:s'),
            $r->getTipo(),
            $r->getObservacao(),
        ], $registros);
    }

    private function emSemFiltro(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em;
    }

    private function limpar(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant APROVAR TODOS ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(string $prefixo): User
    {
        $container = static::getContainer();
        $em     = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('User ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Vínculo ativo com role isSystem (bypassa o PermissionChecker, como no teste de isolamento do ponto). */
    private function vincular(User $user, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();
    }

    private function criarJustificativa(
        User $user,
        Tenant $tenant,
        string $lote,
        string $data,
        string $tipo,
        ?string $tipoRegistro,
        ?string $hora,
    ): JustificativaPonto {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $justificativa = new JustificativaPonto();
        $justificativa->setUser($user);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime($data));
        $justificativa->setStatus('pendente');
        $justificativa->setBatchId($lote);
        $justificativa->setTipo($tipo);
        $justificativa->setTipoRegistroEsquecido($tipoRegistro);
        $justificativa->setHoraRegistroEsquecido($hora !== null ? new \DateTime('1970-01-01 ' . $hora . ':00') : null);
        $em->persist($justificativa);
        $em->flush();

        return $justificativa;
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
