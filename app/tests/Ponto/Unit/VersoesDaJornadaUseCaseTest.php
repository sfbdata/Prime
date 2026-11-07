<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\VersaoDaJornadaInput;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Exception\VersaoDaJornadaNaoEncontradaException;
use App\Ponto\Exception\VersaoDaJornadaRecusadaException;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\HistoricoDeJornadas;
use App\Ponto\UseCase\ExcluirVersaoDaJornadaUseCase;
use App\Ponto\UseCase\LimparJornadaPessoalUseCase;
use App\Ponto\UseCase\SalvarVersaoDaJornadaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Spec `docs/specs/ponto-jornada-vigencia.md` §3.4 e §10.2: salvar, excluir e limpar versões da jornada. */
#[CoversClass(SalvarVersaoDaJornadaUseCase::class)]
#[CoversClass(ExcluirVersaoDaJornadaUseCase::class)]
#[CoversClass(LimparJornadaPessoalUseCase::class)]
final class VersoesDaJornadaUseCaseTest extends TestCase
{
    /** @var list<JornadaColaborador> o que o banco teria */
    private array $banco = [];
    /** @var list<JornadaColaborador> */
    private array $removidas = [];
    private int $gravacoes = 0;
    private int $proximoId = 1;
    private JornadaColaboradorRepository $jornadas;
    private User $pessoa;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->pessoa = (new User())->setEmail('ana@teste.com')->setFullName('Ana');
        $this->escritorio = (new Tenant())->setName('Escritório');

