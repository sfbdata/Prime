<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\LotacaoComVigenciaRepository;
use App\Ponto\UseCase\ApagarLotacoesQueSairamUseCase;
use App\Repository\LotacaoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra de apagar lotação na aba "Lotações" (spec `docs/specs/ponto-desenho-0811.md`, E6): a que saiu da tela e tem
 * feriado ou histórico fica no escritório e é devolvida como recusada; a que saiu sem nada sai da coleção (o
 * `orphanRemoval` a apaga no flush); a que ficou na tela não muda. O flush com o banco está no teste funcional
 * `ApagarLotacaoComHistoricoTest`.
 */
#[CoversClass(ApagarLotacoesQueSairamUseCase::class)]
#[Group('ponto')]
final class ApagarLotacoesQueSairamUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private Lotacao $fica;
    private Lotacao $comHistorico;
    private Lotacao $semNada;

    protected function setUp(): void
    {
        $this->escritorio = $this->comId(new Tenant(), 1);
        $this->fica = $this->lotacao(10, 'MACEIÓ/AL');
        $this->comHistorico = $this->lotacao(20, 'BRASÍLIA/DF');
        $this->semNada = $this->lotacao(30, 'RECIFE/PE');
    }

    #[TestDox('A que saiu da tela com feriado ou histórico fica no escritório e é recusada; a sem nada sai da coleção')]
    public function testComHistoricoFicaESemNadaSai(): void
    {
        // Como o formulário deixa a coleção: intacta (ele não a escreve).
        $recusadas = $this->casoDeUso([20])->executar($this->escritorio, [10]);

        self::assertSame([$this->comHistorico], $recusadas);
        self::assertTrue($this->escritorio->getLotacoes()->contains($this->comHistorico));
        self::assertSame($this->escritorio, $this->comHistorico->getTenant());
        self::assertFalse($this->escritorio->getLotacoes()->contains($this->semNada), 'o orphanRemoval apaga no flush');
        self::assertNull($this->semNada->getTenant());
        self::assertTrue($this->escritorio->getLotacoes()->contains($this->fica));
    }

    #[TestDox('A com histórico que algum caminho já tirou da coleção volta ao escritório')]
    public function testComHistoricoJaTiradaVolta(): void
    {
        $this->escritorio->removeLotacao($this->comHistorico);

        $recusadas = $this->casoDeUso([20])->executar($this->escritorio, [10, 30]);

        self::assertSame([$this->comHistorico], $recusadas);
        self::assertTrue($this->escritorio->getLotacoes()->contains($this->comHistorico));
        self::assertSame($this->escritorio, $this->comHistorico->getTenant(), 'volta ao escritório');
    }

    #[TestDox('A que ficou na tela não muda, mesmo com histórico; sem recusa quando nada com histórico sai')]
    public function testQueFicaNaoMuda(): void
    {
        $recusadas = $this->casoDeUso([10, 20])->executar($this->escritorio, [10, 20, 30]);

        self::assertSame([], $recusadas);
        self::assertCount(3, $this->escritorio->getLotacoes());
    }

    #[TestDox('Sem nenhuma com histórico, todas as que saíram da tela saem')]
    public function testSemHistoricoTodasQueSaemSaem(): void
    {
        $recusadas = $this->casoDeUso([])->executar($this->escritorio, [10]);

        self::assertSame([], $recusadas);
        self::assertSame([$this->fica], array_values($this->escritorio->getLotacoes()->toArray()));
    }

    /** @param list<int> $comHistorico */
    private function casoDeUso(array $comHistorico): ApagarLotacoesQueSairamUseCase
    {
        $lotacoes = $this->createMock(LotacaoRepository::class);
        $lotacoes->method('findBy')->with(['tenant' => $this->escritorio])->willReturn([$this->fica, $this->comHistorico, $this->semNada]);
        $versoes = $this->createMock(LotacaoComVigenciaRepository::class);
        $versoes->method('idsDasLotacoesComHistorico')->with($this->escritorio)->willReturn($comHistorico);

        return new ApagarLotacoesQueSairamUseCase($lotacoes, $versoes);
    }

    private function lotacao(int $id, string $nome): Lotacao
    {
        $lotacao = $this->comId((new Lotacao())->setNome($nome), $id);
        $this->escritorio->addLotacao($lotacao);

        return $lotacao;
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
