<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Service\FeriadosDaPessoa;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\UseCase\ListarPendenciasDeRegistroUseCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Feriado por lotação nos consumidores por pessoa, com o banco e os serviços reais (spec
 * `docs/specs/ponto-desenho-0811.md`, E6, P71 com o desvio da E6): Ana é de Maceió, Bruno de Brasília, no mesmo
 * escritório, os dois com 8 h batidas no dia. O feriado de Maceió é feriado na folha e no saldo da Ana (as 8 h viram
 * crédito) e dia comum no do Bruno, também o recorrente cadastrado em outro ano; "Todas as sedes" vale para os dois; o
 * de uma lotação de outro escritório com o mesmo nome não conta. Na lista de pendências, o feriado da lotação tira o
 * dia só de quem é dela.
 */
#[CoversClass(FeriadosDaPessoa::class)]
#[CoversClass(FolhaPontoBuilder::class)]
#[CoversClass(ListarPendenciasDeRegistroUseCase::class)]
#[Group('ponto')]
final class FeriadoPorLotacaoTest extends KernelTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private const DIA = '2026-03-10';   // terça-feira
    private const OITO_HORAS = 480;

    private Tenant $escritorio;
    private Lotacao $maceio;
    private Lotacao $brasilia;
    private User $ana;
    private User $bruno;
    /** @var array<int, JornadaColaborador> id da pessoa => jornada */
    private array $jornadas = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio('Escritório Feriado por Lotação');
        $this->maceio = $this->lotacao('MACEIÓ/AL', $this->escritorio);
        $this->brasilia = $this->lotacao('BRASÍLIA/DF', $this->escritorio);
        $this->ana = $this->colaborador('Ana', $this->maceio);
        $this->bruno = $this->colaborador('Bruno', $this->brasilia);
    }

    #[TestDox('Folha: o feriado de Maceió é feriado para a Ana e dia comum para o Bruno')]
    public function testFolha(): void
    {
        $this->feriado('Emancipação de Alagoas', self::DIA, $this->maceio);

        self::assertSame('Emancipação de Alagoas', $this->linhaDoDia($this->ana)['nomeFeriado'] ?? null);
        self::assertFalse($this->linhaDoDia($this->bruno)['isFeriado'] ?? false);
    }

    #[TestDox('Saldo: as 8 h da Ana no feriado dela viram crédito; as do Bruno são o dia comum')]
    public function testSaldo(): void
    {
        $semFeriado = $this->saldo($this->ana);
        self::assertSame($semFeriado, $this->saldo($this->bruno), 'mesmas batidas, mesma jornada: mesmo saldo');

        $this->feriado('Emancipação de Alagoas', self::DIA, $this->maceio);

        self::assertSame($semFeriado + self::OITO_HORAS, $this->saldo($this->ana));
        self::assertSame($semFeriado, $this->saldo($this->bruno));
    }

    #[TestDox('"Todas as sedes" vale para os dois, na folha e no saldo')]
    public function testTodasAsSedes(): void
    {
        $semFeriado = $this->saldo($this->ana);
        $this->feriado('Padroeira da cidade', self::DIA, null);

        foreach ([$this->ana, $this->bruno] as $pessoa) {
            self::assertSame('Padroeira da cidade', $this->linhaDoDia($pessoa)['nomeFeriado'] ?? null);
            self::assertSame($semFeriado + self::OITO_HORAS, $this->saldo($pessoa));
        }
    }

    #[TestDox('Feriado recorrente de Maceió (cadastrado em outro ano): feriado para a Ana, dia comum para o Bruno')]
    public function testFeriadoRecorrenteDaLotacao(): void
    {
        $semFeriado = $this->saldo($this->ana);
        $this->feriado('Padroeira de Maceió', '2000-03-10', $this->maceio, recorrente: true);

        self::assertSame('Padroeira de Maceió', $this->linhaDoDia($this->ana)['nomeFeriado'] ?? null);
        self::assertSame($semFeriado + self::OITO_HORAS, $this->saldo($this->ana));
        self::assertFalse($this->linhaDoDia($this->bruno)['isFeriado'] ?? false);
        self::assertSame($semFeriado, $this->saldo($this->bruno));
    }

    /**
     * O feriado chega à folha da Ana (a lista vai inteira, como quando a folha recebe feriados de mais de um escritório)
     * e vem de uma lotação de outro escritório com o MESMO nome da dela: a regra casa pelo id da lotação, não pelo nome.
     */
    #[TestDox('Lotação de outro escritório com o mesmo nome da lotação da Ana: o feriado dela não conta para a Ana')]
    public function testLotacaoDeMesmoNomeEmOutroEscritorio(): void
    {
        $semFeriado = $this->saldo($this->ana);
        $outro = $this->escritorio('Outro escritório');
        $this->feriado('Feriado de lá', self::DIA, $this->lotacao('MACEIÓ/AL', $outro), escritorio: $outro);
        $comODeLa = array_merge($this->feriadosDoEscritorio(), static::getContainer()->get(FeriadoRepository::class)->doEscritorio($outro));
        self::assertCount(1, $comODeLa, 'o feriado de lá chega à folha');

        self::assertFalse($this->linhaDoDia($this->ana, $comODeLa)['isFeriado'] ?? false);
        self::assertSame($semFeriado, $this->saldo($this->ana, $comODeLa));
    }

    #[TestDox('Pendências: o feriado de Maceió tira o dia incompleto da Ana e deixa o do Bruno')]
    public function testPendencias(): void
    {
        $dia = $this->diaUtilRecente();
        foreach ([$this->ana, $this->bruno] as $pessoa) {
            $this->registrarBatida($pessoa, $this->escritorio, 'entrada', $dia->setTime(9, 0));
        }
        $this->feriado('Emancipação de Alagoas', $dia->format('Y-m-d'), $this->maceio);

        self::assertSame([], $this->pendenciasDoDia($this->ana, $dia));
        self::assertSame(['saida'], $this->pendenciasDoDia($this->bruno, $dia));
    }

    // ----------------------------------------------------------------- apoio

    private function lotacao(string $nome, Tenant $escritorio): Lotacao
    {
        $lotacao = (new Lotacao())->setNome($nome)->setTenant($escritorio);
        $this->em()->persist($lotacao);
        $this->em()->flush();

        return $lotacao;
    }

    /** Vínculo com a lotação, a jornada de 8 h (seg. a sex.) e as quatro batidas do DIA. */
    private function colaborador(string $nome, Lotacao $lotacao): User
    {
        $pessoa = $this->pessoa($nome);
        $this->vincular($pessoa, $this->escritorio)->setLotacao($lotacao);
        $this->em()->flush();
        $this->jornadas[(int) $pessoa->getId()] = $this->jornadaPessoal($pessoa, $this->escritorio, [[[1, 2, 3, 4, 5], '09:00', '12:00', '13:00', '18:00']]);
        foreach (['entrada' => '09:00', 'repouso' => '12:00', 'retorno' => '13:00', 'saida' => '18:00'] as $tipo => $hora) {
            $this->registrarBatida($pessoa, $this->escritorio, $tipo, new \DateTimeImmutable(self::DIA . ' ' . $hora));
        }

        return $pessoa;
    }

    private function feriado(string $nome, string $dia, ?Lotacao $lotacao, ?Tenant $escritorio = null, bool $recorrente = false): void
    {
        $feriado = (new Feriado())->setNome($nome)->setData(new \DateTime($dia))->setRecorrente($recorrente)
            ->setTenant($escritorio ?? $this->escritorio)->setLotacao($lotacao);
        $this->em()->persist($feriado);
        $this->em()->flush();
    }

    /**
     * @param ?list<Feriado> $feriados nulos, os do escritório (como os chamadores)
     *
     * @return array<string, mixed> a linha do DIA na folha de março
     */
    private function linhaDoDia(User $pessoa, ?array $feriados = null): array
    {
        $dia = new \DateTimeImmutable(self::DIA);
        $linhas = $this->folha()->buildRows(
            $dia,
            $dia->setTime(23, 59, 59),
            $this->batidasDoMes($pessoa),
            true,
            false,
            $this->jornadas[(int) $pessoa->getId()],
            $feriados ?? $this->feriadosDoEscritorio(),
            [],
            null,
            $dia,
        );

        return $linhas[0];
    }

    /** @param ?list<Feriado> $feriados nulos, os do escritório */
    private function saldo(User $pessoa, ?array $feriados = null): int
    {
        return $this->folha()->calcularSaldoAteMes($pessoa, 2026, 3, $feriados ?? $this->feriadosDoEscritorio(), null, new \DateTimeImmutable(self::DIA), $this->escritorio);
    }

    /** @return list<string> os tipos que faltam no dia, nas pendências da pessoa */
    private function pendenciasDoDia(User $pessoa, \DateTimeImmutable $dia): array
    {
        $tipos = [];
        foreach (static::getContainer()->get(ListarPendenciasDeRegistroUseCase::class)->executar($pessoa, $this->escritorio) as $pendencia) {
            if ($pendencia->dia === $dia->format('Y-m-d')) {
                $tipos[] = $pendencia->tipo;
            }
        }

        return $tipos;
    }

    /** @return list<Feriado> */
    private function feriadosDoEscritorio(): array
    {
        return array_values(static::getContainer()->get(FeriadoRepository::class)->findByTenant($this->escritorio));
    }

    /** @return list<\App\Ponto\Entity\RegistroPonto> */
    private function batidasDoMes(User $pessoa): array
    {
        return array_values(static::getContainer()->get(\App\Ponto\Repository\RegistroPontoRepository::class)
            ->findByUserAndCompetencia($pessoa, $this->escritorio, 2026, 3));
    }

    private function folha(): FolhaPontoBuilder
    {
        return static::getContainer()->get(FolhaPontoBuilder::class);
    }

    /** Um dia útil da última semana, antes de hoje (a lista de pendências olha os últimos 30 dias). */
    private function diaUtilRecente(): \DateTimeImmutable
    {
        $dia = new \DateTimeImmutable('today -2 days');
        while ((int) $dia->format('N') >= 6) {
            $dia = $dia->modify('-1 day');
        }

        return $dia;
    }
}
