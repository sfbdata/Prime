<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cobre o SQL (subqueries + isolamento) que puxa eventos de Metas (Tarefa /
 * TarefaMensagem) para a timeline da pasta. Usa um sentinela em actor_email
 * para separar as linhas inseridas pelo teste de qualquer auditoria automática.
 */
#[CoversClass(AuditLogRepository::class)]
final class AuditLogRepositoryMetasTimelineTest extends KernelTestCase
{
    private const TAREFA   = 'App\\Entity\\Tarefa\\Tarefa';
    private const MENSAGEM = 'App\\Entity\\Tarefa\\TarefaMensagem';

    private EntityManagerInterface $em;
    private Connection $conn;
    private AuditLogRepository $repo;
    private string $sentinela;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em        = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn      = $this->em->getConnection();
        $this->repo      = static::getContainer()->get(AuditLogRepository::class);
        $this->sentinela = 'meta_' . uniqid() . '@test.com';
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant META ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('META-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);

        return $pasta;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('meta_user_' . uniqid() . '@test.com');
        $user->setFullName('User META Test');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);

        return $user;
    }

    private function criarTarefa(Pasta $pasta): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta ' . uniqid());
        $tarefa->setDescricao('Descrição da meta');
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($pasta->getTenant());
        $this->em->persist($tarefa);

        return $tarefa;
    }

    private function inserirAudit(string $entityClass, string $entityId, int $tenantId, string $createdAt): void
    {
        $this->conn->insert('audit_log', [
            'action'       => 'create',
            'entity_class' => $entityClass,
            'entity_id'    => $entityId,
            'changes'      => null,
            'actor_email'  => $this->sentinela,
            'tenant_id'    => $tenantId,
            'created_at'   => $createdAt,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string> entity_id das linhas do sentinela para a classe dada
     */
    private function idsDoSentinela(array $rows, string $entityClass): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row['actor_email'] === $this->sentinela && $row['entity_class'] === $entityClass) {
                $ids[] = (string) $row['entity_id'];
            }
        }

        return $ids;
    }

    #[TestDox('Timeline da pasta puxa eventos de Tarefa e TarefaMensagem da própria pasta e não de outra')]
    public function testPuxaMetasDaPastaEIsolaOutraPasta(): void
    {
        $tenant   = $this->criarTenant();
        $pastaA   = $this->criarPasta($tenant);
        $pastaB   = $this->criarPasta($tenant);
        $usuario  = $this->criarUser();
        $tarefaA  = $this->criarTarefa($pastaA);
        $tarefaB  = $this->criarTarefa($pastaB);

        $mensagemA = new TarefaMensagem();
        $mensagemA->setUsuario($usuario);
        $mensagemA->setMensagem('Observação na meta A');
        $mensagemA->setTenant($tenant);
        $tarefaA->addMensagem($mensagemA);
        $this->em->persist($mensagemA);
        $this->em->flush();

        $tenantId = (int) $tenant->getId();
        $this->inserirAudit(self::TAREFA, (string) $tarefaA->getId(), $tenantId, '2026-06-17 10:00:00');
        $this->inserirAudit(self::MENSAGEM, (string) $mensagemA->getId(), $tenantId, '2026-06-17 10:05:00');
        $this->inserirAudit(self::TAREFA, (string) $tarefaB->getId(), $tenantId, '2026-06-17 10:10:00');

        $rows = $this->repo->findForPastaTimeline((int) $pastaA->getId(), $tenantId, null);

        $tarefas   = $this->idsDoSentinela($rows, self::TAREFA);
        $mensagens = $this->idsDoSentinela($rows, self::MENSAGEM);

        self::assertContains((string) $tarefaA->getId(), $tarefas);
        self::assertContains((string) $mensagemA->getId(), $mensagens);
        self::assertNotContains((string) $tarefaB->getId(), $tarefas);
    }

    #[TestDox('Evento de meta marcado com outro tenant não aparece na timeline da pasta')]
    public function testIsolaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $pastaA  = $this->criarPasta($tenantA);
        $tarefaA = $this->criarTarefa($pastaA);
        $this->em->flush();

        // Linha de auditoria da tarefaA, porém marcada com o tenant errado.
        $this->inserirAudit(self::TAREFA, (string) $tarefaA->getId(), (int) $tenantB->getId(), '2026-06-17 11:00:00');

        $rows = $this->repo->findForPastaTimeline((int) $pastaA->getId(), (int) $tenantA->getId(), null);

        self::assertNotContains((string) $tarefaA->getId(), $this->idsDoSentinela($rows, self::TAREFA));
    }

    #[TestDox('Exclusão de meta (hard delete) é recuperada pelo fallback JSON before.pasta.id')]
    public function testDeleteDeMetaRecuperadoPeloFallbackJson(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $this->em->flush();

        $tenantId = (int) $tenant->getId();
        $pastaId  = (string) $pasta->getId();

        // Simula o estado pós hard delete: a tarefa não existe mais na tabela,
        // então a subquery por pasta_id não casa — só o fallback JSON encontra.
        $entityIdInexistente = '999999999';

        $this->conn->insert('audit_log', [
            'action'       => 'delete',
            'entity_class' => self::TAREFA,
            'entity_id'    => $entityIdInexistente,
            'changes'      => json_encode(['diff' => ['before' => ['pasta' => ['id' => $pastaId], 'titulo' => 'Meta excluída']]]),
            'actor_email'  => $this->sentinela,
            'tenant_id'    => $tenantId,
            'created_at'   => '2026-06-17 12:00:00',
        ]);

        $rows = $this->repo->findForPastaTimeline((int) $pasta->getId(), $tenantId, null);

        self::assertContains($entityIdInexistente, $this->idsDoSentinela($rows, self::TAREFA));
    }

    #[TestDox('Eventos legados de Pasta e PastaDocumento continuam sendo puxados (regressão)')]
    public function testArmsLegadosContinuamFuncionando(): void
    {
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $this->em->flush();

        $tenantId = (int) $tenant->getId();
        $pastaId  = (string) $pasta->getId();

        // Pasta: casa por entity_id direto.
        $this->inserirAudit('App\\Pasta\\Entity\\Pasta', $pastaId, $tenantId, '2026-06-17 09:00:00');

        // PastaDocumento: casa pelo fallback JSON (diff.after.pasta.id), sem depender da linha física.
        $this->conn->insert('audit_log', [
            'action'       => 'create',
            'entity_class' => 'App\\Pasta\\Entity\\PastaDocumento',
            'entity_id'    => '777',
            'changes'      => json_encode(['diff' => ['after' => ['pasta' => ['id' => $pastaId], 'nomeOriginal' => 'peca.pdf']]]),
            'actor_email'  => $this->sentinela,
            'tenant_id'    => $tenantId,
            'created_at'   => '2026-06-17 09:05:00',
        ]);

        $rows = $this->repo->findForPastaTimeline((int) $pasta->getId(), $tenantId, null);

        self::assertContains($pastaId, $this->idsDoSentinela($rows, 'App\\Pasta\\Entity\\Pasta'));
        self::assertContains('777', $this->idsDoSentinela($rows, 'App\\Pasta\\Entity\\PastaDocumento'));
    }
}
