<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Auth\Entity\CadastroPendente;
use App\Auth\Entity\RedefinicaoSenha;
use App\Entity\Auth\User;
use App\Command\PurgarDadosExpiradosCommand;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Shared\Doubles\FalhaDeCommitArmavel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(PurgarDadosExpiradosCommand::class)]
final class PurgarDadosExpiradosCommandTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
    }

    #[TestDox('--dry-run relata e não apaga nada')]
    public function testDryRunNaoApaga(): void
    {
        $cadastroId = $this->criarCadastroExpirado();
        $tenantId   = (int) $this->criarTenantPurgavel()->getId();

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('simulação', strtolower($tester->getDisplay()));

        $this->em->clear();
        self::assertNotNull($this->em->find(CadastroPendente::class, $cadastroId), 'dry-run não apaga cadastro');
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = :t', ['t' => $tenantId]), 'dry-run não apaga tenant');
    }

    #[TestDox('--force executa as três faxinas (cadastro expirado + redefinição de senha + escritório em quarentena)')]
    public function testForceExecutaAsTresFaxinas(): void
    {
        $cadastroId   = $this->criarCadastroExpirado();
        $redefinicaoId = $this->criarRedefinicaoVencida();
        $tenantId     = (int) $this->criarTenantPurgavel()->getId();

        $tester = $this->tester();
        $tester->execute(['--force' => true]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        self::assertNull($this->em->find(CadastroPendente::class, $cadastroId), 'cadastro expirado apagado');
        // Sem esta asserção, o UseCase de purga podia continuar existindo sem chamador
        // nenhum — que foi exatamente como ele nasceu.
        self::assertNull($this->em->find(RedefinicaoSenha::class, $redefinicaoId), 'pedido de redefinição vencido apagado');
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = :t', ['t' => $tenantId]), 'escritório em quarentena purgado');
    }

    /** @return int id do pedido de redefinição já vencido (guarda IP + user agent) */
    private function criarRedefinicaoVencida(): int
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('purgacmd_' . uniqid() . '@adv.com');
        $user->setFullName('Purga Comando');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'x'));
        $this->em->persist($user);

        $pedido = new RedefinicaoSenha(
            user: $user,
            tokenHash: RedefinicaoSenha::hashDoToken(bin2hex(random_bytes(32))),
            expiraEm: new \DateTimeImmutable('-2 hours'),
            ip: '1.2.3.4',
            userAgent: 'UA',
        );
        $this->em->persist($pedido);
        $this->em->flush();

        return (int) $pedido->getId();
    }

    #[TestDox('Falha na purga de um escritório NÃO aborta os demais (retorna FAILURE)')]
    public function testFalhaEmUmTenantNaoAbortaOsDemais(): void
    {
        $idA = (int) $this->criarTenantPurgavel()->getId();
        $idB = (int) $this->criarTenantPurgavel()->getId();

        // Força falha só no tenant A: uma tabela tenant-scoped nova (não coberta) com linha
        // de A faz o guard anti-drift abortar a purga de A — mas não a de B.
        $this->conn->executeStatement('CREATE TABLE _drift_cmd (id serial PRIMARY KEY, tenant_id integer NOT NULL)');
        $this->conn->executeStatement('INSERT INTO _drift_cmd (tenant_id) VALUES (:t)', ['t' => $idA]);

        try {
            $tester = $this->tester();
            $tester->execute(['--force' => true]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'deve retornar FAILURE por causa da falha em A');
            self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = :t', ['t' => $idA]), 'A falhou → preservado por rollback');
            self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = :t', ['t' => $idB]), 'B seguiu e foi purgado');
        } finally {
            $this->conn->executeStatement('DROP TABLE IF EXISTS _drift_cmd');
        }
    }

    /**
     * E2.5: anexos de Tarefa ficam fora da purga por decisão (E2.7). São dívida conhecida: aparecem
     * na saída, para limpeza manual, mas não derrubam o código de saída — senão todo escritório que
     * usou o chat de Tarefa deixaria o cron em alerta permanente.
     */
    #[TestDox('Anexos de Tarefa fora da purga: listados na saída, sem FAILURE')]
    public function testTarefaForaDaPurgaNaoViraFalha(): void
    {
        $tenantId = (int) $this->criarTenantPurgavel()->getId();
        $userId   = (int) UserFactory::createOne()->getId();
        $ts       = '2020-01-01 00:00:00';
        $pasta    = (int) $this->conn->fetchOne('INSERT INTO pasta (nup, data_abertura, created_at, tenant_id) VALUES (?, ?, ?, ?) RETURNING id', ['NUP-' . uniqid(), $ts, $ts, $tenantId]);
        $tarefa   = (int) $this->conn->fetchOne("INSERT INTO tarefa (pasta_id, titulo, descricao, status, data_criacao, tenant_id) VALUES (?, 'T', 'd', 'pendente', ?, ?) RETURNING id", [$pasta, $ts, $tenantId]);
        $valor    = '/uploads/tarefas/chat/arquivo_' . uniqid('', true) . '.pdf';
        $this->conn->executeStatement(
            "INSERT INTO tarefa_mensagem (tarefa_id, usuario_id, mensagem, criado_em, tenant_id, arquivo_anexo) VALUES (?, ?, 'm', ?, ?, ?)",
            [$tarefa, $userId, $ts, $tenantId, $valor],
        );

        $tester = $this->tester();
        $tester->execute(['--force' => true]);
        $saida = preg_replace('/\s+/', '', $tester->getDisplay()) ?? '';

        $tester->assertCommandIsSuccessful();
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = :t', ['t' => $tenantId]));
        self::assertStringContainsString(basename($valor), $saida);
        self::assertStringContainsString('foradapurga', mb_strtolower($saida));
    }

    /**
     * Depois do COMMIT o disco nunca derruba a purga. Arquivo sem prova de pertencimento é
     * reportado como sobra — com texto próprio, diferente de "Falha ao purgar" (que só significa
     * "nada foi apagado") — e o comando devolve FAILURE para o cron e o alerta enxergarem.
     */
    #[TestDox('Escritório purgado com arquivo que ficou no disco: FAILURE, aviso próprio e o escritório apagado')]
    public function testSobraNoDiscoEhReportadaSemVirarFalhaDePurga(): void
    {
        $tenantId = (int) $this->criarTenantPurgavel()->getId();
        $ativo    = new Tenant();
        $ativo->setName('Ativo ' . uniqid());
        $ativo->setIsActive(true);
        $this->em->persist($ativo);
        $this->em->flush();

        $nome = bin2hex(random_bytes(16)) . '.pdf';
        foreach ([$tenantId, (int) $ativo->getId()] as $t) {
            $ts = '2020-01-01 00:00:00';
            $p  = (int) $this->conn->fetchOne('INSERT INTO pasta (nup, data_abertura, created_at, tenant_id) VALUES (?, ?, ?, ?) RETURNING id', ['NUP-' . uniqid(), $ts, $ts, $t]);
            $this->conn->executeStatement(
                "INSERT INTO pasta_documento (titulo, categoria, caminho_arquivo, nome_original, mime_type, tamanho_bytes, uploaded_at, pasta_id, tenant_id) VALUES ('D', 'geral', ?, 'd.pdf', 'application/pdf', 1, ?, ?, ?)",
                [$nome, $ts, $p, $t],
            );
        }

        $tester = $this->tester();
        $tester->execute(['--force' => true]);
        $saida = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = :t', ['t' => $tenantId]), 'o escritório foi purgado');
        self::assertStringContainsString('purgado no banco', $saida);
        self::assertStringContainsString($nome, preg_replace('/\s+/', '', $saida) ?? '');
        self::assertStringNotContainsString('Falha ao purgar', $saida);
    }

    /** COMMIT com a resposta perdida: o comando não pode dizer "nada foi apagado". */
    #[TestDox('COMMIT da purga com resultado incerto: FAILURE e mensagem de resultado INCERTO')]
    public function testCommitIncertoTemMensagemPropria(): void
    {
        $tenantId = (int) $this->criarTenantPurgavel()->getId();
        FalhaDeCommitArmavel::perderRespostaDoProximoCommit();

        try {
            $tester = $this->tester();
            $tester->execute(['--force' => true]);
        } finally {
            FalhaDeCommitArmavel::desarmar();
        }

        $saida = $tester->getDisplay();
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('INCERTO', $saida);
        self::assertStringContainsString('#' . $tenantId, $saida);
        self::assertStringNotContainsString('nada foi apagado dele', $saida);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(static::getContainer()->get(PurgarDadosExpiradosCommand::class));
    }

    private function criarTenantPurgavel(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Quarentena ' . uniqid());
        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarCadastroExpirado(): int
    {
        $cadastro = new CadastroPendente(
            email: 'exp' . uniqid() . '@x.com',
            token: bin2hex(random_bytes(16)),
            nomeCompleto: 'Fulano',
            nomeEscritorio: 'Escritório',
            oabNumero: '12345',
            oabUf: 'SP',
            senhaHash: 'hash',
            ip: '127.0.0.1',
            expiresAt: new \DateTimeImmutable('-1 hour'),
        );
        $this->em->persist($cadastro);
        $this->em->flush();

        return (int) $cadastro->getId();
    }
}
