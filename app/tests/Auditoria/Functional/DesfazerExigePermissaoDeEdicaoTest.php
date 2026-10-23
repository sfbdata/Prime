<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Controller\AuditLogController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Achado de auditoria interna F19 (spec `docs/specs/seguranca-b-ponto.md` §1.3): desfazer uma alteração de Sede, Cargo,
 * Lotação ou Chamado exige, além de `admin.audit.view`, a permissão da tela que edita a entidade. Sem ela, 403 e nada
 * muda. O desfazer das outras entidades continua só com `admin.audit.view`.
 */
#[CoversClass(AuditLogController::class)]
#[CoversClass(DesfazerAlteracaoAuditLogUseCase::class)]
final class DesfazerExigePermissaoDeEdicaoTest extends JusPrimeWebTestCase
{
    private const SO_AUDITORIA = ['admin.audit.view'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Desfazer F19 ' . uniqid());
        $this->em->persist($this->tenant);
        $this->em->flush();

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

    /** @param list<string> $permissoes */
    private function entrarCom(array $permissoes): void
    {
        $user = new User();
        $user->setEmail('f19_' . uniqid() . '@test.com');
        $user->setFullName('Auditora F19');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $role = new TenantRole();
        $role->setTenant($this->tenant);
        $role->setName('Auditoria ' . uniqid());
        $role->setIsSystem(false);
        $this->em->persist($role);

        foreach ($permissoes as $codigo) {
            $perm = $this->em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $this->em->persist($perm);
            }
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($role);
            $ligacao->setPermission($perm);
            $this->em->persist($ligacao);
            $role->getTenantRolePermissions()->add($ligacao);
        }

        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();

        $this->logarComTenant($this->client, $user, $this->tenant);
    }

    /**
     * Cria a entidade já com o valor "novo" e a linha de auditoria que o leva de volta ao anterior.
     *
     * @return array{0: int, 1: int} [id da entidade, id da linha de auditoria]
     */
    private function cenario(string $classe): array
    {
        if ($classe === Sede::class) {
            $entidade = new Sede();
            $entidade->setNome('Sede F19');
            $entidade->setLatitude('-10.00000000');
            $entidade->setLongitude('-48.06479935');
            $entidade->setRaioPermitido(100);
            $entidade->setTimezone('America/Sao_Paulo');
            $diff = ['latitude' => ['from' => '-15.81100086', 'to' => '-10.00000000']];
        } elseif ($classe === Chamado::class) {
            $entidade = new Chamado();
            $entidade->setTitulo('Titulo novo');
            $entidade->setDescricao('Descrição do chamado');
            $entidade->setSolicitante($this->solicitante());
            $diff = ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Titulo novo']];
        } else {
            $entidade = $classe === Cargo::class ? new Cargo() : new Lotacao();
            $entidade->setNome('Nome novo');
            $diff = ['nome' => ['from' => 'Nome anterior', 'to' => 'Nome novo']];
        }
        $entidade->setTenant($this->tenant);
        $this->em->persist($entidade);
        $this->em->flush();

        return [(int) $entidade->getId(), $this->criarLog($classe, (int) $entidade->getId(), $diff)];
    }

    private function solicitante(): User
    {
        $user = new User();
        $user->setEmail('f19_solicitante_' . uniqid() . '@test.com');
        $user->setFullName('Solicitante F19');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist(new UserTenant($user, $this->tenant));

        return $user;
    }

    /** @param array<string, array{from: mixed, to: mixed}> $diff */
    private function criarLog(string $classe, int $entityId, array $diff): int
    {
        $log = (new AuditLog())
            ->setAction('update')
            ->setEntityClass($classe)
            ->setEntityId((string) $entityId)
            ->setChanges(['diff' => ['changes' => $diff], 'context' => []])
            ->setTenantId($this->tenant->getId());
        $this->em->persist($log);
        $this->em->flush();

        return (int) $log->getId();
    }

    private function desfazer(int $logId): void
    {
        $this->em->clear();
        $this->client->request('POST', "/auditoria/{$logId}/desfazer", ['_token' => 'TOKEN_audit_desfazer_' . $logId]);
    }

