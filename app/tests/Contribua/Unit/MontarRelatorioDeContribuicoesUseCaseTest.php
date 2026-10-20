<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\ContribuicoesDaConta;
use App\Contribua\DTO\MesDeContribuicoes;
use App\Contribua\DTO\RelatorioDeContribuicoes;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\UseCase\MontarRelatorioDeContribuicoesUseCase;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Relatório de contribuições do Master (desenho 08, L253): 12 meses completos, confirmado separado do só
 * informado, contas pela maior soma, escritório excluído marcado, e a chave `rel` respeitada.
 */
#[CoversClass(MontarRelatorioDeContribuicoesUseCase::class)]
#[CoversClass(RelatorioDeContribuicoes::class)]
final class MontarRelatorioDeContribuicoesUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-20 15:00:00';

    #[TestDox('Sem apoio nenhum: 12 meses zerados, do mais antigo (nov/25) ao atual (out/26), e o estado vazio')]
    public function testMesesVazios(): void
    {
        $r = $this->useCase($this->apoios([], []), $this->integracao(true))->executar();

        self::assertTrue($r->ligado());
        self::assertTrue($r->semConfirmados());
        self::assertCount(12, $r->meses);
        self::assertSame(['2025-11', 'nov/25'], [$r->meses[0]->mes, $r->meses[0]->rotulo]);
        self::assertSame(['2026-10', 'out/26'], [$r->meses[11]->mes, $r->meses[11]->rotulo]);
        self::assertSame([0], array_values(array_unique(array_map(static fn (MesDeContribuicoes $m): int => $m->confirmadoCentavos + $m->informadoCentavos, $r->meses))));
        self::assertSame(0, $r->totalGeralCentavos);
        self::assertSame(1, $r->maiorMes(), 'a base das barras nunca é zero');
    }

    #[TestDox('A busca por mês vai do 1º dia do mês de 11 meses atrás (inclusive) ao 1º dia do mês seguinte (exclusive)')]
    public function testDesdeOPrimeiroDoPrimeiroMes(): void
    {
        $apoios = $this->createMock(ApoioRepository::class);
        $apoios->expects(self::once())->method('totaisPorMes')
            ->with(
                self::callback(static fn (\DateTimeImmutable $d): bool => '2025-11-01 00:00:00' === $d->format('Y-m-d H:i:s')),
                self::callback(static fn (\DateTimeImmutable $d): bool => '2026-11-01 00:00:00' === $d->format('Y-m-d H:i:s')),
            )
            ->willReturn([]);
        $apoios->method('totaisPorConta')->willReturn([]);

        $this->useCase($apoios, $this->integracao(true))->executar();
    }

    #[TestDox('Confirmado e informado ficam separados; o informado não entra no total geral')]
    public function testConfirmadosVersusInformados(): void
    {
        $meses = [
            ['mes' => '2026-03', 'confirmado' => 5000, 'informado' => 0],
            ['mes' => '2026-10', 'confirmado' => 2000, 'informado' => 3000],
        ];
        $contas = [['escritorio' => 'Silva Advogados', 'excluido' => false, 'total' => 7000, 'quantidade' => 2, 'ultimo' => '2026-10-02 10:00:00']];

        $r = $this->useCase($this->apoios($meses, $contas), $this->integracao(true))->executar();

        $marco = $r->meses[4];
        self::assertSame(['2026-03', 5000, 0], [$marco->mes, $marco->confirmadoCentavos, $marco->informadoCentavos]);
        self::assertSame([2000, 3000], [$r->meses[11]->confirmadoCentavos, $r->meses[11]->informadoCentavos]);
        self::assertSame(7000, $r->confirmadoNosMeses());
        self::assertSame(3000, $r->informadoNosMeses());
        self::assertSame(7000, $r->totalGeralCentavos);
        self::assertSame(5000, $r->maiorMes());
        self::assertFalse($r->semConfirmados());
    }

    #[TestDox('Contas na ordem do banco (a consulta ordena); escritório excluído marcado como tal')]
    public function testOrdenacaoEExcluido(): void
    {
        $contas = [
            ['escritorio' => 'Gama', 'excluido' => true, 'total' => 9000, 'quantidade' => 3, 'ultimo' => '2026-05-03 09:00:00'],
            ['escritorio' => 'Ágora', 'excluido' => false, 'total' => 1000, 'quantidade' => 2, 'ultimo' => '2026-02-11 09:00:00'],
            ['escritorio' => 'Beta', 'excluido' => false, 'total' => 1000, 'quantidade' => 1, 'ultimo' => '2026-01-10 09:00:00'],
        ];

        $r = $this->useCase($this->apoios([], $contas), $this->integracao(true))->executar();

        self::assertSame(['Gama', 'Ágora', 'Beta'], array_map(static fn (ContribuicoesDaConta $c): string => $c->escritorio, $r->contas));
        self::assertTrue($r->contas[0]->excluido);
        self::assertFalse($r->contas[1]->excluido);
        self::assertSame(3, $r->contas[0]->quantidade);
        self::assertSame('2026-05-03', $r->contas[0]->ultimoApoio->format('Y-m-d'));
        self::assertSame(11000, $r->totalGeralCentavos);
    }

    #[TestDox('Chave "rel" desligada: relatório desligado, sem consultar os apoios')]
    public function testChaveDesligada(): void
    {
        $apoios = $this->createMock(ApoioRepository::class);
        $apoios->expects(self::never())->method('totaisPorMes');
        $apoios->expects(self::never())->method('totaisPorConta');

        $r = $this->useCase($apoios, $this->integracao(false))->executar();

        self::assertFalse($r->ligado());
        self::assertSame(RelatorioDeContribuicoes::CHAVE_DESLIGADA, $r->desligado);
        self::assertSame([], $r->meses);
    }

    #[TestDox('Integração bancária nunca salva: relatório desligado, sem consultar os apoios')]
    public function testSemIntegracao(): void
    {
        $apoios = $this->createMock(ApoioRepository::class);
        $apoios->expects(self::never())->method('totaisPorMes');
        $integracoes = $this->createStub(IntegracaoBancariaRepository::class);
        $integracoes->method('atual')->willReturn(new IntegracaoBancaria());

        $r = $this->useCase($apoios, $integracoes)->executar();

        self::assertSame(RelatorioDeContribuicoes::SEM_INTEGRACAO, $r->desligado);
    }

    private function useCase(ApoioRepository $apoios, IntegracaoBancariaRepository $integracoes): MontarRelatorioDeContribuicoesUseCase
    {
        return new MontarRelatorioDeContribuicoesUseCase($apoios, $integracoes, new MockClock(self::AGORA));
    }

    /**
     * @param list<array{mes: string, confirmado: int, informado: int}>                                    $meses
     * @param list<array{escritorio: string, excluido: bool, total: int, quantidade: int, ultimo: string}> $contas
     */
    private function apoios(array $meses, array $contas): ApoioRepository
    {
        $apoios = $this->createStub(ApoioRepository::class);
        $apoios->method('totaisPorMes')->willReturn($meses);
        $apoios->method('totaisPorConta')->willReturn($contas);

        return $apoios;
    }

    /** Integração já salva (com id), com a chave `rel` ligada ou não. */
    private function integracao(bool $relatorio): IntegracaoBancariaRepository
    {
        $integracao = new IntegracaoBancaria();
        $integracao->configurar('sandbox', 'cliente', ['rel' => $relatorio], new User());
        (new \ReflectionProperty(IntegracaoBancaria::class, 'id'))->setValue($integracao, 1);
        $integracoes = $this->createStub(IntegracaoBancariaRepository::class);
        $integracoes->method('atual')->willReturn($integracao);

        return $integracoes;
    }
}
