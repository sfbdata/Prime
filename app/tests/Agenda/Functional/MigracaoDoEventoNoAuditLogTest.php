<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Entity\Evento;
use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261020122000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// A pasta migrations/ não entra no autoload do Composer.
require_once dirname(__DIR__, 3) . '/migrations/Version20261020122000.php';

/**
 * O Evento saiu de App\Entity\Agenda para App\Agenda\Entity. A migration de dados troca o nome da
 * classe no audit_log, e o histórico antigo continua no filtro da auditoria e no "Desfazer".
 */
#[CoversClass(Version20261020122000::class)]
final class MigracaoDoEventoNoAuditLogTest extends KernelTestCase
{
    private const ANTIGA = 'App\Entity\Agenda\Evento';

    #[TestDox('Depois da migração, o log antigo do Evento aparece no filtro e o Desfazer reverte a alteração')]
    public function testLogAntigoVoltaAoFiltroEAoDesfazer(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$tenant, $evento] = $this->criarEvento($em, 'Título novo');
        $logId = $this->criarLogAntigo($em, $tenant, $evento, 'Título antigo', 'Título novo');

        $this->rodar('up');
        $em->clear();

        $log = $em->find(AuditLog::class, $logId);
        self::assertSame(Evento::class, $log->getEntityClass());
        $repo = static::getContainer()->get(AuditLogRepository::class);
        $ids = array_map(static fn (AuditLog $l): ?int => $l->getId(), $repo->findByFilters(Evento::class, null, null, null, $tenant->getId()));
        self::assertContains($logId, $ids);
        self::assertContains(Evento::class, $repo->findDistinctEntityClasses($tenant->getId()));

        $desfazer = static::getContainer()->get(DesfazerAlteracaoAuditLogUseCase::class);
        self::assertTrue($desfazer->podeReverter($log));
        self::assertTrue($desfazer->executar($logId, (int) $tenant->getId())->sucesso);
        $em->clear();
        self::assertSame('Título antigo', $em->find(Evento::class, $evento->getId())->getTitulo());
    }

    #[TestDox('O down devolve o nome antigo da classe')]
    public function testDownDevolveONomeAntigo(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$tenant, $evento] = $this->criarEvento($em, 'Qualquer');
        $logId = $this->criarLogAntigo($em, $tenant, $evento, 'a', 'b');

        $this->rodar('up');
        $this->rodar('down');
        $em->clear();

        self::assertSame(self::ANTIGA, $em->find(AuditLog::class, $logId)->getEntityClass());
    }

    private function rodar(string $direcao): void
    {
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        /** @var AbstractMigration $migration */
        $migration = new Version20261020122000($conexao, new NullLogger());
        $migration->{$direcao}(new Schema());
        foreach ($migration->getSql() as $query) {
            $conexao->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    /** @return array{Tenant, Evento} */
    private function criarEvento(EntityManagerInterface $em, string $titulo): array
    {
        $tenant = (new Tenant())->setName('Tenant MIG ' . uniqid());
        $em->persist($tenant);
        $user = new User();
        $user->setEmail('mig_' . uniqid() . '@test.com');
        $user->setFullName('Usuário MIG');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $evento = new Evento();
        $evento->setTitulo($titulo);
        $evento->setDataInicio(new \DateTimeImmutable('2030-03-10 09:00'));
        $evento->setDataFim(new \DateTimeImmutable('2030-03-10 10:00'));
        $evento->setCriador($user);
        $evento->setTenant($tenant);
        $em->persist($evento);
        $em->flush();

        return [$tenant, $evento];
    }

    private function criarLogAntigo(EntityManagerInterface $em, Tenant $tenant, Evento $evento, string $de, string $para): int
    {
        $log = (new AuditLog())
            ->setAction('update')
            ->setEntityClass(self::ANTIGA)
            ->setEntityId((string) $evento->getId())
            ->setChanges(['diff' => ['changes' => ['titulo' => ['from' => $de, 'to' => $para]]], 'context' => []])
            ->setTenantId($tenant->getId());
        $em->persist($log);
        $em->flush();

        return (int) $log->getId();
    }
}
