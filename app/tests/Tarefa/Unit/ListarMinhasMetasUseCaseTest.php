<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\AndamentoDasMetasOutput;
use App\Tarefa\DTO\MinhasMetasOutput;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\AgrupadorDeMetas;
use App\Tarefa\UseCase\ListarMinhasMetasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * O trilho do desenho 04 ("Seu andamento" e "Precisa de atenção") sobre todas as metas da pessoa: o
 * UseCase pede ao repositório o universo próprio do trilho, sem a aba e sem os filtros, inclusive na
 * recarga parcial (o trilho mora no fragmento que o XHR redesenha).
 */
#[CoversClass(ListarMinhasMetasUseCase::class)]
#[Group('tarefa')]
final class ListarMinhasMetasUseCaseTest extends TestCase
{
    private TarefaRepository&MockObject $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(TarefaRepository::class);
        $this->repo->method('findParaMinhasMetas')->willReturn([]);
        $this->repo->method('carregarMarcadoresDaLista')->willReturn(['acompanhadas' => [], 'mensagens' => []]);
        $this->repo->method('numerosNaPasta')->willReturn([]);
    }

    private function executar(bool $modoLista, bool $soAListagem): MinhasMetasOutput
    {
        $useCase = new ListarMinhasMetasUseCase($this->repo, new AgrupadorDeMetas());

        return $useCase->executar(new User(), AbaMetas::CRIEI, ['busca' => 'x', 'prazo' => 'sem'], $modoLista, $soAListagem, [7]);
    }

    #[TestDox('Na recarga parcial (XHR do filtro) o andamento e a atenção continuam vindo de todas as metas, com o cadeado e o teto de 4')]
    public function testTrilhoTambemNaRecargaParcial(): void
    {
        $andamento = new AndamentoDasMetasOutput(concluidas: 1, total: 5, atrasadas: 2, emRevisao: 1);
        $atrasada  = new Tarefa();
        $this->repo->expects(self::once())->method('contarAndamento')->with(self::isInstanceOf(User::class), [7])->willReturn($andamento);
        $this->repo->expects(self::once())->method('findAtrasadasParaAtencao')
            ->with(self::isInstanceOf(User::class), MinhasMetasOutput::TETO_ATENCAO, [7])
            ->willReturn([$atrasada]);
        $this->repo->expects(self::never())->method('contarPorAba');

        $painel = $this->executar(modoLista: false, soAListagem: true);

        self::assertSame($andamento, $painel->andamento);
        self::assertSame([$atrasada], $painel->metasQuePrecisamDeAtencao());
    }

    #[TestDox('O modo lista não desenha o trilho e não paga as duas consultas dele')]
    public function testModoListaDispensaOTrilho(): void
    {
        $this->repo->expects(self::never())->method('contarAndamento');
        $this->repo->expects(self::never())->method('findAtrasadasParaAtencao');

        $painel = $this->executar(modoLista: true, soAListagem: false);

        self::assertSame(0, $painel->totalDoAndamento());
        self::assertSame([], $painel->metasQuePrecisamDeAtencao());
    }
}
