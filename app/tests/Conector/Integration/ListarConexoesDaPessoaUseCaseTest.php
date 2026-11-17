<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ConexaoDeIaOutput;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\UseCase\ListarConexoesDaPessoaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A lista "Conexões de I.A." do Meu Perfil (`docs/specs/mcp-remoto-oauth.md`, 13.1 linha do PR 8, e decisões 1 e 2 do
 * plano): as conexões ativas da pessoa em todos os escritórios dela, com a sessão num só; o app pelo host do
 * `client_id`; a conexão do escritório com vínculo caído aparece marcada.
 */
#[CoversClass(ListarConexoesDaPessoaUseCase::class)]
final class ListarConexoesDaPessoaUseCaseTest extends KernelTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** @return list<ConexaoDeIaOutput> */
    private function listar(User $pessoa): array
    {
        return static::getContainer()->get(ListarConexoesDaPessoaUseCase::class)->executar($pessoa);
    }

    private function sessaoNoEscritorio(Tenant $tenant): void
    {
        $this->em()->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    /** @param list<string> $escopos */
    private function conexao(User $pessoa, Tenant $escritorio, string $host, array $escopos, string $quando): ConsentimentoDoConector
    {
        $consentimento = new ConsentimentoDoConector($pessoa, $escritorio, $this->appDeIa($host), $escopos, new \DateTimeImmutable($quando));
        $this->em()->persist($consentimento);
        $this->em()->flush();

        return $consentimento;
    }

    #[TestDox('Só as ativas da pessoa, dos dois escritórios com a sessão num só, a mais recente primeiro, com o host do client_id e o escritório')]
    public function testListaAsAtivasDaPessoaEmTodosOsEscritorios(): void
    {
        $a = $this->escritorio('A');
        $b = $this->escritorio('B');
        $pessoa = $this->pessoa();
        $outraPessoa = $this->pessoa();
        $this->vinculo($pessoa, $a);
        $this->vinculo($pessoa, $b);
        $this->vinculo($outraPessoa, $a);
        $deA = $this->conexao($pessoa, $a, 'claude.ai', ['mcp:leitura', 'offline_access'], '2026-11-10 09:00:00');
        $deB = $this->conexao($pessoa, $b, 'chatgpt.com', ['mcp:leitura', 'mcp:escrita'], '2026-11-12 15:30:00');
        $deB->registrarUso(new \DateTimeImmutable('2026-11-15 08:00:00'));
        $this->conexao($pessoa, $a, 'outro.example', ['mcp:leitura'], '2026-11-14 10:00:00')->revogar(new \DateTimeImmutable());
        $this->conexao($outraPessoa, $a, 'claude.ai', ['mcp:leitura'], '2026-11-15 10:00:00');
        $this->em()->flush();
        $this->em()->clear();
        $this->sessaoNoEscritorio($a);

        $lista = $this->listar($this->em()->find(User::class, $pessoa->getId()) ?? self::fail('pessoa sumiu'));

        self::assertSame([$deB->getId(), $deA->getId()], array_map(static fn (ConexaoDeIaOutput $c): int => $c->id, $lista));
        self::assertEquals(
            new ConexaoDeIaOutput((int) $deB->getId(), 'chatgpt.com', (string) $b->getName(), (int) $b->getId(), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('2026-11-12 15:30:00'), new \DateTimeImmutable('2026-11-15 08:00:00'), true),
            $lista[0],
        );
        self::assertSame('claude.ai', $lista[1]->hostDoApp, 'o host do client_id, nunca o client_name ("App")');
        self::assertSame($a->getName(), $lista[1]->nomeDoEscritorio);
        self::assertNull($lista[1]->ultimoUsoEm);
        self::assertTrue($lista[1]->vinculoAtivo);
    }

    #[TestDox('Vínculo caído (suspenso ou removido): a conexão continua na lista, com vinculoAtivo falso')]
    public function testVinculoCaidoMarcaAConexao(): void
    {
        $ativo = $this->escritorio('Ativo');
        $suspenso = $this->escritorio('Suspenso');
        $removido = $this->escritorio('Removido');
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $ativo);
        $this->vinculo($pessoa, $suspenso)->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $vinculoRemovido = $this->vinculo($pessoa, $removido);
        $this->conexao($pessoa, $ativo, 'claude.ai', ['mcp:leitura'], '2026-11-10 09:00:00');
        $this->conexao($pessoa, $suspenso, 'claude.ai', ['mcp:leitura'], '2026-11-11 09:00:00');
        $this->conexao($pessoa, $removido, 'claude.ai', ['mcp:leitura'], '2026-11-12 09:00:00');
        $this->em()->remove($vinculoRemovido);
        $this->em()->flush();
        $this->sessaoNoEscritorio($ativo);

        $porEscritorio = [];
        foreach ($this->listar($pessoa) as $conexao) {
            $porEscritorio[$conexao->tenantId] = $conexao->vinculoAtivo;
        }

        self::assertSame([(int) $removido->getId() => false, (int) $suspenso->getId() => false, (int) $ativo->getId() => true], $porEscritorio);
    }

    #[TestDox('Sem conexão ativa: lista vazia')]
    public function testSemConexaoListaVazia(): void
    {
        self::assertSame([], $this->listar($this->pessoa()));
    }
}
