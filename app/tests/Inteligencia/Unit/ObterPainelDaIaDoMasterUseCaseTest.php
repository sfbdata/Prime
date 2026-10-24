<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\DTO\EscritorioNaIaDoMasterOutput;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\UseCase\ObterPainelDaIaDoMasterUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObterPainelDaIaDoMasterUseCase::class)]
#[CoversClass(EscritorioNaIaDoMasterOutput::class)]
final class ObterPainelDaIaDoMasterUseCaseTest extends TestCase
{
    /** @param list<array<string, mixed>> $linhas */
    private function sut(ConfiguracaoDaPlataformaDeIa $plataforma, array $linhas, string $chave = '', bool $trava = false): ObterPainelDaIaDoMasterUseCase
    {
        $repositorio = $this->createStub(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('obter')->willReturn($plataforma);
        $configuracoes = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $configuracoes->method('listarEscritoriosParaOMaster')->willReturn($linhas);

        return new ObterPainelDaIaDoMasterUseCase($repositorio, $configuracoes, $chave, $trava);
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
}
