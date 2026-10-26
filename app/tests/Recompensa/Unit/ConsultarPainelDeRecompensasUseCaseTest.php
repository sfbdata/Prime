<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\IndicadorDeRecompensaOutput;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use App\Recompensa\UseCase\ConsultarPainelDeRecompensasUseCase;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ConsultarPainelDeRecompensasUseCase::class)]
final class ConsultarPainelDeRecompensasUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-26 12:00:00';

    private ?ProgramaDeRecompensa $programa = null;
    /** @var array<int, CondicaoDeRecompensa> */
    private array $condicoes = [];
    /** @var list<array{user_id: int, nome: string, email: string, escritorio: ?string, envios: int}> */
    private array $indicadores = [];
    /** @var list<array{indicador_id: int, conta_criada_em: string, tem_pasta: bool}> */
    private array $contas = [];

    private function executar(): \App\Recompensa\DTO\PainelDeRecompensasOutput
    {
        $indicacoes = $this->createStub(IndicacaoRepository::class);
        $indicacoes->method('indicadoresParaRecompensas')->willReturnCallback(fn (): array => $this->indicadores);
        $indicacoes->method('contasParaRecompensas')->willReturnCallback(fn (): array => $this->contas);
        $programas = $this->createStub(ProgramaDeRecompensaRepository::class);
        $programas->method('buscarUnico')->willReturnCallback(fn (): ?ProgramaDeRecompensa => $this->programa);
        $condicoes = $this->createStub(CondicaoDeRecompensaRepository::class);
        $condicoes->method('todasPorIndicador')->willReturnCallback(fn (): array => $this->condicoes);

        return (new ConsultarPainelDeRecompensasUseCase($indicacoes, $programas, $condicoes, new MockClock(new \DateTimeImmutable(self::AGORA))))->executar();
    }

    private function indicador(int $id, int $envios = 20, string $nome = 'Ana Indicadora'): void
    {
        $this->indicadores[] = ['user_id' => $id, 'nome' => $nome, 'email' => "ana{$id}@exemplo.com", 'escritorio' => 'Escritório Alfa', 'envios' => $envios];
    }

    private function contasHaDias(int $indicador, int $n, int $dias, bool $comPasta = true): void
    {
        $criada = (new \DateTimeImmutable(self::AGORA))->modify("-{$dias} days")->format('Y-m-d H:i:s');
        for ($i = 0; $i < $n; ++$i) {
            $this->contas[] = ['indicador_id' => $indicador, 'conta_criada_em' => $criada, 'tem_pasta' => $comPasta];
        }
    }

    private function linha(int $id): IndicadorDeRecompensaOutput
    {
        foreach ($this->executar()->indicadores as $i) {
            if ($i->userId === $id) {
                return $i;
            }
        }
        self::fail('indicador ausente');
    }

    #[TestDox('sem a linha do programa valem os padrões; sem indicação não há indicador, e os KPIs ficam zerados')]
    public function testVazio(): void
    {
        $p = $this->executar();

        self::assertTrue($p->ativo);
        self::assertSame(1000, $p->valorCentavos);
        self::assertSame([], $p->indicadores);
        self::assertSame(['indicadores' => 0, 'contasAbertas' => 0, 'liberados' => 0, 'aPagarCentavos' => 0, 'jaPagoCentavos' => 0], $p->paraArray()['kpis']);
    }

    #[TestDox('libera com o mínimo de contas ativas: 10 contas com 40 dias e pasta liberam e rendem 10 x R$ 10,00')]
    public function testLiberadoPeloMinimo(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 10, 40);

        $l = $this->linha(1);

        self::assertSame(10, $l->contas);
        self::assertSame(10, $l->ativos);
        self::assertSame(10000, $l->saldoCentavos);
        self::assertSame(0, $l->pagoCentavos);
        self::assertTrue($l->liberado());
        self::assertSame('liberado', $l->situacao);
        self::assertNull($l->condicao);
    }

    #[TestDox('9 contas ativas não liberam: fica "em andamento" e o crédito já corre')]
    public function testNaoLiberaAbaixoDoMinimo(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 9, 40);

        $l = $this->linha(1);

        self::assertFalse($l->liberado());
        self::assertSame('andamento', $l->situacao);
        self::assertSame(9000, $l->saldoCentavos);
    }

    #[TestDox('carência: conta mais nova que 30 dias não conta; com 30 dias exatos conta; situação "em carência" com N')]
    public function testCarencia(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 2, 29);
        $this->contasHaDias(1, 1, 30);

        $l = $this->linha(1);

        self::assertSame(3, $l->contas);
        self::assertSame(1, $l->ativos);
        self::assertSame(2, $l->emCarencia);
        self::assertSame('carencia', $l->situacao);
        self::assertSame(1000, $l->saldoCentavos);
    }

    #[TestDox('exige pasta: conta sem pasta no escritório não é ativa nem fica em carência; desligando a regra, passa a contar')]
    public function testExigePasta(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 4, 60, false);
        $this->contasHaDias(1, 1, 60, true);

        self::assertSame(1, $this->linha(1)->ativos);

        $this->programa = new ProgramaDeRecompensa();
        $this->programa->alterarRegras(true, 1000, 10, 30, 5, false);

        self::assertSame(5, $this->linha(1)->ativos);
    }

    #[TestDox('conta sem pasta ainda em carência fica em carência (a carência vem antes da pasta)')]
    public function testCarenciaAntesDaPasta(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 2, 5, false);

        $l = $this->linha(1);

        self::assertSame(2, $l->emCarencia);
        self::assertSame(0, $l->ativos);
    }

    #[TestDox('condição individual sobrepõe a regra geral campo a campo e não muda quando a geral muda')]
    public function testCondicaoIndividual(): void
    {
        $this->indicador(1);
        $this->indicador(2);
        $this->contasHaDias(1, 3, 10);
        $this->contasHaDias(2, 3, 10);
        $condicao = new CondicaoDeRecompensa(Pessoas::usuario(1));
        $condicao->definir(2500, 3, 7, true, 200, 'Convênio');
        $this->condicoes[1] = $condicao;

        $com = $this->linha(1);
        $sem = $this->linha(2);

        self::assertSame(3, $com->ativos, 'carência individual de 7 dias');
        self::assertSame(7500, $com->saldoCentavos, '3 x R$ 25,00');
        self::assertSame(3, $com->minEscritorios);
        self::assertTrue($com->liberado());
        self::assertSame(['valorCentavos' => 2500, 'minEscritorios' => 3, 'carenciaDias' => 7, 'recorrente' => true, 'mensalCentavos' => 200, 'motivo' => 'Convênio'], $com->condicao?->paraArray());
        self::assertSame(0, $sem->ativos, 'a regra geral (30 dias) segue valendo para quem não tem condição');
        self::assertSame(3, $sem->emCarencia);

        $this->programa = new ProgramaDeRecompensa();
        $this->programa->alterarRegras(true, 5000, 1, 0, 5, true);

        self::assertSame(7500, $this->linha(1)->saldoCentavos, 'a condição não muda com a regra geral');
        self::assertSame(15000, $this->linha(2)->saldoCentavos);
    }

    #[TestDox('campo vazio da condição usa a geral: só o valor próprio, o resto vem do programa')]
    public function testCondicaoParcial(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 2, 60);
        $condicao = new CondicaoDeRecompensa(Pessoas::usuario(1));
        $condicao->definir(3000, null, null, false, null, null);
        $this->condicoes[1] = $condicao;

        $l = $this->linha(1);

        self::assertSame(10, $l->minEscritorios);
        self::assertSame(6000, $l->saldoCentavos);
        self::assertFalse($l->liberado());
    }

    #[TestDox('KPIs somam a lista e a ordem é saldo, depois ativos, depois o id')]
    public function testKpisEOrdem(): void
    {
        $this->indicador(1, nome: 'Pouca');
        $this->indicador(2, nome: 'Muita');
        $this->indicador(3, nome: 'Sem conta', envios: 4);
        $this->contasHaDias(1, 2, 60);
        $this->contasHaDias(2, 11, 60);

        $p = $this->executar();

        self::assertSame([2, 1, 3], array_map(static fn (IndicadorDeRecompensaOutput $i): int => $i->userId, $p->indicadores));
        self::assertSame(['indicadores' => 3, 'contasAbertas' => 13, 'liberados' => 1, 'aPagarCentavos' => 13000, 'jaPagoCentavos' => 0], $p->paraArray()['kpis']);
        self::assertSame(0, $p->comCondicaoIndividual());
    }

    #[TestDox('a saída não traz nome nem e-mail de indicado: só os campos do indicador')]
    public function testSemDadoDeIndicado(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 1, 60);

        $chaves = array_keys($this->executar()->paraArray()['indicadores'][0]);

        self::assertSame(['userId', 'nome', 'email', 'escritorio', 'envios', 'contas', 'ativos', 'emCarencia', 'minEscritorios', 'valorCentavos', 'saldoCentavos', 'pagoCentavos', 'liberado', 'situacao', 'condicao'], $chaves);
    }
}
