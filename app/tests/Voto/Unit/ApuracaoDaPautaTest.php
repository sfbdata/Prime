<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Service\ApuracaoDaPauta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regra do resultado (docs/specs/voto-assembleia-desenho.md §5). Os contadores das opções são gravados por reflexão:
 * em produção só mudam por SQL direto (OpcaoDaPautaRepository::somarVoto()).
 */
#[CoversClass(ApuracaoDaPauta::class)]
final class ApuracaoDaPautaTest extends TestCase
{
    private ApuracaoDaPauta $apuracao;

    protected function setUp(): void
    {
        $this->apuracao = new ApuracaoDaPauta();
    }

    #[TestDox('Uma unidade, um voto: Decidido pelo mais votado; abstenção conta no quórum e fica fora dos válidos')]
    public function testDecididoPorUnidade(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [4, '4'], 'Não' => [2, '2']], [1, '1']);

        $r = $this->apuracao->apurar($pauta, '10', 10);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $r->situacao);
        self::assertTrue($r->decidido());
        self::assertSame('Sim', $r->vencedor?->rotulo);
        self::assertSame(1, $r->vencedor->numero);
        self::assertSame('6.000000', $r->pesoValido);
        self::assertSame('7.000000', $r->pesoPresente);
        self::assertSame('10.000000', $r->pesoApto);
        self::assertSame('70.00', $r->quorumPct);
        self::assertSame('50.00', $r->quorumExigido);
        self::assertTrue($r->quorumOk);
        self::assertSame(7, $r->votantes);
        self::assertSame(1, $r->abstencoesUnidades);
        self::assertSame('1.000000', $r->abstencoesPeso);
        self::assertSame(['66.67', '33.33'], array_map(static fn ($o) => $o->percentual, $r->opcoes));
        self::assertFalse($r->empate);
    }

    #[TestDox('Fração ideal: vence o maior peso, não o maior número de unidades; opções por peso decrescente')]
    public function testDecididoPorFracao(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Fracao, 1, ['Sim' => [3, '0.200000'], 'Não' => [1, '0.350000']], [0, '0']);

        $r = $this->apuracao->apurar($pauta, '1.000000', 6);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $r->situacao);
        self::assertSame('Não', $r->vencedor?->rotulo);
        self::assertSame(['Não', 'Sim'], array_map(static fn ($o) => $o->rotulo, $r->opcoes));
        self::assertSame([1, 3], array_map(static fn ($o) => $o->unidades, $r->opcoes));
        self::assertSame(['0.350000', '0.200000'], array_map(static fn ($o) => $o->peso, $r->opcoes));
        self::assertSame(['63.64', '36.36'], array_map(static fn ($o) => $o->percentual, $r->opcoes));
        self::assertSame('55.00', $r->quorumPct);
        self::assertSame(6, $r->unidadesAptas);
    }

    #[TestDox('Quórum não atingido na 1ª convocação: sem vencedor, mesmo com mais votos numa opção')]
    public function testQuorumNaoAtingido(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Fracao, 1, ['Sim' => [2, '0.300000'], 'Não' => [1, '0.100000']], [1, '0.050000']);

        $r = $this->apuracao->apurar($pauta, '1.000000', 10);

        self::assertSame(ResultadoDaPauta::QUORUM_NAO_ATINGIDO, $r->situacao);
        self::assertNull($r->vencedor);
        self::assertFalse($r->quorumOk);
        self::assertSame('45.00', $r->quorumPct);
    }

    #[TestDox('Quórum exatamente no exigido passa; a abstenção completa o quórum')]
    public function testQuorumNoLimiteComAbstencao(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Fracao, 1, ['Sim' => [2, '0.300000'], 'Não' => [1, '0.100000']], [1, '0.100000']);

        $r = $this->apuracao->apurar($pauta, '1.000000', 10);

        self::assertTrue($r->quorumOk);
        self::assertSame('50.00', $r->quorumPct);
        self::assertSame(ResultadoDaPauta::DECIDIDO, $r->situacao);
        self::assertSame('75.00', $r->opcoes[0]->percentual, 'percentual sobre os válidos, sem a abstenção');
    }

    #[TestDox('Frações somadas sem float: 0,7 + 0,1 sobre 1,6 apto dá 50% exatos e o quórum passa')]
    public function testSemErroDeFloat(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Fracao, 1, ['Sim' => [1, '0.700000'], 'Não' => [1, '0.100000']], [0, '0']);

        $r = $this->apuracao->apurar($pauta, '1.600000', 2);

        self::assertSame('0.800000', $r->pesoValido);
        self::assertSame('50.00', $r->quorumPct);
        self::assertTrue($r->quorumOk);
    }

    #[TestDox('Empate: 1º e 2º com o mesmo peso, sem vencedor; no empate de peso a ordem é pelo número')]
    public function testEmpate(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [3, '3'], 'Não' => [3, '3']], [0, '0']);

        $r = $this->apuracao->apurar($pauta, '10', 10);

        self::assertSame(ResultadoDaPauta::EMPATE, $r->situacao);
        self::assertTrue($r->empate);
        self::assertNull($r->vencedor);
        self::assertSame([1, 2], array_map(static fn ($o) => $o->numero, $r->opcoes));
    }

    #[TestDox('Empate por fração, com unidades diferentes')]
    public function testEmpatePorFracao(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Fracao, 2, ['Sim' => [1, '0.250000'], 'Não' => [2, '0.250000']], [0, '0']);

        $r = $this->apuracao->apurar($pauta, '1.000000', 4);

        self::assertSame(ResultadoDaPauta::EMPATE, $r->situacao);
    }

    #[TestDox('Quórum vem antes do empate')]
    public function testQuorumAntesDoEmpate(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [1, '1'], 'Não' => [1, '1']], [0, '0']);

        $r = $this->apuracao->apurar($pauta, '10', 10);

        self::assertSame(ResultadoDaPauta::QUORUM_NAO_ATINGIDO, $r->situacao);
    }

    #[TestDox('Critério não atingido: quórum ok, sem empate, mas o 1º sem peso (só abstenções)')]
    public function testCriterioNaoAtingido(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 2, ['Sim' => [0, '0']], [2, '2']);

        $r = $this->apuracao->apurar($pauta, '10', 10);

        self::assertSame(ResultadoDaPauta::CRITERIO_NAO_ATINGIDO, $r->situacao);
        self::assertTrue($r->quorumOk);
        self::assertNull($r->vencedor);
        self::assertSame(['0.00'], array_map(static fn ($o) => $o->percentual, $r->opcoes));
    }

    #[TestDox('2ª convocação (quórum 0%): decide com qualquer participação')]
    public function testSegundaConvocacao(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 2, ['Sim' => [1, '1'], 'Não' => [0, '0']], [0, '0']);

        $r = $this->apuracao->apurar($pauta, '100', 100);

        self::assertSame('0.00', $r->quorumExigido);
        self::assertSame('1.00', $r->quorumPct);
        self::assertSame(ResultadoDaPauta::DECIDIDO, $r->situacao);
    }

    #[TestDox('Sem peso apto o quórum é 0%: só passa na 2ª convocação')]
    public function testSemPesoApto(): void
    {
        $primeira = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [0, '0'], 'Não' => [0, '0']], [0, '0']), '0', 0);
        $segunda = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 2, ['Sim' => [0, '0'], 'Não' => [0, '0']], [0, '0']), '0', 0);

        self::assertSame('0.00', $primeira->quorumPct);
        self::assertFalse($primeira->quorumOk);
        self::assertSame(ResultadoDaPauta::QUORUM_NAO_ATINGIDO, $primeira->situacao);
        self::assertTrue($segunda->quorumOk);
        self::assertSame(ResultadoDaPauta::EMPATE, $segunda->situacao);
    }

    /**
     * Pauta aberta na convocação dada, com as opções numeradas na ordem do array e os contadores gravados.
     *
     * @param array<string, array{int, string}> $opcoes    rótulo => [unidades, peso]
     * @param array{int, string}                $abstencao [unidades, peso]
     */
    private function pauta(PesoDoVoto $peso, int $convocacao, array $opcoes, array $abstencao): PautaDaAssembleia
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', $peso, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        $numero = 0;
        foreach ($opcoes as $rotulo => [$unidades, $pesoDaOpcao]) {
            self::votos(new OpcaoDaPauta($pauta, ++$numero, $rotulo), $unidades, $pesoDaOpcao);
        }
        self::votos(new OpcaoDaPauta($pauta, 0, 'Abstenção', true), ...$abstencao);
        // Publicada por reflexão: publicar() exige duas opções, e o cenário de uma opção só é o do critério.
        (new \ReflectionObject($assembleia))->getProperty('estado')->setValue($assembleia, EstadoDaAssembleia::Publicada);
        $pauta->abrir('Mesa', $convocacao, null);

        return $pauta;
    }

    private static function votos(OpcaoDaPauta $opcao, int $unidades, string $peso): void
    {
        $reflexao = new \ReflectionObject($opcao);
        $reflexao->getProperty('totalUnidades')->setValue($opcao, $unidades);
        $reflexao->getProperty('totalPeso')->setValue($opcao, $peso);
    }
}
