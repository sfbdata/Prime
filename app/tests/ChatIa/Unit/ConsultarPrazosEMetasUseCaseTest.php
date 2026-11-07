<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\DTO\ConsultaDePrazosInput;
use App\ChatIa\Enum\PeriodoDaConsulta;
use App\ChatIa\Exception\SemAcessoAsMetasNoChatException;
use App\ChatIa\Service\GuardaDaConsultaDePrazos;
use App\ChatIa\Service\ListaDasMinhasMetas;
use App\ChatIa\UseCase\ConsultarPrazosEMetasUseCase;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Pasta\Entity\Pasta;
use App\Processo\Repository\ProcessoRepository;
use App\Service\PermissionChecker;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A consulta de prazos e metas do chat sem banco: a lista da tela é de mentira (devolve o que o caso mandar e guarda o que
 * pediram a ela), o repositório de processos diz quais pastas estão em segredo de justiça. Hoje é 07/11/2026.
 */
#[CoversClass(ConsultarPrazosEMetasUseCase::class)]
final class ConsultarPrazosEMetasUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private User $eu;

    /** @var list<array{aba: AbaMetas, filtro: string}> o que a consulta pediu à lista da tela */
    private array $pedidosALista = [];

    /** @var list<list<int>> os ids de metas cujos cartões a consulta mandou carregar */
    private array $cartoesCarregados = [];

    /** @var list<int> as pastas que o repositório de processos diz estarem em segredo de justiça */
    private array $pastasEmSegredo = [];

    /** @var list<list<int>> as pastas que a consulta perguntou ao repositório de processos */
    private array $pastasPerguntadas = [];

    private int $proximoId = 1;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 7);
        $this->eu = new User();
        DefineId::em($this->eu, 70);
    }

    /** @param list<Tarefa> $daTela o que a tela Minhas Metas mostraria */
    private function caso(array $daTela, bool $modulo = true, bool $aceite = true): ConsultarPrazosEMetasUseCase
    {
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturn($modulo);
        $configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        if ($aceite) {
            $configuracao->liberarPeloMaster();
            $configuracao->atualizar(true, 50, 500, true, $this->eu);
        }
        $configuracoes = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $configuracoes->method('findDoTenant')->willReturn($configuracao);

        $processos = $this->createStub(ProcessoRepository::class);
        $processos->method('idsDasPastasEmSegredoDeJustica')->willReturnCallback(function (array $pastas, Tenant $tenant): array {
            self::assertSame($this->tenant, $tenant, 'o segredo é lido no escritório da sessão');
            $this->pastasPerguntadas[] = $pastas;

            return array_values(array_intersect($this->pastasEmSegredo, $pastas));
        });

        return new ConsultarPrazosEMetasUseCase(
            new GuardaDaConsultaDePrazos(new AcessoAoModuloTarefas($permissoes), $configuracoes),
            $this->lista($daTela),
            $processos,
            new MockClock(new \DateTimeImmutable('2026-11-07 10:30:00')), // no fuso do sistema, como o relógio de produção
        );
    }

    /** @param list<Tarefa> $daTela */
    private function lista(array $daTela): ListaDasMinhasMetas
    {
        $teste = $this;

        return new class ($teste, $daTela) implements ListaDasMinhasMetas {
            /** @param list<Tarefa> $daTela */
            public function __construct(private readonly ConsultarPrazosEMetasUseCaseTest $teste, private readonly array $daTela)
            {
            }

            public function daTela(User $eu, Tenant $tenant, AbaMetas $aba, string $filtroDePrazo): array
            {
                $this->teste->registrarPedidoALista($eu, $tenant, $aba, $filtroDePrazo);

                return $this->daTela;
            }

            public function carregarCartoes(array $metaIds, Tenant $tenant): void
            {
                $this->teste->registrarCartoes($metaIds);
            }
        };
    }

    public function registrarPedidoALista(User $eu, Tenant $tenant, AbaMetas $aba, string $filtro): void
    {
        self::assertSame($this->eu, $eu, 'a lista é SEMPRE a da pessoa da sessão');
        self::assertSame($this->tenant, $tenant);
        $this->pedidosALista[] = ['aba' => $aba, 'filtro' => $filtro];
    }

    /** @param list<int> $ids */
    public function registrarCartoes(array $ids): void
    {
        $this->cartoesCarregados[] = $ids;
    }

    private function meta(string $prazo, ?Tenant $tenant = null, string $titulo = 'Contestação', int $pastaId = 100): Tarefa
    {
        $pasta = new Pasta();
        DefineId::em($pasta, $pastaId);
        $pasta->setNup('0001234-56.2026.8.26.0100');
        $pasta->setNomeCliente('Maria da Silva');
        $criador = new User();
        $criador->setFullName('Carlos Pereira');
        $meta = new Tarefa();
        $id = $this->proximoId++;
        DefineId::em($meta, $id);
        (new \ReflectionProperty(Tarefa::class, 'numero'))->setValue($meta, $id);
        $meta->setTenant($tenant ?? $this->tenant);
        $meta->setPasta($pasta);
        $meta->setTitulo($titulo);
        $meta->setPrazo(new \DateTimeImmutable($prazo));
        $meta->setPrioridade(PrioridadeDaMeta::Alta);
        $meta->setCriadoPor($criador);
        $meta->addResponsavel($this->eu);

        return $meta;
    }

    private function consulta(PeriodoDaConsulta $periodo, AbaMetas $papel = AbaMetas::RESPONSAVEL): ConsultaDePrazosInput
    {
        return new ConsultaDePrazosInput($periodo, $papel);
    }

    /** @return iterable<string, array{PeriodoDaConsulta, string}> */
    public static function periodos(): iterable
    {
        yield 'hoje usa o "Vencem em 7 dias" da tela' => [PeriodoDaConsulta::Hoje, 'proximas'];
        yield 'próximos 7 dias usa o "Vencem em 7 dias"' => [PeriodoDaConsulta::Proximos7Dias, 'proximas'];
        yield 'atrasadas usa o "Vencidas"' => [PeriodoDaConsulta::Atrasadas, 'vencidas'];
    }

    #[DataProvider('periodos')]
    #[TestDox('Pede à tela Minhas Metas o filtro de prazo equivalente ao período ($periodo)')]
    public function testPedeOFiltroDaTela(PeriodoDaConsulta $periodo, string $filtro): void
    {
        $this->caso([])->executar($this->eu, $this->tenant, $this->consulta($periodo, AbaMetas::CRIEI));

        self::assertEquals([['aba' => AbaMetas::CRIEI, 'filtro' => $filtro]], $this->pedidosALista, 'o papel vai como a aba da tela');
    }

    #[TestDox('"Hoje" recorta no dia o que a tela trouxe para os 7 dias')]
    public function testHojeRecortaNoDia(): void
    {
        $hoje = $this->meta('2026-11-07 18:00:00', titulo: 'Hoje');
        $amanha = $this->meta('2026-11-08 09:00:00', titulo: 'Amanhã');

        $saida = $this->caso([$amanha, $hoje])->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Hoje));

        self::assertSame(['Hoje'], array_map(static fn ($m): string => $m->titulo, $saida->metas));
        self::assertSame(0, $saida->metas[0]->dias);
        self::assertSame(1, $saida->total);
        self::assertSame('2026-11-07', $saida->hoje->format('Y-m-d'));
    }

    #[TestDox('A saída leva os campos da linha da tela: número, título, pasta, cliente, prazo, dias, prioridade e quem delegou')]
    public function testCamposDaSaida(): void
    {
        $atrasada = $this->meta('2026-11-04 00:00:00', titulo: 'Recurso');

        $saida = $this->caso([$atrasada])->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Atrasadas));

        $m = $saida->metas[0];
        self::assertSame([1, 'Recurso', '0001234-56.2026.8.26.0100', 'MARIA DA SILVA', -3, 'alta', ['Carlos Pereira']], [$m->numero, $m->titulo, $m->pasta, $m->cliente, $m->dias, $m->prioridade, $m->quem]);
        self::assertSame(PeriodoDaConsulta::Atrasadas, $saida->periodo);
        self::assertSame(AbaMetas::RESPONSAVEL, $saida->papel);
    }

    #[TestDox('Fora da aba "Sou responsável", o "quem" são os responsáveis (até três)')]
    public function testQuemNasOutrasAbas(): void
    {
        $meta = $this->meta('2026-11-09 00:00:00');
        $this->eu->setFullName('Eu Mesma');
        foreach (['Ana', 'Bruno', 'Clara'] as $nome) {
            $meta->addResponsavel((new User())->setFullName($nome));
        }

        $saida = $this->caso([$meta])->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Proximos7Dias, AbaMetas::CRIEI));

        self::assertSame(['Eu Mesma', 'Ana', 'Bruno'], $saida->metas[0]->quem);
    }

    #[TestDox('Meta de outro escritório sai mesmo se a lista a trouxer (guarda explícita, sem depender do TenantFilter)')]
    public function testOutroEscritorioSai(): void
    {
        $outro = new Tenant();
        DefineId::em($outro, 8);

        $saida = $this->caso([$this->meta('2026-11-07', $outro, 'Alheia'), $this->meta('2026-11-07', titulo: 'Minha')])
            ->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Hoje));

        self::assertSame(['Minha'], array_map(static fn ($m): string => $m->titulo, $saida->metas));
        self::assertSame(1, $saida->total);
    }

    #[TestDox('Meta de pasta com processo em segredo de justiça sai e só entra na contagem')]
    public function testSegredoDeJusticaSaiEContado(): void
    {
        $this->pastasEmSegredo = [200];
        $metas = [$this->meta('2026-11-05', titulo: 'Sigilosa', pastaId: 200), $this->meta('2026-11-06', titulo: 'Comum', pastaId: 100)];

        $saida = $this->caso($metas)->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Atrasadas));

        self::assertSame(['Comum'], array_map(static fn ($m): string => $m->titulo, $saida->metas));
        self::assertSame(1, $saida->total);
        self::assertSame(1, $saida->omitidasPorSegredo);
        self::assertSame([[200, 100]], $this->pastasPerguntadas, 'as pastas do período, perguntadas de uma vez');
        self::assertSame([[2]], $this->cartoesCarregados, 'os cartões são só os das metas mostradas');
    }

    #[TestDox('Mostra até 20, pelo prazo (a mais antiga primeiro), e o total conta todas')]
    public function testTetoEOrdem(): void
    {
        $metas = [];
        for ($i = 25; $i >= 1; --$i) {
            $metas[] = $this->meta(sprintf('2026-10-%02d', $i), titulo: 'Meta ' . $i);
        }

        $saida = $this->caso($metas)->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Atrasadas));

        self::assertSame(25, $saida->total);
        self::assertCount(ConsultarPrazosEMetasUseCase::MAXIMO_DE_METAS, $saida->metas);
        self::assertSame('Meta 1', $saida->metas[0]->titulo);
        self::assertSame('Meta 20', $saida->metas[19]->titulo);
    }

    #[TestDox('Sem o módulo Tarefas: recusa antes de ler a lista')]
    public function testSemModuloRecusa(): void
    {
        $caso = $this->caso([$this->meta('2026-11-07')], modulo: false);

        try {
            $caso->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Hoje));
            self::fail('devia recusar');
        } catch (SemAcessoAsMetasNoChatException) {
        }

        self::assertSame([], $this->pedidosALista);
    }

    #[TestDox('Sem o aceite de envio do escritório: recusa antes de ler a lista')]
    public function testSemAceiteRecusa(): void
    {
        $caso = $this->caso([$this->meta('2026-11-07')], aceite: false);

        $this->expectException(SemAcessoAsMetasNoChatException::class);

        $caso->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Hoje));
    }

    #[TestDox('Lista vazia: sem consulta de segredo desnecessária quebrando e com total zero')]
    public function testListaVazia(): void
    {
        $saida = $this->caso([])->executar($this->eu, $this->tenant, $this->consulta(PeriodoDaConsulta::Proximos7Dias));

        self::assertSame(0, $saida->total);
        self::assertSame([], $saida->metas);
        self::assertSame(0, $saida->omitidasPorSegredo);
    }
}
