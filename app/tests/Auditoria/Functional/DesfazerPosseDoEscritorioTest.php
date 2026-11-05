<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Controller\AuditLogController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Service\Audit\AuditLogSubscriber;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Auditoria 3.01: quem tem vínculo em A e B edita, com a sessão em A, a sede de B pela URL de B. O log tem de nascer com
 * o escritório DONO da sede (B), não com o da sessão; e um log antigo, gravado com A para a sede de B, não se desfaz
 * pela trilha de A.
 */
#[CoversClass(AuditLogSubscriber::class)]
#[CoversClass(DesfazerAlteracaoAuditLogUseCase::class)]
#[CoversClass(AuditLogController::class)]
final class DesfazerPosseDoEscritorioTest extends JusPrimeWebTestCase
{
    /** CSRF stateless (token_id submit) confere a mesma origem. */
    private const ORIGEM = ['HTTP_ORIGIN' => 'http://localhost'];
    private const LAT_ANTIGA = '-22.90680000';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Sessão em A, edição da sede de B pela URL de B: o log nasce com o escritório B e não aparece na trilha de A')]
    public function testLogDaSedeDeOutroEscritorioNasceComODono(): void
    {
        $a = $this->escritorio('A');
        $b = $this->escritorio('B');
        $sede = $this->sede($b);
        $pessoa = $this->pessoa();
        $this->admin($pessoa, $a);
        $this->admin($pessoa, $b);
        $this->logarComTenant($this->client, $pessoa, $a);

        $crawler = $this->client->request('GET', "/tenant/{$b->getId()}/sedes");
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="sede[_token]"]')->attr('value');
        $this->client->request('POST', "/tenant/{$b->getId()}/sedes/{$sede->getId()}/edit", ['sede' => [
            'nome' => 'Sede de B alterada',
            'latitude' => '-23.55052000',
            'longitude' => '-43.17290000',
            'raioPermitido' => '100',
            '_token' => $token,
        ]], [], self::ORIGEM);
        self::assertResponseRedirects("/tenant/{$b->getId()}/sedes");

        $this->em->clear();
        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Sede::class, 'entityId' => (string) $sede->getId(), 'action' => 'update',
        ]);
        self::assertCount(1, $logs);
        self::assertSame($b->getId(), $logs[0]->getTenantId(), 'o log é do escritório dono da sede, não do da sessão');

        $this->client->request('GET', '/auditoria');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            "/auditoria/{$logs[0]->getId()}/desfazer",
            (string) $this->client->getResponse()->getContent(),
            'a trilha de A não mostra a alteração da sede de B',
        );
    }

    #[TestDox('Log do escritório A com a sede de B (gravado antes da correção): desfazer recusa e nada muda')]
    public function testDesfazerDeSedeDeOutroEscritorioRecusa(): void
    {
        $a = $this->escritorio('A');
        $b = $this->escritorio('B');
        $sede = $this->sede($b);
        $atacante = $this->pessoa();
        $this->admin($atacante, $a);

        $log = (new AuditLog())
            ->setAction('update')
            ->setEntityClass(Sede::class)
            ->setEntityId((string) $sede->getId())
            ->setChanges(['diff' => ['changes' => ['latitude' => ['from' => '-15.81100086', 'to' => self::LAT_ANTIGA]]], 'context' => []])
            ->setTenantId($a->getId());
        $this->em->persist($log);
        $this->em->flush();
        $logId = (int) $log->getId();
        $antes = $this->logsDaSede($sede);

        $this->fingirOCsrf();
        $this->logarComTenant($this->client, $atacante, $a);
        $this->em->clear();
        $this->client->request('POST', "/auditoria/{$logId}/desfazer", ['_token' => 'TOKEN_audit_desfazer_' . $logId]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('Registro não encontrado.', (string) $this->client->getResponse()->getContent());
        self::assertSame(self::LAT_ANTIGA, $this->em->getConnection()->fetchOne('SELECT latitude FROM sede WHERE id = ?', [$sede->getId()]));
        self::assertSame($antes, $this->logsDaSede($sede), 'nenhum log novo de update para a sede de B');
    }

    private function logsDaSede(Sede $sede): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_class = ? AND entity_id = ? AND action = 'update'",
            [Sede::class, (string) $sede->getId()],
        );
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Posse 3.01 ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function sede(Tenant $tenant): Sede
    {
        $sede = new Sede();
        $sede->setNome('Sede de B');
        $sede->setLatitude(self::LAT_ANTIGA);
        $sede->setLongitude('-43.17290000');
        $sede->setRaioPermitido(100);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($tenant);
        $this->em->persist($sede);
        $this->em->flush();

        return $sede;
    }

    private function pessoa(): User
    {
        $user = new User();
        $user->setEmail('posse_301_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa 3.01');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Vínculo ativo com o perfil de sistema (canAdminister em qualquer módulo). */
    private function admin(User $user, Tenant $tenant): void
    {
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $this->em->persist($role);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();
    }

    /** O token de cada intenção é `TOKEN_<intenção>`. */
    private function fingirOCsrf(): void
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
