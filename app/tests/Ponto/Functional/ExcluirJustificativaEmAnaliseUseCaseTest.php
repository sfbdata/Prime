<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Exception\ExclusaoDeJustificativaRecusadaException;
use App\Ponto\UseCase\AnalisarJustificativaUseCase;
use App\Ponto\UseCase\ExcluirJustificativaEmAnaliseUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E3 do desenho do Ponto (P41): o colaborador exclui a própria justificativa enquanto ela está em
 * análise. Risco ALTO: a justificativa decide o que a folha abona.
 *
 * Só o dono, só "pendente", só no escritório dela. O atestado sai do disco DEPOIS do commit, e só
 * quando nenhum outro dia do lote ainda aponta para ele.
 */
#[CoversClass(ExcluirJustificativaEmAnaliseUseCase::class)]
final class ExcluirJustificativaEmAnaliseUseCaseTest extends KernelTestCase
{
    /** @var list<string> arquivos semeados pelos testes, apagados no fim */
    private array $criados = [];

    protected function tearDown(): void
    {
        foreach ($this->criados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }

        parent::tearDown();
    }

    #[TestDox('Em análise e do próprio dono: a justificativa sai do banco e o atestado sem outra referência sai do disco')]
    public function testExcluiPendenteEApagaAnexoOrfao(): void
    {
        self::bootKernel();
        [$tenant, $dono] = $this->cenario();
        $anexo           = $this->gravarAnexo();
        [$justificativa] = $this->criarLote($tenant, $dono, $anexo, 1);
        $id              = (int) $justificativa->getId();

        $data = $this->useCase()->executar($id, $dono, $tenant);

        self::assertSame('2026-03-01', $data->format('Y-m-d'), 'a data volta para a mensagem da tela');
        self::assertFalse($this->existeNoBanco($id), 'a justificativa saiu do banco');
        self::assertFileDoesNotExist($this->caminho($anexo), 'o atestado sem referência saiu do disco');
    }

    #[TestDox('Dia de um lote: só aquele dia sai, e o atestado fica porque os outros dias ainda apontam para ele')]
    public function testDiaDoLoteMantemOAnexoDosOutrosDias(): void
    {
        self::bootKernel();
        [$tenant, $dono] = $this->cenario();
        $anexo           = $this->gravarAnexo();
        $lote            = $this->criarLote($tenant, $dono, $anexo, 3);

        $this->useCase()->executar((int) $lote[1]->getId(), $dono, $tenant);

        self::assertFalse($this->existeNoBanco((int) $lote[1]->getId()));
        self::assertTrue($this->existeNoBanco((int) $lote[0]->getId()), 'os outros dias do lote ficam');
        self::assertTrue($this->existeNoBanco((int) $lote[2]->getId()));
        self::assertFileExists($this->caminho($anexo), 'o atestado ainda é dos outros dias do lote');
    }

    /** @return iterable<string, array{string}> */
    public static function situacoesAnalisadas(): iterable
    {
        yield 'abonada' => ['abonado'];
        yield 'recusada' => ['rejeitado'];
        yield 'não abonada' => ['nao_abonado'];
    }

    #[DataProvider('situacoesAnalisadas')]
    #[TestDox('Já analisada ($status): recusa, a justificativa e o atestado ficam')]
    public function testAnalisadaNaoSai(string $status): void
    {
        self::bootKernel();
        [$tenant, $dono] = $this->cenario();
        $anexo           = $this->gravarAnexo();
        [$justificativa] = $this->criarLote($tenant, $dono, $anexo, 1, $status);

        $this->esperarRecusa(
            fn () => $this->useCase()->executar((int) $justificativa->getId(), $dono, $tenant),
            ExclusaoDeJustificativaRecusadaException::JA_ANALISADA,
        );

        self::assertTrue($this->existeNoBanco((int) $justificativa->getId()));
        self::assertFileExists($this->caminho($anexo));
    }

    #[TestDox('Justificativa de outra pessoa do mesmo escritório: recusa, nada sai')]
    public function testDeOutroUsuarioNaoSai(): void
    {
        self::bootKernel();
        [$tenant, $dono] = $this->cenario();
        $outro           = $this->criarUsuario($tenant);
        $anexo           = $this->gravarAnexo();
        [$justificativa] = $this->criarLote($tenant, $dono, $anexo, 1);

        $this->esperarRecusa(
            fn () => $this->useCase()->executar((int) $justificativa->getId(), $outro, $tenant),
            ExclusaoDeJustificativaRecusadaException::DE_OUTRA_PESSOA,
        );

        self::assertTrue($this->existeNoBanco((int) $justificativa->getId()));
        self::assertFileExists($this->caminho($anexo));
    }

    #[TestDox('Justificativa de outro escritório: não encontrada (nem o dono a apaga pelo escritório errado)')]
    public function testDeOutroEscritorioNaoSai(): void
    {
        self::bootKernel();
        [$tenantA, $dono] = $this->cenario();
        [$tenantB]        = $this->cenario();
        $this->em()->persist(new UserTenant($dono, $tenantB));
        $this->em()->flush();
        $anexo           = $this->gravarAnexo();
        [$justificativa] = $this->criarLote($tenantA, $dono, $anexo, 1);

        $this->esperarRecusa(
            fn () => $this->useCase()->executar((int) $justificativa->getId(), $dono, $tenantB),
            ExclusaoDeJustificativaRecusadaException::NAO_ENCONTRADA,
        );

        self::assertTrue($this->existeNoBanco((int) $justificativa->getId()));
        self::assertFileExists($this->caminho($anexo));
    }

