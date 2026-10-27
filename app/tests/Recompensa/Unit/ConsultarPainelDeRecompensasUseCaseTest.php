<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\IndicadorDeRecompensaOutput;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
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
    /** @var list<array<string, mixed>> */
    private array $contas = [];
    private int $proximaConta = 1000;
    /** @var array<int, int> */
    private array $bonus = [];

    private function executar(): \App\Recompensa\DTO\PainelDeRecompensasOutput
    {
        $indicacoes = $this->createStub(IndicacaoRepository::class);
        $indicacoes->method('indicadoresParaRecompensas')->willReturnCallback(fn (): array => $this->indicadores);
        $indicacoes->method('contasParaRecompensas')->willReturnCallback(fn (): array => $this->contas);
        $programas = $this->createStub(ProgramaDeRecompensaRepository::class);
        $programas->method('buscarUnico')->willReturnCallback(fn (): ?ProgramaDeRecompensa => $this->programa);
        $condicoes = $this->createStub(CondicaoDeRecompensaRepository::class);
        $condicoes->method('todasPorIndicador')->willReturnCallback(fn (): array => $this->condicoes);

        $lancamentos = $this->createStub(LancamentoDeRecompensaRepository::class);
        $lancamentos->method('somaDoBonusPorIndicador')->willReturnCallback(fn (): array => $this->bonus);

        return (new ConsultarPainelDeRecompensasUseCase($indicacoes, $programas, $condicoes, $lancamentos, new MockClock(new \DateTimeImmutable(self::AGORA))))->executar();
    }

    private function indicador(int $id, int $envios = 20, string $nome = 'Ana Indicadora'): void
    {
        $this->indicadores[] = ['user_id' => $id, 'nome' => $nome, 'email' => "ana{$id}@exemplo.com", 'escritorio' => 'Escritório Alfa', 'envios' => $envios];
    }

    private function contasHaDias(int $indicador, int $n, int $dias, bool $comPasta = true): void
    {
        $criada = (new \DateTimeImmutable(self::AGORA))->modify("-{$dias} days")->format('Y-m-d H:i:s');
        for ($i = 0; $i < $n; ++$i) {
            $this->contas[] = $this->conta($indicador, $criada, $comPasta);
        }
    }

    /**
     * @param array<string, mixed> $sinais sobrescreve os sinais do antifraude (a conta nasce limpa)
     *
     * @return array<string, mixed>
     */
    private function conta(int $indicador, string $criada, bool $comPasta = true, array $sinais = []): array
    {
        return $sinais + [
            'indicador_id' => $indicador, 'conta_id' => ++$this->proximaConta, 'conta_criada_em' => $criada, 'tem_pasta' => $comPasta, 'aprovada' => false,
            'escritorios_conta' => [], 'escritorios_indicador' => [], 'cnpjs_conta' => [], 'cnpjs_indicador' => [],
            'dominio_conta' => 'indicado.com.br', 'dominio_indicador' => 'indicador.com.br',
        ];
    }

    /** @param array<string, mixed> $sinais */
    private function contaSuspeitaHaDias(int $indicador, int $dias, array $sinais = ['dominio_conta' => 'banca.adv.br', 'dominio_indicador' => 'banca.adv.br']): void
    {
        $this->contas[] = $this->conta($indicador, (new \DateTimeImmutable(self::AGORA))->modify("-{$dias} days")->format('Y-m-d H:i:s'), true, $sinais);
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
        self::assertTrue($l->liberado);
        self::assertSame('liberado', $l->situacao);
        self::assertNull($l->condicao);
    }

    #[TestDox('9 contas ativas não liberam: fica "em andamento" e o crédito já corre')]
    public function testNaoLiberaAbaixoDoMinimo(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 9, 40);

        $l = $this->linha(1);

        self::assertFalse($l->liberado);
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
        self::assertTrue($com->liberado);
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
        self::assertFalse($l->liberado);
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

        self::assertSame(['userId', 'nome', 'email', 'escritorio', 'envios', 'contas', 'ativos', 'emCarencia', 'minEscritorios', 'valorCentavos', 'saldoCentavos', 'pagoCentavos', 'liberado', 'situacao', 'bloqueado', 'suspeitas', 'motivosDeSuspeita', 'emSeteDias', 'condicao'], $chaves);
    }

    #[TestDox('F2: o saldo soma os lançamentos manuais (crédito e estorno) ao crédito das contas; os KPIs refletem')]
    public function testSomaOsLancamentos(): void
    {
        $this->indicador(1);
        $this->indicador(2);
        $this->contasHaDias(1, 10, 40);
        $this->bonus = [1 => 2500, 2 => 700];

        $p = $this->executar();

        self::assertSame(12500, $this->linha(1)->saldoCentavos, '10 x R$ 10,00 + R$ 25,00');
        self::assertSame(700, $this->linha(2)->saldoCentavos, 'bônus sem nenhuma conta ainda vale');
        self::assertSame(13200, $p->paraArray()['kpis']['aPagarCentavos']);
    }

    #[TestDox('F2: estorno maior que o crédito zera o saldo, nunca fica negativo, e não puxa o A PAGAR dos outros para baixo')]
    public function testSaldoNuncaNegativo(): void
    {
        $this->indicador(1);
        $this->indicador(2);
        $this->contasHaDias(1, 2, 40);
        $this->contasHaDias(2, 3, 40);
        $this->bonus = [1 => -999999];

        $p = $this->executar();

        self::assertSame(0, $this->linha(1)->saldoCentavos);
        self::assertSame(3000, $this->linha(2)->saldoCentavos);
        self::assertSame(3000, $p->paraArray()['kpis']['aPagarCentavos']);
    }

    #[TestDox('F3: conta suspeita não aprovada não conta nem como ativa nem como carência; entra só na contagem de suspeitas com o motivo')]
    public function testSuspeitaNaoConta(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 2, 60);
        $this->contaSuspeitaHaDias(1, 60);
        $this->contaSuspeitaHaDias(1, 3);

        $l = $this->linha(1);

        self::assertSame(4, $l->contas, 'a conta suspeita é conta aberta');
        self::assertSame(2, $l->ativos);
        self::assertSame(0, $l->emCarencia);
        self::assertSame(2, $l->suspeitas);
        self::assertSame(['dominio' => 2], $l->motivosDeSuspeita);
        self::assertSame(2000, $l->saldoCentavos);
        self::assertSame('analise', $l->situacao);
    }

    #[TestDox('F3: conta suspeita aprovada passa a contar (ativa depois da carência)')]
    public function testAprovadaConta(): void
    {
        $this->indicador(1);
        $this->contaSuspeitaHaDias(1, 60, ['dominio_conta' => 'banca.adv.br', 'dominio_indicador' => 'banca.adv.br', 'aprovada' => true]);

        $l = $this->linha(1);

        self::assertSame(1, $l->ativos);
        self::assertSame(0, $l->suspeitas);
        self::assertSame('andamento', $l->situacao);
    }

    #[TestDox('F3: cada sinal vira o seu motivo agregado; domínio de provedor público não é suspeita')]
    public function testMotivosAgregados(): void
    {
        $this->indicador(1);
        $this->contaSuspeitaHaDias(1, 60, ['escritorios_conta' => [5], 'escritorios_indicador' => [5, 6]]);
        $this->contaSuspeitaHaDias(1, 60, ['cnpjs_conta' => ['12345678000199'], 'cnpjs_indicador' => ['12.345.678/0001-99']]);
        $this->contaSuspeitaHaDias(1, 60, ['dominio_conta' => 'gmail.com', 'dominio_indicador' => 'gmail.com']);

        $l = $this->linha(1);

        self::assertSame(2, $l->suspeitas);
        self::assertSame(['escritorio' => 1, 'cnpj' => 1], $l->motivosDeSuspeita);
        self::assertSame(1, $l->ativos, 'a do gmail passa');
    }

    #[TestDox('F3: alerta com as aceitas dos últimos 7 dias >= alerta do programa (suspeitas inclusive); só sinaliza, o crédito segue')]
    public function testAlerta(): void
    {
        $this->indicador(1);
        $this->indicador(2);
        $this->contasHaDias(1, 4, 6);
        $this->contasHaDias(1, 1, 7);
        $this->contasHaDias(2, 5, 6);
        $this->contasHaDias(2, 5, 40);

        self::assertSame('carencia', $this->linha(1)->situacao, '4 em 7 dias (a de 7 dias exatos fica fora) não alerta');
        $dois = $this->linha(2);
        self::assertSame('alerta', $dois->situacao);
        self::assertSame(5, $dois->emSeteDias);
        self::assertSame(5000, $dois->saldoCentavos);
    }

    #[TestDox('F3: situação na ordem do desenho: bloqueado vence análise, que vence alerta, que vence liberado; liberado independe da situação')]
    public function testOrdemDaSituacao(): void
    {
        $this->indicador(1);
        $this->contasHaDias(1, 10, 40);
        $this->contasHaDias(1, 5, 2);
        self::assertSame('alerta', $this->linha(1)->situacao);
        self::assertTrue($this->linha(1)->liberado, 'segue liberado para os KPIs e filtros');

        $this->contaSuspeitaHaDias(1, 40);
        self::assertSame('analise', $this->linha(1)->situacao);

        $condicao = new CondicaoDeRecompensa(Pessoas::usuario(1));
        $condicao->bloquear(new \DateTimeImmutable(self::AGORA));
        $this->condicoes[1] = $condicao;
        $l = $this->linha(1);

        self::assertSame('bloqueado', $l->situacao);
        self::assertTrue($l->bloqueado);
        self::assertNull($l->condicao, 'só o bloqueio: não é condição individual (sem a estrela)');
        self::assertSame(10000, $l->saldoCentavos, 'bloquear não mexe no saldo');
    }

    #[TestDox('F3: indicador apagado sai da lista e dos KPIs; o bloqueado fica')]
    public function testApagadoSai(): void
    {
        $this->indicador(1);
        $this->indicador(2);
        $this->contasHaDias(1, 2, 60);
        $this->contasHaDias(2, 3, 60);
        $apagada = new CondicaoDeRecompensa(Pessoas::usuario(1));
        $apagada->apagar(new \DateTimeImmutable(self::AGORA));
        $bloqueada = new CondicaoDeRecompensa(Pessoas::usuario(2));
        $bloqueada->bloquear(new \DateTimeImmutable(self::AGORA));
        $this->condicoes = [1 => $apagada, 2 => $bloqueada];

        $p = $this->executar();

        self::assertSame([2], array_map(static fn (IndicadorDeRecompensaOutput $i): int => $i->userId, $p->indicadores));
        self::assertSame(['indicadores' => 1, 'contasAbertas' => 3, 'liberados' => 0, 'aPagarCentavos' => 3000, 'jaPagoCentavos' => 0], $p->paraArray()['kpis']);
    }
}
