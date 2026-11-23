<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Tenant\Tenant;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Enum\SituacaoDaContratacao;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A migration `Version20261123180000` (spec multiproduto, §4) desce e sobe dentro da transação do DAMA; os CHECK
 * recusam produto fora da lista; e a purga do escritório leva as linhas dele, e só as dele.
 */
#[CoversClass(PurgarEscritorioUseCase::class)]
final class MigracaoDaContratacaoTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261123180000';

    #[TestDox('down tira a tabela contratacao; up devolve com o índice único parcial')]
    public function testDownEUp(): void
    {
        self::bootKernel();
        self::assertSame(0, $this->executar('--down'));
        self::assertFalse($this->tabelaExiste($this->banco()));

        // A migration executada fica congelada na fábrica de dependências: cada execução precisa de um kernel novo.
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));
        $banco = $this->banco();
        self::assertTrue($this->tabelaExiste($banco));
        self::assertSame(
            "CREATE UNIQUE INDEX uniq_contratacao_ativa ON public.contratacao USING btree (tenant_id, produto) WHERE ((situacao)::text = 'ativa'::text)",
            $banco->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'uniq_contratacao_ativa'"),
        );
    }

    #[TestDox('O CHECK recusa produto fora da lista')]
    public function testCheckRecusaProdutoDesconhecido(): void
    {
        self::bootKernel();
        $tenant = $this->escritorio('CHECK ' . uniqid());

        // O DBAL não tem exceção própria para CHECK: confere o nome da constraint na mensagem.
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_contratacao_produto/');

        $this->inserir((int) $tenant->getId(), 'xadrez');
    }

    #[TestDox('O CHECK de período recusa fim antes do início')]
    public function testCheckRecusaFimAntesDoInicio(): void
    {
        self::bootKernel();
        $tenant = $this->escritorio('Período ' . uniqid());

        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_contratacao_periodo/');

        $this->banco()->executeStatement(
            "INSERT INTO contratacao (tenant_id, produto, situacao, origem, inicio, fim, criada_em, atualizada_em)
             VALUES (?, 'voto', 'encerrada', 'dono', NOW(), NOW() - INTERVAL '1 day', NOW(), NOW())",
            [$tenant->getId()],
        );
    }

    #[TestDox('Os CHECK do banco aceitam exatamente os valores dos enums do PHP')]
    public function testChecksBatemComOsEnums(): void
    {
        self::bootKernel();
        $esperado = [
            'chk_contratacao_produto' => Produto::cases(),
            'chk_contratacao_situacao' => SituacaoDaContratacao::cases(),
            'chk_contratacao_origem' => OrigemDaContratacao::cases(),
        ];

        foreach ($esperado as $nome => $casos) {
            $definicao = (string) $this->banco()->fetchOne(
                "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'contratacao'::regclass AND conname = ?",
                [$nome],
            );
            preg_match_all("/'([a-z]+)'::/", $definicao, $achados);
            $valores = array_map(static fn (\BackedEnum $c): string|int => $c->value, $casos);

            self::assertEqualsCanonicalizing($valores, $achados[1], $nome . ': enum e CHECK divergem (produto novo pede migration)');
        }
    }

    #[TestDox('Purgar o escritório apaga as linhas de contratação dele; o vizinho fica intacto')]
    public function testPurgaApagaAsLinhas(): void
    {
        self::bootKernel();
        $alvo = $this->escritorio('Purga alvo ' . uniqid(), emQuarentena: true);
        $vizinho = $this->escritorio('Purga vizinho ' . uniqid(), emQuarentena: true);
        foreach ([$alvo, $vizinho] as $tenant) {
            $this->inserir((int) $tenant->getId(), 'voto');
        }

        $resultado = static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($alvo, dryRun: false);

        self::assertSame(1, $resultado->linhasPorTabela['contratacao'] ?? 0, 'sai pela ordem de deleção, não pela cascata');
        self::assertSame(0, $this->linhasDo((int) $alvo->getId()));
        self::assertSame(1, $this->linhasDo((int) $vizinho->getId()));
    }

    private function escritorio(string $nome, bool $emQuarentena = false): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName($nome);
        if ($emQuarentena) {
            $tenant->setIsActive(false);
            $tenant->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        }
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function inserir(int $tenantId, string $produto): void
    {
        $this->banco()->executeStatement(
            "INSERT INTO contratacao (tenant_id, produto, situacao, origem, inicio, criada_em, atualizada_em)
             VALUES (?, ?, 'ativa', 'dono', NOW(), NOW(), NOW())",
            [$tenantId, $produto],
        );
    }

    private function executar(string $sentido): int
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('doctrine:migrations:execute'));

        return $tester->execute(['versions' => [self::VERSAO], $sentido => true], ['interactive' => false]);
    }

    private function banco(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function tabelaExiste(Connection $banco): bool
    {
        return 1 === (int) $banco->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'contratacao'");
    }

    private function linhasDo(int $tenantId): int
    {
        return (int) $this->banco()->fetchOne('SELECT COUNT(*) FROM contratacao WHERE tenant_id = ?', [$tenantId]);
    }
}
