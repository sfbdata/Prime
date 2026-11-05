<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
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
        $this->repo->method('carregarMarcadoresDaLista')->willReturn(['acompanhadas' => []]);
    }

    private function executar(bool $soAListagem): MinhasMetasOutput
    {
        $this->repo->method('findParaMinhasMetas')->willReturn([]);
        $useCase = new ListarMinhasMetasUseCase($this->repo, new AgrupadorDeMetas());

        return $useCase->executar(new User(), AbaMetas::CRIEI, ['busca' => 'x', 'prazo' => 'sem'], $soAListagem, [7]);
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

        $painel = $this->executar(soAListagem: true);

        self::assertSame($andamento, $painel->andamento);
        self::assertSame([$atrasada], $painel->metasQuePrecisamDeAtencao());
    }

    #[TestDox('Com o escritório, o cartão de cada meta da lista vem de uma leitura em lote, presa ao escritório')]
    public function testCartoesDaListaEmLote(): void
    {
        $tenant = new Tenant();
        $meta   = $this->metaComId(42);
        $this->repo->method('findParaMinhasMetas')->willReturn([$meta]);
        $this->repo->expects(self::once())->method('carregarCartoesDasMetas')->with([42], $tenant);
        $this->repo->method('contarAndamento')->willReturn(new AndamentoDasMetasOutput(concluidas: 0, total: 0, atrasadas: 0, emRevisao: 0));

        $useCase = new ListarMinhasMetasUseCase($this->repo, new AgrupadorDeMetas());
        $useCase->executar(new User(), AbaMetas::TODAS, [], true, [], $tenant);
    }

    #[TestDox('Sem metas na lista, não há leitura em lote')]
    public function testSemMetasNaoCarregaCartoes(): void
    {
        $this->repo->method('findParaMinhasMetas')->willReturn([]);
        $this->repo->expects(self::never())->method('carregarCartoesDasMetas');
        $this->repo->method('contarAndamento')->willReturn(new AndamentoDasMetasOutput(concluidas: 0, total: 0, atrasadas: 0, emRevisao: 0));

        $useCase = new ListarMinhasMetasUseCase($this->repo, new AgrupadorDeMetas());
        $useCase->executar(new User(), AbaMetas::TODAS, [], true, [], new Tenant());
    }

    #[TestDox('As caixas da esteira já lidas por quem chama dão o nome da etapa sem ler de novo')]
    public function testCaixasProntasNaoLeemDeNovo(): void
    {
        $tenant = new Tenant();
        $caixa  = new CaixaDaEsteira($tenant, EtapaDaPasta::Protocolo);
        $caixa->configurar(null, 'Protocolar já', []);
        $caixas = $this->createMock(CaixaDaEsteiraRepository::class);
        $caixas->expects(self::never())->method('configuradasDoEscritorio');
        $this->repo->method('contarAndamento')->willReturn(new AndamentoDasMetasOutput(concluidas: 0, total: 0, atrasadas: 0, emRevisao: 0));
        $this->repo->method('findParaMinhasMetas')->willReturn([]);

        $useCase = new ListarMinhasMetasUseCase($this->repo, new AgrupadorDeMetas(), $caixas);
        $painel  = $useCase->executar(new User(), AbaMetas::TODAS, [], true, [], $tenant, [], null, ['protocolo' => $caixa]);

        self::assertSame(['protocolo' => 'Protocolar já'], $painel->nomesDasEtapas);
    }

    private function metaComId(int $id): Tarefa
    {
        $meta = new Tarefa();
        $meta->setPasta(new Pasta());
        (new \ReflectionProperty(Tarefa::class, 'id'))->setValue($meta, $id);

        return $meta;
    }
}
