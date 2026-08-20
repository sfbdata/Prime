<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cobre a spec §6.4: com o hard delete do vínculo, a trilha de auditoria da pessoa removida
 * só continua alcançável pela tela se o filtro "usuário" ainda a listar. A fonte deixa de ser
 * só quem tem vínculo — passa a incluir também quem só restou no audit_log daquele tenant.
 */
#[CoversClass(UserRepository::class)]
final class UserRepositoryAuditFilterOptionsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(UserRepository::class);
    }

    private function criarTenant(string $sufixo = ''): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Audit ' . uniqid() . $sufixo);
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarUser(string $prefixo = 'user'): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('User ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);

        return $user;
    }

    private function criarRegistroAuditoria(?int $actorUserId, ?int $tenantId): AuditLog
    {
        $log = new AuditLog();
        $log->setAction('delete');
        $log->setEntityClass(UserTenant::class);
        $log->setActorUserId($actorUserId);
        $log->setTenantId($tenantId);
        $this->em->persist($log);

        return $log;
    }

    /**
     * @param array<int, array{value: string, label: string}> $opcoes
     */
    private function contemValor(array $opcoes, string $valor): bool
    {
        foreach ($opcoes as $opcao) {
            if ($opcao['value'] === $valor) {
                return true;
            }
        }

        return false;
    }

    #[TestDox('Pessoa removida (vínculo apagado) continua entre as opções do filtro do próprio tenant')]
    public function testPessoaRemovidaContinuaEntreAsOpcoesDoFiltro(): void
    {
        $tenant = $this->criarTenant();
        $removida = $this->criarUser('removida');
        $this->em->flush();

        // O vínculo NUNCA existiu (ou foi hard-deletado) — só o rastro no audit_log restou.
        $this->criarRegistroAuditoria($removida->getId(), $tenant->getId());
        $this->em->flush();

        $opcoes = $this->repo->findAuditFilterOptions((int) $tenant->getId());

        self::assertTrue(
            $this->contemValor($opcoes, (string) $removida->getId()),
            'A pessoa removida deveria continuar entre as opções do filtro de auditoria.'
        );
    }

    #[TestDox('Quem só aparece no audit_log de OUTRO tenant não entra no filtro deste')]
    public function testUsuarioDeOutroTenantNaoEntraNoFiltro(): void
    {
        $tenantA = $this->criarTenant('A');
        $tenantB = $this->criarTenant('B');
        $userB = $this->criarUser('tenantB');
        $this->em->flush();

        $this->criarRegistroAuditoria($userB->getId(), $tenantB->getId());
        $this->em->flush();

        $opcoesA = $this->repo->findAuditFilterOptions((int) $tenantA->getId());

        self::assertFalse(
            $this->contemValor($opcoesA, (string) $userB->getId()),
            'Usuário com auditoria só em outro tenant não pode vazar para o filtro deste.'
        );
    }

    #[TestDox('Quem tem vínculo E múltiplos registros de auditoria aparece uma única vez no filtro')]
    public function testSemDuplicataQuandoTemVinculoEAuditoria(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser('vinculado');
        $this->em->flush();

        // DOIS (ou mais) registros de auditoria para o MESMO usuário/tenant — é o caso comum
        // (qualquer colaborador com mais de uma ação auditada) e é o que gera o fan-out do JOIN
        // que o GROUP BY precisa colapsar. Com só 1 registro, o teste passaria mesmo sem
        // GROUP BY: não existe duplicata nenhuma para o SELECT devolver.
        $ut = new UserTenant($user, $tenant);
        $this->em->persist($ut);
        $this->criarRegistroAuditoria($user->getId(), $tenant->getId());
        $this->criarRegistroAuditoria($user->getId(), $tenant->getId());
        $this->em->flush();

        $opcoes = $this->repo->findAuditFilterOptions((int) $tenant->getId());

        $ocorrencias = array_filter(
            $opcoes,
            static fn (array $opcao): bool => $opcao['value'] === (string) $user->getId()
        );

        self::assertCount(1, $ocorrencias, 'O usuário não pode aparecer duplicado no filtro.');
    }

    #[TestDox('Registro de auditoria com actor_user_id nulo não quebra a consulta nem gera opção vazia')]
    public function testRegistroComActorNuloNaoQuebraNemGeraOpcaoVazia(): void
    {
        $tenant = $this->criarTenant();
        // Usuário REAL, sem vínculo e sem auditoria própria — a única forma de detectar um
        // vazamento causado por junção mal comparada com NULL é ter alguém que PODERIA vazar
        // por engano. Sem este usuário, o teste passaria mesmo que a igualdade NULL casasse
        // com qualquer coisa: não haveria ninguém no banco para aparecer.
        $estranho = $this->criarUser('estranho_a_tudo');
        $this->em->flush();

        // Existe em dev: linhas de audit_log com actor_user_id e tenant_id nulos.
        $this->criarRegistroAuditoria(null, $tenant->getId());
        $this->criarRegistroAuditoria(null, null);
        $this->em->flush();

        $opcoes = $this->repo->findAuditFilterOptions((int) $tenant->getId());

        self::assertSame(
            [],
            $opcoes,
            'Tenant sem vínculo e sem auditoria própria (só registros de actor nulo) deve devolver filtro vazio.'
        );
        self::assertFalse(
            $this->contemValor($opcoes, ''),
            'Registro de auditoria sem actor não pode virar opção vazia no dropdown.'
        );
    }
}
