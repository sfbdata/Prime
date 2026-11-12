<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\RegraDeDecisao;
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

    #[TestDox('Maioria absoluta sobre o total de aptos: mais da metade (limite exato não basta)')]
    public function testMaioriaAbsolutaSobreAptos(): void
    {
        $regras = [RegraDeDecisao::Absoluta, BaseDaDecisao::Aptos, 1, 0];

        $passa = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [6, '6'], 'Não' => [1, '1']], [0, '0'], $regras), '10', 10);
        $limite = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [5, '5'], 'Não' => [1, '1']], [0, '0'], $regras), '10', 10);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $passa->situacao);
        self::assertSame(RegraDeDecisao::Absoluta, $passa->criterio);
        self::assertSame(BaseDaDecisao::Aptos, $passa->base);
        self::assertSame(ResultadoDaPauta::CRITERIO_NAO_ATINGIDO, $limite->situacao, '5 de 10 aptos não é mais da metade');
        self::assertFalse($limite->decidido());
    }

    #[TestDox('Dois terços sobre o total de aptos: o limite exato vale')]
    public function testDoisTercos(): void
    {
        $regras = [RegraDeDecisao::DoisTercos, BaseDaDecisao::Aptos, 1, 0];

        $exato = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [6, '6'], 'Não' => [0, '0']], [0, '0'], $regras), '9', 9);
        $abaixo = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [5, '5'], 'Não' => [0, '0']], [0, '0'], $regras), '9', 9);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $exato->situacao, '6 de 9 é exatamente dois terços');
        self::assertSame(ResultadoDaPauta::CRITERIO_NAO_ATINGIDO, $abaixo->situacao);
    }

    #[TestDox('Unanimidade sobre os votos válidos: um voto contrário já impede')]
    public function testUnanimidade(): void
    {
        $regras = [RegraDeDecisao::Unanimidade, BaseDaDecisao::Validos, 1, 0];

        $todos = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [4, '4'], 'Não' => [0, '0']], [2, '2'], $regras), '10', 10);
        $contra = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [4, '4'], 'Não' => [1, '1']], [0, '0'], $regras), '10', 10);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $todos->situacao, 'a abstenção fica fora dos válidos');
        self::assertSame(ResultadoDaPauta::CRITERIO_NAO_ATINGIDO, $contra->situacao);
    }

    #[TestDox('A base "presentes" conta as abstenções; a base "válidos" não')]
    public function testBasePresentesContaAbstencao(): void
    {
        $abstencoes = [4, '4'];
        $presentes = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [4, '4'], 'Não' => [0, '0']], $abstencoes, [RegraDeDecisao::Absoluta, BaseDaDecisao::Presentes, 1, 0]), '10', 10);
        $validos = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [4, '4'], 'Não' => [0, '0']], $abstencoes, [RegraDeDecisao::Absoluta, BaseDaDecisao::Validos, 1, 0]), '10', 10);

        self::assertSame(ResultadoDaPauta::CRITERIO_NAO_ATINGIDO, $presentes->situacao, '4 de 8 presentes não é maioria absoluta');
        self::assertSame(ResultadoDaPauta::DECIDIDO, $validos->situacao, '4 de 4 válidos é');
    }

    #[TestDox('Várias vagas: os N mais votados são eleitos em ordem; critério e base não se aplicam')]
    public function testVariasVagas(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Ana' => [5, '5'], 'Bia' => [3, '3'], 'Caio' => [1, '1']], [0, '0'], [RegraDeDecisao::Unanimidade, BaseDaDecisao::Aptos, 2, 0]);

        $r = $this->apuracao->apurar($pauta, '10', 10, ['unidades' => 6, 'peso' => '6']);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $r->situacao);
        self::assertSame(['Ana', 'Bia'], array_map(static fn ($o) => $o->rotulo, $r->vencedores));
        self::assertSame('Ana', $r->vencedor?->rotulo);
        self::assertTrue($r->decidido());
        self::assertSame(2, $r->vagas);
    }

    #[TestDox('Várias vagas: empate na última vaga não proclama ninguém')]
    public function testEmpateNaUltimaVaga(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Ana' => [5, '5'], 'Bia' => [3, '3'], 'Caio' => [3, '3']], [0, '0'], [RegraDeDecisao::Mais, BaseDaDecisao::Validos, 2, 0]);

        $r = $this->apuracao->apurar($pauta, '10', 10, ['unidades' => 6, 'peso' => '6']);

        self::assertSame(ResultadoDaPauta::EMPATE_NA_ULTIMA_VAGA, $r->situacao);
        self::assertSame([], $r->vencedores);
        self::assertNull($r->vencedor);
        self::assertFalse($r->decidido());
        self::assertTrue($r->empate);
    }

    #[TestDox('Várias vagas: empate entre eleitos, dentro do corte, não impede; no empate vale o número menor primeiro')]
    public function testEmpateDentroDoCorte(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Ana' => [3, '3'], 'Bia' => [3, '3'], 'Caio' => [1, '1']], [0, '0'], [RegraDeDecisao::Mais, BaseDaDecisao::Validos, 2, 0]);

        $r = $this->apuracao->apurar($pauta, '10', 10, ['unidades' => 4, 'peso' => '4']);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $r->situacao);
        self::assertSame(['Ana', 'Bia'], array_map(static fn ($o) => $o->rotulo, $r->vencedores));
    }

    #[TestDox('Várias vagas: com menos candidatos votados que vagas, só os votados são eleitos; sem nenhum voto, nada decidido')]
    public function testMenosVotadosQueVagas(): void
    {
        $regras = [RegraDeDecisao::Mais, BaseDaDecisao::Validos, 3, 0];

        $alguns = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Ana' => [2, '2'], 'Bia' => [1, '1'], 'Caio' => [0, '0']], [0, '0'], $regras), '10', 10, ['unidades' => 2, 'peso' => '2']);
        $nenhum = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Ana' => [0, '0'], 'Bia' => [0, '0'], 'Caio' => [0, '0']], [0, '0'], $regras), '10', 10, ['unidades' => 0, 'peso' => '0']);

        self::assertSame(['Ana', 'Bia'], array_map(static fn ($o) => $o->rotulo, $alguns->vencedores));
        self::assertSame(ResultadoDaPauta::DECIDIDO, $alguns->situacao);
        self::assertSame(ResultadoDaPauta::CRITERIO_NAO_ATINGIDO, $nenhum->situacao);
        self::assertSame([], $nenhum->vencedores);
    }

    #[TestDox('Várias vagas: presentes e votantes vêm das participações, uma por unidade, não da soma das escolhas')]
    public function testPresencaVemDasParticipacoes(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Ana' => [5, '5'], 'Bia' => [4, '4'], 'Caio' => [3, '3']], [0, '0'], [RegraDeDecisao::Mais, BaseDaDecisao::Validos, 2, 50]);

        $r = $this->apuracao->apurar($pauta, '10', 10, ['unidades' => 6, 'peso' => '6']);

        self::assertSame(6, $r->votantes);
        self::assertSame('6.000000', $r->pesoPresente);
        self::assertSame('60.00', $r->quorumPct);
        self::assertSame('12.000000', $r->pesoValido, 'os contadores medem escolhas');
        self::assertTrue($r->quorumOk);
    }

    #[TestDox('Várias vagas: quórum não atingido pelas participações, mesmo com muitas escolhas')]
    public function testQuorumNaoAtingidoComVariasVagas(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Ana' => [4, '4'], 'Bia' => [4, '4'], 'Caio' => [4, '4']], [0, '0'], [RegraDeDecisao::Mais, BaseDaDecisao::Validos, 2, 50]);

        $r = $this->apuracao->apurar($pauta, '10', 10, ['unidades' => 4, 'peso' => '4']);

        self::assertSame(ResultadoDaPauta::QUORUM_NAO_ATINGIDO, $r->situacao);
        self::assertSame([], $r->vencedores);
    }

    #[TestDox('Frações: dois terços sobre aptos em bcmath, sem erro de última casa')]
    public function testDoisTercosComFracoes(): void
    {
        $regras = [RegraDeDecisao::DoisTercos, BaseDaDecisao::Aptos, 1, 0];

        $r = $this->apuracao->apurar($this->pauta(PesoDoVoto::Fracao, 1, ['Sim' => [3, '0.666667'], 'Não' => [0, '0']], [0, '0'], $regras), '1.000000', 10);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $r->situacao, '0,666667 × 3 = 2,000001 ≥ 2');
    }

    #[TestDox('Maioria absoluta com fração: 1 milionésimo acima da metade passa, exatamente a metade não')]
    public function testMaioriaAbsolutaNoLimiteDaFracao(): void
    {
        $regras = [RegraDeDecisao::Absoluta, BaseDaDecisao::Aptos, 1, 0];

        $acima = $this->apuracao->apurar($this->pauta(PesoDoVoto::Fracao, 1, ['Sim' => [1, '0.500001'], 'Não' => [0, '0']], [0, '0'], $regras), '1.000000', 10);
        $metade = $this->apuracao->apurar($this->pauta(PesoDoVoto::Fracao, 1, ['Sim' => [1, '0.500000'], 'Não' => [0, '0']], [0, '0'], $regras), '1.000000', 10);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $acima->situacao);
        self::assertSame(ResultadoDaPauta::CRITERIO_NAO_ATINGIDO, $metade->situacao);
    }

    #[TestDox('Unanimidade sobre o total de aptos: só com todos os aptos votando na opção')]
    public function testUnanimidadeSobreAptos(): void
    {
        $regras = [RegraDeDecisao::Unanimidade, BaseDaDecisao::Aptos, 1, 0];

        $todos = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [5, '5'], 'Não' => [0, '0']], [0, '0'], $regras), '5', 5);
        $falta = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [4, '4'], 'Não' => [0, '0']], [0, '0'], $regras), '5', 5);

        self::assertSame(ResultadoDaPauta::DECIDIDO, $todos->situacao, '5 de 5 aptos é o limite exato');
        self::assertSame(ResultadoDaPauta::CRITERIO_NAO_ATINGIDO, $falta->situacao);
    }

    #[TestDox('Vaga única com critério rígido: empate do 1º com o 2º continua "Empate", mesmo atingindo a base')]
    public function testEmpateComCriterioRigido(): void
    {
        $regras = [RegraDeDecisao::Absoluta, BaseDaDecisao::Validos, 1, 0];

        $r = $this->apuracao->apurar($this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [3, '3'], 'Não' => [3, '3']], [0, '0'], $regras), '10', 10);

        self::assertSame(ResultadoDaPauta::EMPATE, $r->situacao);
        self::assertSame([], $r->vencedores);
    }

    #[TestDox('Vaga única sem a presença das participações segue valendo (contadores): o resultado de antes')]
    public function testVagaUnicaSemPresencaUsaContadores(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Sim' => [4, '4'], 'Não' => [2, '2']], [1, '1']);

        $sem = $this->apuracao->apurar($pauta, '10', 10);
        $com = $this->apuracao->apurar($pauta, '10', 10, ['unidades' => 7, 'peso' => '7']);

        self::assertSame($com->pesoPresente, $sem->pesoPresente);
        self::assertSame($com->votantes, $sem->votantes);
        self::assertSame($com->situacao, $sem->situacao);
    }

    #[TestDox('Várias vagas sem a presença das participações é recusado: somar as escolhas contaria a unidade várias vezes')]
    public function testVariasVagasExigemPresenca(): void
    {
        $pauta = $this->pauta(PesoDoVoto::Unidade, 1, ['Ana' => [2, '2'], 'Bia' => [1, '1']], [0, '0'], [RegraDeDecisao::Mais, BaseDaDecisao::Validos, 2, 0]);

        $this->expectException(\LogicException::class);

        $this->apuracao->apurar($pauta, '10', 10);
    }

    /**
     * Pauta aberta na convocação dada, com as opções numeradas na ordem do array e os contadores gravados.
     *
     * @param array<string, array{int, string}> $opcoes    rótulo => [unidades, peso]
     * @param array{int, string}                $abstencao [unidades, peso]
     * @param array{0?: RegraDeDecisao, 1?: BaseDaDecisao, 2?: int, 3?: int} $regras critério, base, vagas e quórum da 1ª (padrão: as de sempre)
     */
    private function pauta(PesoDoVoto $peso, int $convocacao, array $opcoes, array $abstencao, array $regras = []): PautaDaAssembleia
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', $peso, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        $numero = 0;
        foreach ($opcoes as $rotulo => [$unidades, $pesoDaOpcao]) {
            self::votos(new OpcaoDaPauta($pauta, ++$numero, $rotulo), $unidades, $pesoDaOpcao);
        }
        self::votos(new OpcaoDaPauta($pauta, 0, 'Abstenção', true), ...$abstencao);
        if ([] !== $regras) {
            $pauta->configurar($regras[0] ?? RegraDeDecisao::Mais, $regras[1] ?? BaseDaDecisao::Validos, 1, $regras[2] ?? 1, $regras[3] ?? 50, 0);
        }
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
