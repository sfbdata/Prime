<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\LotacaoComVigencia;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\LotacaoComVigenciaRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\FeriadosDaPessoa;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\UseCase\RegistrarTrocaDeLotacaoUseCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lotação com vigência (spec `docs/specs/ponto-desenho-0811.md`, E6: decisão do Coordenador de 09/11/2026, no molde
 * da jornada), com o banco e os serviços reais. Ana é de Maceió e tem 8 h batidas em dois dias úteis do mês passado; o
 * primeiro é feriado de Maceió, o segundo é feriado de Brasília. Hoje ela passa para Brasília: o mês passado não muda
 * (o feriado de Maceió continua valendo para ela lá, o de Brasília não), e de hoje em diante vale a lotação nova.
 */
#[CoversClass(RegistrarTrocaDeLotacaoUseCase::class)]
#[CoversClass(LotacaoComVigenciaRepository::class)]
#[CoversClass(FeriadosDaPessoa::class)]
#[CoversClass(FolhaPontoBuilder::class)]
#[Group('ponto')]
final class LotacaoComVigenciaTest extends KernelTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private const OITO_HORAS = 480;

    private Tenant $escritorio;
    private Lotacao $maceio;
    private Lotacao $brasilia;
    private User $ana;
    private JornadaColaborador $jornada;
    private \DateTimeImmutable $diaDeMaceio;
    private \DateTimeImmutable $diaDeBrasilia;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio('Escritório Lotação com Vigência');
        $this->maceio = $this->lotacao('MACEIÓ/AL', $this->escritorio);
        $this->brasilia = $this->lotacao('BRASÍLIA/DF', $this->escritorio);
        $this->ana = $this->pessoa('Ana');
        $this->vincular($this->ana, $this->escritorio)->setLotacao($this->maceio);
        $this->em()->flush();
        $this->jornada = $this->jornadaPessoal($this->ana, $this->escritorio, [[[1, 2, 3, 4, 5], '09:00', '12:00', '13:00', '18:00']]);
        [$this->diaDeMaceio, $this->diaDeBrasilia] = self::doisDiasUteisDoMesPassado();
        foreach ([$this->diaDeMaceio, $this->diaDeBrasilia] as $dia) {
            foreach (['entrada' => '09:00', 'repouso' => '12:00', 'retorno' => '13:00', 'saida' => '18:00'] as $tipo => $hora) {
                $this->registrarBatida($this->ana, $this->escritorio, $tipo, new \DateTimeImmutable($dia->format('Y-m-d') . ' ' . $hora));
            }
        }
        $this->feriado('Emancipação de Alagoas', $this->diaDeMaceio, $this->maceio);
        $this->feriado('Aniversário de Brasília', $this->diaDeBrasilia, $this->brasilia);
    }

    #[TestDox('(a) Trocar a lotação hoje não muda o feriado, a folha nem o banco do mês passado')]
    public function testTrocarNaoMudaOPassado(): void
    {
        $saldoAntes = $this->saldoDoMesPassado();
        self::assertSame('Emancipação de Alagoas', $this->linhaDoDia($this->diaDeMaceio)['nomeFeriado'] ?? null);
        self::assertFalse($this->linhaDoDia($this->diaDeBrasilia)['isFeriado'] ?? false);

        $this->trocar($this->ana, $this->escritorio, $this->brasilia);

        self::assertSame('Emancipação de Alagoas', $this->linhaDoDia($this->diaDeMaceio)['nomeFeriado'] ?? null, 'o feriado de Maceió continua valendo lá');
        self::assertFalse($this->linhaDoDia($this->diaDeBrasilia)['isFeriado'] ?? false, 'o de Brasília não vale para trás');
        self::assertSame($saldoAntes, $this->saldoDoMesPassado(), 'o banco do mês passado não muda');
        self::assertSame($this->brasilia->getId(), $this->vinculo($this->ana, $this->escritorio)->getLotacao()?->getId(), 'o vínculo guarda a lotação de hoje');
    }

    #[TestDox('(b) A partir de hoje vale a lotação nova: o feriado de Brasília vale, o de Maceió não')]
    public function testDeHojeEmDianteValeANova(): void
    {
        $hoje = new \DateTimeImmutable('today');
        $deMaceioHoje = $this->feriado('Feriado de Maceió hoje', $hoje, $this->maceio);
        $deBrasiliaHoje = $this->feriado('Feriado de Brasília hoje', $hoje, $this->brasilia);

        $this->trocar($this->ana, $this->escritorio, $this->brasilia);
        $regra = static::getContainer()->get(FeriadosDaPessoa::class);

        self::assertTrue($regra->valePara($deBrasiliaHoje, $this->ana, $hoje));
        self::assertFalse($regra->valePara($deMaceioHoje, $this->ana, $hoje));
        self::assertTrue($regra->valePara($deMaceioHoje, $this->ana, $hoje->modify('-1 day')), 'ontem ela ainda era de Maceió');
        self::assertTrue($regra->valePara($deBrasiliaHoje, $this->ana, $hoje->modify('+30 days')));
        self::assertSame(
            [[null, $this->maceio->getId()], [$hoje->format('Y-m-d'), $this->brasilia->getId()]],
            $this->versoes($this->ana, $this->escritorio),
            'a versão inicial (a lotação de antes, desde sempre) e a de hoje',
        );
    }

    #[TestDox('(c) Trocar duas vezes no mesmo dia guarda uma versão só para o dia; salvar sem trocar não grava nada')]
    public function testDuasTrocasNoMesmoDia(): void
    {
        $recife = $this->lotacao('RECIFE/PE', $this->escritorio);
        $hoje = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $this->trocar($this->ana, $this->escritorio, $this->maceio);
        self::assertSame([], $this->versoes($this->ana, $this->escritorio), 'sem troca, nenhuma versão');

        $this->trocar($this->ana, $this->escritorio, $this->brasilia);
        $this->trocar($this->ana, $this->escritorio, $recife);

        self::assertSame([[null, $this->maceio->getId()], [$hoje, $recife->getId()]], $this->versoes($this->ana, $this->escritorio));
    }

    #[TestDox('(d) Outro escritório isolado: a troca num escritório não grava versão nem muda o feriado no outro')]
    public function testOutroEscritorioIsolado(): void
    {
        $outro = $this->escritorio('Outro escritório');
        $maceioDeLa = $this->lotacao('MACEIÓ/AL', $outro);
        $this->vincular($this->ana, $outro)->setLotacao($maceioDeLa);
        $this->em()->flush();
        $hoje = new \DateTimeImmutable('today');
        $deLa = $this->feriado('Feriado de Maceió lá', $hoje, $maceioDeLa, $outro);

        $this->trocar($this->ana, $this->escritorio, $this->brasilia);

        self::assertSame([], $this->versoes($this->ana, $outro), 'nenhuma versão no outro escritório');
        self::assertSame($maceioDeLa->getId(), $this->vinculo($this->ana, $outro)->getLotacao()?->getId());
        self::assertTrue(static::getContainer()->get(FeriadosDaPessoa::class)->valePara($deLa, $this->ana, $hoje), 'lá ela continua de Maceió');
        self::assertCount(2, $this->versoes($this->ana, $this->escritorio));
        $escritorios = $this->em()->getConnection()->fetchFirstColumn('SELECT DISTINCT tenant_id FROM lotacao_vigencia WHERE user_id = ?', [$this->ana->getId()]);
        self::assertSame([$this->escritorio->getId()], array_map('intval', $escritorios));
    }

    // ----------------------------------------------------------------- apoio

    /** Troca como a edição do vínculo: o formulário escreve a lotação, o caso de uso registra e o flush grava. */
    private function trocar(User $pessoa, Tenant $escritorio, Lotacao $para): void
    {
        $this->em()->clear();
        $vinculo = $this->vinculo($pessoa, $escritorio);
        $vinculo->setLotacao($this->em()->find(Lotacao::class, $para->getId()));
        static::getContainer()->get(RegistrarTrocaDeLotacaoUseCase::class)->executar($vinculo);
        $this->em()->flush();
        $this->em()->clear();
        static::getContainer()->get(FeriadosDaPessoa::class)->reset();   // como no fim do request
    }

    private function vinculo(User $pessoa, Tenant $escritorio): UserTenant
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $pessoa->getId(), 'tenant' => $escritorio->getId()]);
        self::assertInstanceOf(UserTenant::class, $vinculo);

        return $vinculo;
    }

    /** @return list<array{0: ?string, 1: ?int}> [desde, lotação] das versões, a inicial primeiro */
    private function versoes(User $pessoa, Tenant $escritorio): array
    {
        $versoes = static::getContainer()->get(LotacaoComVigenciaRepository::class)
            ->versoesDoVinculo($this->em()->find(User::class, $pessoa->getId()), $this->em()->find(Tenant::class, $escritorio->getId()));
        $lista = array_map(
            static fn (LotacaoComVigencia $v): array => [$v->getValeAPartirDe()?->format('Y-m-d'), $v->getLotacao()?->getId()],
            $versoes,
        );
        usort($lista, static fn (array $a, array $b): int => [$a[0] !== null, $a[0]] <=> [$b[0] !== null, $b[0]]);

        return $lista;
    }

    private function lotacao(string $nome, Tenant $escritorio): Lotacao
    {
        $lotacao = (new Lotacao())->setNome($nome)->setTenant($this->em()->find(Tenant::class, $escritorio->getId()));
        $this->em()->persist($lotacao);
        $this->em()->flush();

        return $lotacao;
    }

    private function feriado(string $nome, \DateTimeImmutable $dia, Lotacao $lotacao, ?Tenant $escritorio = null): Feriado
    {
        $feriado = (new Feriado())->setNome($nome)->setData(\DateTime::createFromImmutable($dia))->setRecorrente(false)
            ->setTenant($this->em()->find(Tenant::class, ($escritorio ?? $this->escritorio)->getId()))
            ->setLotacao($this->em()->find(Lotacao::class, $lotacao->getId()));
        $this->em()->persist($feriado);
        $this->em()->flush();

        return $feriado;
    }

    /** @return array<string, mixed> a linha do dia na folha, com os feriados do escritório (como os chamadores) */
    private function linhaDoDia(\DateTimeImmutable $dia): array
    {
        $linhas = static::getContainer()->get(FolhaPontoBuilder::class)->buildRows(
            $dia,
            $dia->setTime(23, 59, 59),
            array_values(static::getContainer()->get(RegistroPontoRepository::class)->findByUserAndCompetencia($this->ana, $this->escritorio, (int) $dia->format('Y'), (int) $dia->format('n'))),
            true,
            false,
            $this->em()->find(JornadaColaborador::class, $this->jornada->getId()),
            $this->feriadosDoEscritorio(),
            [],
            null,
            $this->diaDeMaceio,
        );

        return $linhas[0];
    }

    private function saldoDoMesPassado(): int
    {
        return static::getContainer()->get(FolhaPontoBuilder::class)->calcularSaldoAteMes(
            $this->em()->find(User::class, $this->ana->getId()),
            (int) $this->diaDeMaceio->format('Y'),
            (int) $this->diaDeMaceio->format('n'),
            $this->feriadosDoEscritorio(),
            null,
            $this->diaDeMaceio,
            $this->em()->find(Tenant::class, $this->escritorio->getId()),
        );
    }

    /** @return list<Feriado> */
    private function feriadosDoEscritorio(): array
    {
        return array_values(static::getContainer()->get(FeriadoRepository::class)->findByTenant($this->em()->find(Tenant::class, $this->escritorio->getId())));
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} os dois primeiros dias úteis do mês passado */
    private static function doisDiasUteisDoMesPassado(): array
    {
        $dias = [];
        for ($dia = new \DateTimeImmutable('first day of last month midnight'); \count($dias) < 2; $dia = $dia->modify('+1 day')) {
            if ((int) $dia->format('N') <= 5) {
                $dias[] = $dia;
            }
        }

        return [$dias[0], $dias[1]];
    }
}