        $jornadas = $this->createStub(JornadaColaboradorRepository::class);
        $jornadas->method('historicoDe')->willReturnCallback(fn (): ?HistoricoDeJornadas => HistoricoDeJornadas::das($this->banco));
        $jornadas->method('salvar')->willReturnCallback(function (JornadaColaborador $j): void {
            $this->banco[] = $j;
        });
        $jornadas->method('remover')->willReturnCallback(function (JornadaColaborador $j): void {
            $this->removidas[] = $j;
            $this->banco = array_values(array_filter($this->banco, static fn (JornadaColaborador $v): bool => $v !== $j));
        });
        $jornadas->method('gravar')->willReturnCallback(function (): void {
            ++$this->gravacoes;
            foreach ($this->banco as $versao) {
                if ($versao->getId() === null) {
                    (new \ReflectionProperty(JornadaColaborador::class, 'id'))->setValue($versao, $this->proximoId++);
                }
            }
        });
        $this->jornadas = $jornadas;
    }

    #[TestDox('A primeira jornada criada pela tela começa na data e não cobre os dias anteriores')]
    public function testPrimeiraVersao(): void
    {
        $versao = $this->salvar('2026-12-01', 2640, [[[1, 2, 3, 4, 5], 528]]);

        self::assertSame([$versao], $this->banco);
        self::assertSame('2026-12-01', $versao->getValeAPartirDe()->format('Y-m-d'));
        self::assertSame(2640, $versao->getCargaHorariaSemanal());
        self::assertCount(1, $versao->getBlocos());
        self::assertFalse($versao->cobreDiasAnteriores());
        self::assertSame($this->pessoa, $versao->getUser());
        self::assertSame($this->escritorio, $versao->getTenant());
        self::assertSame(1, $this->gravacoes);
    }

    #[TestDox('Data nova cria outra versão e a anterior fica como estava; o alerta vale para todas')]
    public function testDataNovaCriaVersao(): void
    {
        $antiga = $this->migrada('2026-04-01', 2400, [[[1, 2, 3, 4, 5], 480]]);
        $antiga->setEntrada1('07:30');

        $nova = $this->salvar('2026-05-01', 2640, [[[1, 2, 3, 4], 540], [[5], 480]], alerta: false);

        self::assertNotSame($antiga, $nova);
        self::assertCount(2, $this->banco);
        self::assertSame(2400, $antiga->getCargaHorariaSemanal());
        self::assertSame(480, $antiga->getBlocos()->first()->getMinutosBloco());
        self::assertFalse($nova->cobreDiasAnteriores());
        self::assertSame('07:30', $nova->getEntrada1(), 'a versão nova leva os campos legados da que valia na data');
        self::assertFalse($antiga->isAlertaHabilitado());
        self::assertFalse($nova->isAlertaHabilitado());
    }

    #[TestDox('Nova versão na data de uma que já existe atualiza aquela')]
    public function testMesmaDataAtualiza(): void
    {
        $existente = $this->migrada('2026-04-01', 2400, [[[1, 2, 3, 4, 5], 480]]);

        $gravada = $this->salvar('2026-04-01', 2640, [[[1, 2, 3, 4], 540], [[5], 480]]);

        self::assertSame($existente, $gravada);
        self::assertCount(1, $this->banco);
        self::assertCount(2, $existente->getBlocos());
        self::assertTrue($existente->cobreDiasAnteriores(), 'editar não tira a marca');
    }

    #[TestDox('Editar uma versão pelo id pode mudar a data dela')]
    public function testEditarMudaAData(): void
    {
        $versao = $this->migrada('2026-04-01', 2640, [[[1, 2, 3, 4], 540], [[5], 480]]);

        $this->salvar('2026-04-22', 2640, [[[1, 2, 3, 4], 540], [[5], 480]], versaoId: $versao->getId());

        self::assertSame('2026-04-22', $versao->getValeAPartirDe()->format('Y-m-d'));
        self::assertCount(1, $this->banco);
    }

    #[TestDox('Editar para a data de outra versão é recusado')]
    public function testEditarParaDataDeOutraRecusa(): void
    {
        $this->migrada('2026-04-01', 2400, [[[1, 2, 3, 4, 5], 480]]);
        $segunda = $this->salvar('2026-05-01', 2640, [[[1, 2, 3, 4], 540], [[5], 480]]);

        $this->expectException(VersaoDaJornadaRecusadaException::class);
        $this->expectExceptionMessage('Já existe uma versão da jornada que começa em 01/04/2026.');
        $this->salvar('2026-04-01', 2640, [[[1, 2, 3, 4], 540], [[5], 480]], versaoId: $segunda->getId());
    }

    #[TestDox('Versão que não é da pessoa naquele escritório não é encontrada')]
    public function testVersaoDeOutraPessoa(): void
    {
        $this->migrada('2026-04-01', 2400, [[[1, 2, 3, 4, 5], 480]]);

        $this->expectException(VersaoDaJornadaNaoEncontradaException::class);
        $this->salvar('2026-04-01', 2400, [[[1, 2, 3, 4, 5], 480]], versaoId: 999);
    }

    #[TestDox('Excluir passa os dias para a vizinha e a marca de cobrir para trás para a mais antiga que sobra')]
    public function testExcluir(): void
    {
        $migrada = $this->migrada('2026-04-01', 2400, [[[1, 2, 3, 4, 5], 480]]);
        $nova = $this->salvar('2026-05-01', 2640, [[[1, 2, 3, 4], 540], [[5], 480]]);

        (new ExcluirVersaoDaJornadaUseCase($this->jornadas))->executar($this->pessoa, $this->escritorio, (int) $migrada->getId());

        self::assertSame([$nova], $this->banco);
        self::assertTrue($nova->cobreDiasAnteriores());
    }

    #[TestDox('A única versão não sai pelo excluir')]
    public function testExcluirAUnicaRecusa(): void
    {
        $unica = $this->migrada('2026-04-01', 2400, [[[1, 2, 3, 4, 5], 480]]);

        $this->expectException(VersaoDaJornadaRecusadaException::class);
        (new ExcluirVersaoDaJornadaUseCase($this->jornadas))->executar($this->pessoa, $this->escritorio, (int) $unica->getId());
    }

    #[TestDox('Limpar com jornada de antes das versões deixa uma versão sem blocos cobrindo todos os dias')]
    public function testLimparComJornadaAntiga(): void
    {
        $migrada = $this->migrada('2026-04-01', 2400, [[[1, 2, 3, 4, 5], 480]]);
        $nova = $this->salvar('2026-05-01', 2640, [[[1, 2, 3, 4], 540], [[5], 480]]);

        (new LimparJornadaPessoalUseCase($this->jornadas))->executar($this->pessoa, $this->escritorio);

        self::assertSame([$migrada], $this->banco);
        self::assertSame([$nova], $this->removidas);
        self::assertCount(0, $migrada->getBlocos());
        self::assertNull($migrada->getCargaHorariaSemanal());
        self::assertTrue($migrada->cobreDiasAnteriores());
    }

    #[TestDox('Limpar com versões só da tela tira todas as linhas, como antes de a jornada existir')]
    public function testLimparSoComVersoesDaTela(): void
    {
        $this->salvar('2026-12-01', 2640, [[[1, 2, 3, 4, 5], 528]]);

        (new LimparJornadaPessoalUseCase($this->jornadas))->executar($this->pessoa, $this->escritorio);

        self::assertSame([], $this->banco);
    }

    /** @param list<array{0: list<int>, 1: int}> $blocos */
    private function salvar(string $data, int $carga, array $blocos, bool $alerta = true, ?int $versaoId = null): JornadaColaborador
    {
        return (new SalvarVersaoDaJornadaUseCase($this->jornadas))->executar(
            $this->pessoa,
            $this->escritorio,
            new VersaoDaJornadaInput(new \DateTimeImmutable($data), $carga, $alerta, $this->blocos($blocos), $versaoId),
        );
    }

    /**
     * Uma jornada de antes das versões, já gravada.
     *
     * @param list<array{0: list<int>, 1: int}> $blocos
     */
    private function migrada(string $data, int $carga, array $blocos): JornadaColaborador
    {
        $jornada = (new JornadaColaborador())
            ->setUser($this->pessoa)
            ->setTenant($this->escritorio)
            ->setValeAPartirDe(new \DateTimeImmutable($data))
            ->setCargaHorariaSemanal($carga)
            ->setCobreDiasAnteriores(true);
        foreach ($this->blocos($blocos) as $bloco) {
            $jornada->addBloco($bloco);
        }
        (new \ReflectionProperty(JornadaColaborador::class, 'id'))->setValue($jornada, $this->proximoId++);
        $this->banco[] = $jornada;

        return $jornada;
    }

    /**
     * @param list<array{0: list<int>, 1: int}> $blocos
     *
     * @return list<BlocoJornadaColaborador>
     */
    private function blocos(array $blocos): array
    {
        return array_map(
            static fn (array $b): BlocoJornadaColaborador => (new BlocoJornadaColaborador())
                ->setDiasSemana($b[0])
                ->setEntrada('08:00')
                ->setSaida('18:00')
                ->setMinutosBloco($b[1]),
            $blocos,
        );
    }
}