    #[TestDox('E10: em análise, mas um ajuste de batida aponta para ela (abonada e devolvida para análise): recusa antes de escrever, a justificativa, o atestado e os ajustes ficam, e a trilha não ganha "excluída"')]
    public function testComAjusteLigadoNaoSai(): void
    {
        self::bootKernel();
        [$tenant, $dono] = $this->cenario();
        $gestor          = $this->criarUsuario($tenant);
        $anexo           = $this->gravarAnexo();
        [$justificativa] = $this->criarLote($tenant, $dono, $anexo, 1);
        $justificativa->setTipo('esquecimento_registro');
        $justificativa->setTipoRegistroEsquecido('entrada');
        $justificativa->setHoraRegistroEsquecido(new \DateTime('1970-01-01 08:00:00'));
        $this->em()->flush();
        $analisar = static::getContainer()->get(AnalisarJustificativaUseCase::class);
        $analisar->abonar($justificativa, $tenant, $gestor, '');
        $analisar->reverter($justificativa, $tenant, $gestor);
        $id      = (int) $justificativa->getId();
        $eventos = $this->eventosDaTrilha($tenant);

        try {
            $this->useCase()->executar($id, $dono, $tenant);
            self::fail('a exclusão devia ser recusada');
        } catch (ExclusaoDeJustificativaRecusadaException $e) {
            self::assertSame(ExclusaoDeJustificativaRecusadaException::MEXEU_NA_FOLHA, $e->motivo);
            self::assertSame('Esta justificativa já mexeu na folha do ponto, por isso não pode ser excluída. Ela continua em análise com o gestor.', $e->getMessage());
        }

        self::assertTrue($this->em()->isOpen(), 'a recusa não fecha o EntityManager');
        self::assertTrue($this->existeNoBanco($id), 'a justificativa fica');
        self::assertFileExists($this->caminho($anexo), 'o atestado fica');
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_ajuste WHERE justificativa_id = ?', [$id]));
        self::assertSame($eventos, $this->eventosDaTrilha($tenant), 'nenhum evento novo na trilha');
    }

    #[TestDox('Sem atestado: a justificativa sai e nada é procurado no disco')]
    public function testSemAnexoExcluiSoORegistro(): void
    {
        self::bootKernel();
        [$tenant, $dono] = $this->cenario();
        [$justificativa] = $this->criarLote($tenant, $dono, null, 1);
        $id              = (int) $justificativa->getId();

        $this->useCase()->executar($id, $dono, $tenant);

        self::assertFalse($this->existeNoBanco($id));
    }

    // ------------------------------------------------------------------ helpers

    private function esperarRecusa(callable $acao, string $motivo): void
    {
        try {
            $acao();
            self::fail('a exclusão devia ser recusada');
        } catch (ExclusaoDeJustificativaRecusadaException $e) {
            self::assertSame($motivo, $e->motivo);
        }

        self::assertTrue($this->em()->isOpen(), 'a recusa não fecha o EntityManager: o resto da requisição segue');
    }

    /** Pelo banco, e não pela entidade em memória. */
    private function existeNoBanco(int $id): bool
    {
        return $this->em()->getConnection()->fetchOne(
            'SELECT 1 FROM justificativa_ponto WHERE id = ?',
            [$id],
        ) !== false;
    }

    /** @return list<string> */
    private function eventosDaTrilha(Tenant $tenant): array
    {
        return array_map('strval', $this->em()->getConnection()->fetchFirstColumn(
            'SELECT acao FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$tenant->getId()],
        ));
    }

    private function useCase(): ExcluirJustificativaEmAnaliseUseCase
    {
        return static::getContainer()->get(ExcluirJustificativaEmAnaliseUseCase::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function caminho(string $nome): string
    {
        return (string) static::getContainer()->getParameter('justificativas_uploads_dir') . '/' . $nome;
    }

    private function gravarAnexo(): string
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 1);

        $nome = static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePonto::novoAnexoDeLote($tenant, 'pdf'),
            FonteDeConteudo::deTexto('%PDF-1.4 atestado'),
        )->chave->nome;
        $this->criados[] = $this->caminho($nome);

        return $nome;
    }

    /** @return list<JustificativaPonto> */
    private function criarLote(Tenant $tenant, User $user, ?string $anexo, int $dias, string $status = 'pendente'): array
    {
        $batch = bin2hex(random_bytes(12));
        $lote  = [];

        for ($i = 1; $i <= $dias; $i++) {
            $j = new JustificativaPonto();
            $j->setUser($user);
            $j->setTenant($tenant);
            $j->setData(new \DateTime('2026-03-' . str_pad((string) $i, 2, '0', \STR_PAD_LEFT)));
            $j->setAnexoPath($anexo);
            $j->setStatus($status);
            $j->setBatchId($batch);
            $this->em()->persist($j);
            $lote[] = $j;
        }

        $this->em()->flush();

        return $lote;
    }

    /** @return array{0: Tenant, 1: User} */
    private function cenario(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant PONTO EXCLUIR ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return [$tenant, $this->criarUsuario($tenant)];
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('ponto_excluir_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Ponto');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }
}
