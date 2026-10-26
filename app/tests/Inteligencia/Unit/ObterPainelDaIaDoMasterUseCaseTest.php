<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\DTO\EscritorioNaIaDoMasterOutput;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\CustoDaIa;
use App\Inteligencia\UseCase\ObterPainelDaIaDoMasterUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObterPainelDaIaDoMasterUseCase::class)]
#[CoversClass(EscritorioNaIaDoMasterOutput::class)]
final class ObterPainelDaIaDoMasterUseCaseTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $linhas
     * @param list<array<string, mixed>> $consumo
     */
    private function sut(ConfiguracaoDaPlataformaDeIa $plataforma, array $linhas, string $chave = '', bool $trava = false, array $consumo = []): ObterPainelDaIaDoMasterUseCase
    {
        $repositorio = $this->createStub(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('obter')->willReturn($plataforma);
        $configuracoes = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $configuracoes->method('listarEscritoriosParaOMaster')->willReturn($linhas);

        $analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $analises->expects($this->once())->method('consumoDosEscritoriosDesde')
            ->with(new \DateTimeImmutable('first day of this month midnight'))
            ->willReturn($consumo);

        return new ObterPainelDaIaDoMasterUseCase($repositorio, $configuracoes, $chave, $trava, $analises, new CustoDaIa('global'));
    }

    #[TestDox('instalação, chave geral, modelo, retenção e escritórios; a chave da API só vira "gravada sim/não"')]
    public function testMontaOPainel(): void
    {
        $plataforma = new ConfiguracaoDaPlataformaDeIa();
        $plataforma->ligarParaTodos(new User(), new \DateTimeImmutable());
        $plataforma->escolherModelo(ModeloDeIa::Opus55, new User(), new \DateTimeImmutable());
        $linhas = [
            ['tenant_id' => 3, 'nome' => 'Alfa', 'liberada' => true, 'habilitada' => true, 'aceite_em' => '2026-10-20 14:00:00', 'modo' => 'adicional', 'orcamento' => 1500],
            ['tenant_id' => 4, 'nome' => 'Beta', 'liberada' => false, 'habilitada' => false, 'aceite_em' => '2026-09-01 10:00:00', 'modo' => null, 'orcamento' => null],
        ];

        $saida = $this->sut($plataforma, $linhas, ' sk-ant-segredo ', true)->executar();

        self::assertTrue($saida->chaveDaApiGravada);
        self::assertTrue($saida->travaDoServidorLigada);
        self::assertTrue($saida->ligadaParaTodos);
        self::assertSame(ModeloDeIa::Opus55, $saida->modelo);
        self::assertSame(12, $saida->mesesDeRetencao);
        self::assertStringNotContainsString('sk-ant', serialize($saida), 'o valor da chave nunca sai do UseCase');
        self::assertCount(2, $saida->escritorios);
        [$alfa, $beta] = $saida->escritorios;
        self::assertSame([3, 'Alfa', true, '2026-10-20', ModoDeCobrancaDaIa::Adicional, 1500], [$alfa->tenantId, $alfa->nome, $alfa->liberada, $alfa->aceiteEm?->format('Y-m-d'), $alfa->modoDeCobranca, $alfa->orcamentoMensalCentavos]);
        self::assertNull($beta->aceiteEm, 'aceite de quem desligou a IA é histórico: a tabela diz "sem aceite"');
        self::assertSame(ModoDeCobrancaDaIa::Incluso, $beta->modoDeCobranca, 'sem linha de configuração vale o padrão');
    }

    #[TestDox('sem chave no ambiente e trava desligada: "não gravada" e "desligada"')]
    public function testInstalacaoVazia(): void
    {
        $saida = $this->sut(new ConfiguracaoDaPlataformaDeIa(), [], '   ')->executar();

        self::assertFalse($saida->chaveDaApiGravada);
        self::assertFalse($saida->travaDoServidorLigada);
        self::assertFalse($saida->ligadaParaTodos);
        self::assertSame(ModeloDeIa::Haiku55, $saida->modelo);
        self::assertSame([], $saida->escritorios);
    }

    #[TestDox('consumo do mês: uma consulta para todos, somada por escritório; selo de orçamento atingido e acima do grátis')]
    public function testConsumoDoMes(): void
    {
        $linhas = [
            ['tenant_id' => 3, 'nome' => 'Alfa', 'liberada' => true, 'habilitada' => true, 'aceite_em' => null, 'modo' => 'incluso', 'orcamento' => 300],
            ['tenant_id' => 4, 'nome' => 'Beta', 'liberada' => true, 'habilitada' => true, 'aceite_em' => null, 'modo' => 'limite_gratis', 'orcamento' => 100],
            ['tenant_id' => 5, 'nome' => 'Gama', 'liberada' => true, 'habilitada' => true, 'aceite_em' => null, 'modo' => 'adicional', 'orcamento' => null],
            ['tenant_id' => 6, 'nome' => 'Delta', 'liberada' => false, 'habilitada' => false, 'aceite_em' => null, 'modo' => null, 'orcamento' => null],
        ];
        $consumo = [
            // Alfa: Haiku US$ 1 + Sonnet US$ 2 = 300 centavos, exatamente o orçamento.
            ['tenantId' => 3, 'modelo' => ModeloDeIa::Haiku55->value, 'analises' => 7, 'tokensEntrada' => 10_000_000, 'tokensSaida' => 0],
            ['tenantId' => 3, 'modelo' => ModeloDeIa::Sonnet55->value, 'analises' => 2, 'tokensEntrada' => 1_000_000, 'tokensSaida' => 0],
            // Beta: 101 centavos, um acima da parte grátis.
            ['tenantId' => 4, 'modelo' => ModeloDeIa::Haiku55->value, 'analises' => 1, 'tokensEntrada' => 10_100_000, 'tokensSaida' => 0],
            // Gama: sem orçamento, gasta à vontade.
            ['tenantId' => 5, 'modelo' => null, 'analises' => 3, 'tokensEntrada' => 0, 'tokensSaida' => 0],
        ];

        [$alfa, $beta, $gama, $delta] = $this->sut(new ConfiguracaoDaPlataformaDeIa(), $linhas, consumo: $consumo)->executar()->escritorios;

        self::assertSame([9, 300, true, false], [$alfa->analisesNoMes, $alfa->gastoNoMesCentavos, $alfa->orcamentoAtingido(), $alfa->acimaDoGratis()]);
        self::assertSame([1, 101, false, true], [$beta->analisesNoMes, $beta->gastoNoMesCentavos, $beta->orcamentoAtingido(), $beta->acimaDoGratis()]);
        self::assertSame([3, 0, false, false], [$gama->analisesNoMes, $gama->gastoNoMesCentavos, $gama->orcamentoAtingido(), $gama->acimaDoGratis()]);
        self::assertSame([0, 0, false, false], [$delta->analisesNoMes, $delta->gastoNoMesCentavos, $delta->orcamentoAtingido(), $delta->acimaDoGratis()], 'sem análise no mês: zero');
    }
}