    private function valorAtual(string $classe, int $id): string
    {
        [$tabela, $coluna] = match ($classe) {
            Sede::class  => ['sede', 'latitude'],
            Chamado::class => ['chamado', 'titulo'],
            Cargo::class => ['cargo', 'nome'],
            default      => ['lotacao', 'nome'],
        };

        return (string) $this->em->getConnection()->fetchOne("SELECT {$coluna} FROM {$tabela} WHERE id = ?", [$id]);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function entidades(): iterable
    {
        yield 'Sede' => [Sede::class, 'admin.ponto.manage', '-10.00000000', '-15.81100086'];
        yield 'Cargo' => [Cargo::class, 'admin.tenant.settings.manage', 'Nome novo', 'Nome anterior'];
        yield 'Lotação' => [Lotacao::class, 'admin.tenant.settings.manage', 'Nome novo', 'Nome anterior'];
        yield 'Chamado' => [Chamado::class, 'admin.servicedesk.manage', 'Titulo novo', 'Titulo anterior'];
    }

    #[DataProvider('entidades')]
    #[TestDox('$classe: só com admin.audit.view o desfazer dá 403 e nada muda')]
    public function testSoAuditoriaRecebe403(string $classe, string $permissao, string $atual, string $anterior): void
    {
        [$id, $logId] = $this->cenario($classe);
        $this->entrarCom(self::SO_AUDITORIA);

        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($atual, $this->valorAtual($classe, $id), 'nada pode mudar sem a permissão de edição');
    }

    #[DataProvider('entidades')]
    #[TestDox('$classe: com a permissão da tela de edição, o desfazer funciona')]
    public function testComPermissaoDeEdicaoDesfaz(string $classe, string $permissao, string $atual, string $anterior): void
    {
        [$id, $logId] = $this->cenario($classe);
        $this->entrarCom([...self::SO_AUDITORIA, $permissao]);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame($anterior, $this->valorAtual($classe, $id));
    }

    #[TestDox('A permissão de outra tela não serve: Cargo com admin.ponto.manage dá 403')]
    public function testPermissaoDeOutraTelaNaoServe(): void
    {
        [$id, $logId] = $this->cenario(Cargo::class);
        $this->entrarCom([...self::SO_AUDITORIA, 'admin.ponto.manage']);

        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Nome novo', $this->valorAtual(Cargo::class, $id));
    }

    #[TestDox('Outras entidades continuam como antes: a pasta se desfaz só com admin.audit.view')]
    public function testOutraEntidadeSoComAuditoria(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-F19-' . uniqid());
        $pasta->setTenant($this->tenant);
        $pasta->setPrioridade(PrioridadePasta::Normal);
        $pasta->setNomeAcao('acao atual');
        $this->em->persist($pasta);
        $this->em->flush();
        $id = (int) $pasta->getId();
        $logId = $this->criarLog(Pasta::class, $id, ['nomeAcao' => ['from' => 'acao anterior', 'to' => 'acao atual']]);
        $this->entrarCom(self::SO_AUDITORIA);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        // O setter da pasta grava o nome da ação em maiúsculas.
        self::assertSame('ACAO ANTERIOR', $this->em->getConnection()->fetchOne('SELECT nome_acao FROM pasta WHERE id = ?', [$id]));
    }

    #[TestDox('O botão desfazer só aparece na linha que a pessoa pode desfazer')]
    public function testBotaoSoParaQuemPode(): void
    {
        [, $logSede] = $this->cenario(Sede::class);
        [, $logCargo] = $this->cenario(Cargo::class);
        $this->entrarCom([...self::SO_AUDITORIA, 'admin.tenant.settings.manage']);

        $this->client->request('GET', '/auditoria');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("/auditoria/{$logCargo}/desfazer", $html, 'controle: a linha do cargo tem o botão');
        self::assertStringNotContainsString("/auditoria/{$logSede}/desfazer", $html, 'sem admin.ponto.manage, a linha da sede não tem o botão');
    }
}
