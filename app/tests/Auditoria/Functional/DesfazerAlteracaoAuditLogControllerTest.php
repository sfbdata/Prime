<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Controller\AuditLogController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * D-AUDIT-UNDO pela rota real (`POST /auditoria/{id}/desfazer`): o valor do log é a forma
 * normalizada pelo AuditLogSubscriber e precisa voltar pelo tipo do campo; o que não se desfaz
 * recusa a operação inteira, sem "sucesso" e sem tocar no banco.
 */
#[CoversClass(AuditLogController::class)]
#[CoversClass(DesfazerAlteracaoAuditLogUseCase::class)]
final class DesfazerAlteracaoAuditLogControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = $this->criarTenant('Desfazer A');
        $this->admin = $this->criarUsuario('admin_desfazer', $this->tenant, ['ROLE_SUPER_ADMIN']);

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        $this->logarComTenant($this->client, $this->admin, $this->tenant);
    }

    private function criarTenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function criarUsuario(string $prefixo, Tenant $tenant, array $roles = []): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . $prefixo);
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist(new UserTenant($user, $tenant));
        $this->em->flush();

        return $user;
    }

    private function criarPasta(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-DESFAZER-' . uniqid());
        $pasta->setTenant($this->tenant);
        $pasta->setPrioridade(PrioridadePasta::Normal);
        $pasta->setNomeAcao('acao atual');
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
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

        self::assertResponseRedirects();
    }

    /** @return list<string> */
    private function flashes(string $tipo): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($tipo);
    }

    /** @return array<string, mixed> */
    private function linhaDaPasta(int $id): array
    {
        $linha = $this->em->getConnection()->fetchAssociative(
            'SELECT prioridade, nome_acao, data_abertura, responsavel_id FROM pasta WHERE id = ?',
            [$id],
        );
        self::assertIsArray($linha);

        return $linha;
    }

    private function contarLogsDaPasta(int $id): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE entity_class = ? AND entity_id = ?',
            [Pasta::class, (string) $id],
        );
    }

    #[TestDox('enum no formato novo: a prioridade volta ao caso anterior e o próprio desfazer é auditado')]
    public function testEnumFormatoNovo(): void
    {
        $pasta = $this->criarPasta();
        $id = (int) $pasta->getId();
        $logId = $this->criarLog(Pasta::class, $id, ['prioridade' => ['from' => 'urgente', 'to' => 'normal']]);
        $logsAntes = $this->contarLogsDaPasta($id);

        $this->desfazer($logId);

        self::assertSame([], $this->flashes('error'));
        self::assertNotSame([], $this->flashes('success'));
        self::assertSame('urgente', $this->linhaDaPasta($id)['prioridade']);
        self::assertSame($logsAntes + 1, $this->contarLogsDaPasta($id), 'o desfazer gera o seu próprio registro de auditoria');
    }

    #[TestDox('enum no formato antigo {class, id, label: null}: recusa com mensagem e nada muda')]
    public function testEnumFormatoAntigoRecusa(): void
    {
        $pasta = $this->criarPasta();
        $id = (int) $pasta->getId();
        $logId = $this->criarLog(Pasta::class, $id, [
            'nomeAcao' => ['from' => 'acao antiga', 'to' => 'ACAO ATUAL'],
            'prioridade' => ['from' => ['class' => PrioridadePasta::class, 'id' => null, 'label' => null], 'to' => 'normal'],
        ]);
        $logsAntes = $this->contarLogsDaPasta($id);

        $this->desfazer($logId);

        self::assertSame([sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_VALOR_IRRECUPERAVEL, 'prioridade')], $this->flashes('error'));
        self::assertSame([], $this->flashes('success'));
        $linha = $this->linhaDaPasta($id);
        self::assertSame('normal', $linha['prioridade']);
        self::assertSame('ACAO ATUAL', $linha['nome_acao']);
        self::assertSame($logsAntes, $this->contarLogsDaPasta($id));
    }

    #[TestDox('data em ATOM volta como data no banco')]
    public function testData(): void
    {
        $pasta = $this->criarPasta();
        $id = (int) $pasta->getId();
        $logId = $this->criarLog(Pasta::class, $id, ['dataAbertura' => ['from' => '2025-03-04T10:20:30+00:00', 'to' => '2026-10-06T08:00:00+00:00']]);

        $this->desfazer($logId);

        self::assertSame([], $this->flashes('error'));
        self::assertStringStartsWith('2025-03-04 10:20:30', (string) $this->linhaDaPasta($id)['data_abertura']);
    }

    #[TestDox('escalar: o texto anterior volta (o setter grava em maiúsculas)')]
    public function testEscalar(): void
    {
        $pasta = $this->criarPasta();
        $id = (int) $pasta->getId();
        $logId = $this->criarLog(Pasta::class, $id, ['nomeAcao' => ['from' => 'acao antiga', 'to' => 'ACAO ATUAL']]);

        $this->desfazer($logId);

        self::assertSame([], $this->flashes('error'));
        self::assertSame((new Pasta())->setNomeAcao('acao antiga')->getNomeAcao(), $this->linhaDaPasta($id)['nome_acao']);
    }

    #[TestDox('associação a usuário do MESMO escritório: o responsável volta')]
    public function testAssociacaoMesmoTenant(): void
    {
        $pasta = $this->criarPasta();
        $id = (int) $pasta->getId();
        $colega = $this->criarUsuario('colega', $this->tenant);
        $logId = $this->criarLog(Pasta::class, $id, ['responsavel' => ['from' => ['class' => User::class, 'id' => (string) $colega->getId(), 'label' => 'Colega'], 'to' => null]]);

        $this->desfazer($logId);

        self::assertSame([], $this->flashes('error'));
        self::assertSame($colega->getId(), (int) $this->linhaDaPasta($id)['responsavel_id']);
    }

    #[TestDox('associação a usuário de OUTRO escritório: recusa, o responsável não muda')]
    public function testAssociacaoOutroTenantRecusa(): void
    {
        $pasta = $this->criarPasta();
        $id = (int) $pasta->getId();
        $estranho = $this->criarUsuario('estranho', $this->criarTenant('Desfazer B'));
        $logId = $this->criarLog(Pasta::class, $id, ['responsavel' => ['from' => ['class' => User::class, 'id' => (string) $estranho->getId(), 'label' => 'Estranho'], 'to' => null]]);

        $this->desfazer($logId);

        self::assertSame([sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_ASSOCIACAO_INDISPONIVEL, 'responsavel')], $this->flashes('error'));
        self::assertNull($this->linhaDaPasta($id)['responsavel_id']);
    }

    #[TestDox('associação a pasta de OUTRO escritório: recusa (a referência nunca é de outro tenant)')]
    public function testAssociacaoPastaDeOutroTenantRecusa(): void
    {
        $alheia = new Pasta();
        $alheia->setNup('TEST-ALHEIA-' . uniqid());
        $alheia->setTenant($this->criarTenant('Desfazer C'));
        $this->em->persist($alheia);

        $tarefa = new \App\Entity\Tarefa\Tarefa();
        $tarefa->setTitulo('Tarefa desfazer');
        $tarefa->setDescricao('...');
        $tarefa->setTenant($this->tenant);
        $tarefa->setPasta($this->criarPasta());
        $this->em->persist($tarefa);
        $this->em->flush();
        $pastaOriginal = $tarefa->getPasta()->getId();

        $logId = $this->criarLog(\App\Entity\Tarefa\Tarefa::class, (int) $tarefa->getId(), [
            'pasta' => ['from' => ['class' => Pasta::class, 'id' => (string) $alheia->getId(), 'label' => null], 'to' => ['class' => Pasta::class, 'id' => (string) $pastaOriginal, 'label' => null]],
        ]);

        $this->desfazer($logId);

        self::assertSame([sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_ASSOCIACAO_INDISPONIVEL, 'pasta')], $this->flashes('error'));
        self::assertSame($pastaOriginal, (int) $this->em->getConnection()->fetchOne('SELECT pasta_id FROM tarefa WHERE id = ?', [$tarefa->getId()]));
    }

    #[TestDox('campo sem setter (checklistMotivo): recusa a operação inteira, sem "sucesso" e sem efeito')]
    public function testCampoSemSetterRecusa(): void
    {
        $pasta = $this->criarPasta();
        $id = (int) $pasta->getId();
        $logId = $this->criarLog(Pasta::class, $id, [
            'nomeAcao' => ['from' => 'acao antiga', 'to' => 'ACAO ATUAL'],
            'checklistMotivo' => ['from' => 'encerrada', 'to' => null],
        ]);
        $logsAntes = $this->contarLogsDaPasta($id);

        $this->desfazer($logId);

        self::assertSame([sprintf(DesfazerAlteracaoAuditLogUseCase::MENSAGEM_CAMPO_NAO_REVERSIVEL, 'checklistMotivo')], $this->flashes('error'));
        self::assertSame([], $this->flashes('success'));
        self::assertSame('ACAO ATUAL', $this->linhaDaPasta($id)['nome_acao']);
        self::assertSame($logsAntes, $this->contarLogsDaPasta($id));
    }

    #[TestDox('item na lixeira continua recusado, apontando para a lixeira da pasta')]
    public function testItemNaLixeiraRecusa(): void
    {
        $pasta = $this->criarPasta();
        $doc = new PastaDocumento();
        $doc->setTitulo('doc.pdf');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('fake-' . bin2hex(random_bytes(6)) . '.pdf');
        $doc->setNomeOriginal('doc.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($pasta);
        $doc->setTenant($this->tenant);
        $this->em->persist($doc);
        $this->em->flush();
        $doc->marcarExcluido($this->admin, new \DateTimeImmutable('2026-10-06 10:00:00'));
        $this->em->flush();

        $logId = $this->criarLog(PastaDocumento::class, (int) $doc->getId(), ['nomeOriginal' => ['from' => 'antigo.pdf', 'to' => 'doc.pdf']]);

        $this->desfazer($logId);

        self::assertSame([DesfazerAlteracaoAuditLogUseCase::MENSAGEM_ITEM_NA_LIXEIRA], $this->flashes('error'));
    }

    #[TestDox('log de outro escritório: "Registro não encontrado", nada muda')]
    public function testLogDeOutroTenantRecusa(): void
    {
        $pasta = $this->criarPasta();
        $id = (int) $pasta->getId();
        $outro = $this->criarTenant('Desfazer D');
        $log = (new AuditLog())
            ->setAction('update')
            ->setEntityClass(Pasta::class)
            ->setEntityId((string) $id)
            ->setChanges(['diff' => ['changes' => ['nomeAcao' => ['from' => 'acao antiga', 'to' => 'ACAO ATUAL']]]])
            ->setTenantId($outro->getId());
        $this->em->persist($log);
        $this->em->flush();

        $this->desfazer((int) $log->getId());

        self::assertSame(['Registro não encontrado.'], $this->flashes('error'));
        self::assertSame('ACAO ATUAL', $this->linhaDaPasta($id)['nome_acao']);
    }
}
