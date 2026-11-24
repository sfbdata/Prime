<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Tenant\Tenant;
use App\Master\DTO\ContextoDosVinculos;
use App\Master\DTO\EscritorioParaConvenio;
use App\Master\DTO\MudancasDoVinculo;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Enum\ProvaDoConvenio;
use App\Master\Service\RegraDosVinculosDeConvenio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra dos vínculos de um escritório (spec docs/specs/convenios.md, §5.1, §5.2 e §6.1, PR 3): cria, não duplica,
 * fecha por estado, pela UF "sem convênio", pela validade e por prova divergente depois de 30 dias.
 */
#[CoversClass(RegraDosVinculosDeConvenio::class)]
#[CoversClass(ContextoDosVinculos::class)]
#[CoversClass(MudancasDoVinculo::class)]
#[CoversClass(ConvenioVinculo::class)]
final class RegraDosVinculosDeConvenioTest extends TestCase
{
    private const AGORA = '2026-11-24 02:10:00';

    private Convenio $oab;

    protected function setUp(): void
    {
        $this->oab = $this->convenio(Convenio::CHAVE_OAB, BeneficioDoConvenio::Gratuidade);
    }

    #[TestDox('cria o vínculo OAB de quem cabe: UF válida, OAB válida do criador, chave ligada')]
    public function testCria(): void
    {
        $m = $this->aplicar($this->escritorio(), []);

        self::assertNotNull($m->novo);
        self::assertSame(OrigemDoVinculo::Oab, $m->novo->getOrigem());
        self::assertSame($this->oab, $m->novo->getConvenio());
        self::assertTrue($m->houveMudanca());
    }

    #[TestDox('não duplica: com o vínculo OAB aberto e tudo certo, nada muda')]
    public function testNaoDuplica(): void
    {
        $m = $this->aplicar($this->escritorio(), [$aberto = $this->vinculo($this->oab)]);

        self::assertNull($m->novo);
        self::assertFalse($m->houveMudanca());
        self::assertTrue($aberto->estaAberto());
    }

    #[TestDox('não cria: sem OAB válida, sem estado, em quarentena, chave desligada, UF sem convênio ou OAB fora da validade')]
    public function testNaoCria(): void
    {
        self::assertNull($this->aplicar($this->escritorio(oabValida: false), [])->novo);
        self::assertNull($this->aplicar($this->escritorio(uf: null), [])->novo);
        self::assertNull($this->aplicar($this->escritorio(emQuarentena: true), [])->novo);
        self::assertNull($this->aplicar($this->escritorio(), [], chaveLigada: false)->novo);
        self::assertNull($this->aplicar($this->escritorio(), [], excecoes: ['GO' => $this->sem('GO', '2026-11-01')])->novo);
        $this->oab = $this->convenio(Convenio::CHAVE_OAB, BeneficioDoConvenio::Gratuidade, validoDe: '2026-12-01');
        self::assertNull($this->aplicar($this->escritorio(), [])->novo);
    }

    #[TestDox('UF "sem convênio" com data futura ainda não fecha nem impede')]
    public function testSemComDataFutura(): void
    {
        $m = $this->aplicar($this->escritorio(), [], excecoes: ['GO' => $this->sem('GO', '2026-12-01')]);

        self::assertNotNull($m->novo);
    }

    #[TestDox('fecha por estado: sem estado válido, "estado_mudou"; mudar de um estado com convênio para outro não fecha')]
    public function testFechaPorEstado(): void
    {
        $this->aplicar($this->escritorio(uf: null), [$aberto = $this->vinculo($this->oab)]);
        self::assertSame(MotivoDoFimDoVinculo::EstadoMudou, $aberto->getMotivoFim());

        $this->aplicar($this->escritorio(uf: 'SP'), [$outro = $this->vinculo($this->oab)]);
        self::assertTrue($outro->estaAberto());
    }

    #[TestDox('fecha pela UF "sem convênio" com o motivo do contexto: "uf_desligada" na tarefa, "estado_mudou" no gancho')]
    public function testFechaPelaUf(): void
    {
        $sem = ['GO' => $this->sem('GO', '2026-11-01')];

        $m = $this->aplicar($this->escritorio(), [$aberto = $this->vinculo($this->oab)], excecoes: $sem);
        self::assertSame(MotivoDoFimDoVinculo::UfDesligada, $aberto->getMotivoFim());
        self::assertSame(1, $m->encerrados);
        self::assertNull($m->novo, 'não reabre na mesma volta');

        $this->aplicar($this->escritorio(), [$mudou = $this->vinculo($this->oab)], excecoes: $sem, motivoDaUf: MotivoDoFimDoVinculo::EstadoMudou);
        self::assertSame(MotivoDoFimDoVinculo::EstadoMudou, $mudou->getMotivoFim());
    }

    #[TestDox('OAB divergente: marca a prova pendente, fecha depois de 30 dias, e a prova de volta tira a marca')]
    public function testProvaDivergente(): void
    {
        $aberto = $this->vinculo($this->oab);

        $m = $this->aplicar($this->escritorio(oabValida: false), [$aberto]);
        self::assertSame(1, $m->provasPendentes);
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $aberto->getProvaPendenteDesde());
        self::assertTrue($aberto->estaAberto());

        $m = $this->aplicar($this->escritorio(oabValida: false), [$aberto], agora: '2026-12-23 02:10:00');
        self::assertSame(0, $m->provasPendentes, 'a marca não recomeça');
        self::assertTrue($aberto->estaAberto(), '29 dias: ainda aberto');

        $m = $this->aplicar($this->escritorio(), [$aberto], agora: '2026-12-23 02:10:00');
        self::assertSame(1, $m->provasDeVolta);
        self::assertNull($aberto->getProvaPendenteDesde());

        $this->aplicar($this->escritorio(oabValida: false), [$aberto], agora: '2026-12-24 02:10:00');
        $this->aplicar($this->escritorio(oabValida: false), [$aberto], agora: '2027-01-23 02:10:00');
        self::assertSame(MotivoDoFimDoVinculo::ProvaDivergente, $aberto->getMotivoFim());
    }

    #[TestDox('fecha por validade ("validade") e por convênio desligado ("convenio_desligado"), também fora do OAB')]
    public function testFechaPorValidade(): void
    {
        $vencido = $this->convenio('caarj', BeneficioDoConvenio::EspacoExtra, validoAte: '2026-11-23');
        $m = $this->aplicar($this->escritorio(), [$aberto = $this->vinculo($vencido), $oab = $this->vinculo($this->oab)]);

        self::assertSame(MotivoDoFimDoVinculo::Validade, $aberto->getMotivoFim());
        self::assertTrue($oab->estaAberto());
        self::assertSame(1, $m->encerrados);

        $futuro = $this->convenio('futuro', BeneficioDoConvenio::EspacoExtra, validoDe: '2026-12-01');
        $this->aplicar($this->escritorio(), [$naoComecou = $this->vinculo($futuro)]);
        self::assertSame(MotivoDoFimDoVinculo::ConvenioDesligado, $naoComecou->getMotivoFim());
    }

    #[TestDox('escritório em quarentena: o vínculo aberto segue até a purga')]
    public function testQuarentena(): void
    {
        $m = $this->aplicar($this->escritorio(emQuarentena: true), [$aberto = $this->vinculo($this->oab)]);

        self::assertTrue($aberto->estaAberto());
        self::assertFalse($m->houveMudanca());
    }

    /**
     * @param list<ConvenioVinculo>       $abertos
     * @param array<string, ConvenioDaUf> $excecoes
     */
    private function aplicar(EscritorioParaConvenio $escritorio, array $abertos, bool $chaveLigada = true, array $excecoes = [], MotivoDoFimDoVinculo $motivoDaUf = MotivoDoFimDoVinculo::UfDesligada, string $agora = self::AGORA): MudancasDoVinculo
    {
        $contexto = new ContextoDosVinculos($this->oab, $excecoes, $chaveLigada, new \DateTimeImmutable($agora), $motivoDaUf);

        return (new RegraDosVinculosDeConvenio())->aplicar(new Tenant(), $escritorio, $abertos, $contexto);
    }

    private function escritorio(?string $uf = 'GO', bool $emQuarentena = false, bool $oabValida = true): EscritorioParaConvenio
    {
        return new EscritorioParaConvenio(7, $uf, $emQuarentena, $oabValida);
    }

    private function vinculo(Convenio $convenio): ConvenioVinculo
    {
        return new ConvenioVinculo(new Tenant(), $convenio, OrigemDoVinculo::Oab, new \DateTimeImmutable('2026-11-01'));
    }

    private function sem(string $uf, string $desde): ConvenioDaUf
    {
        return new ConvenioDaUf($uf, ConvenioDaUf::MODO_SEM, 0.0, new \DateTimeImmutable($desde), null, null, new \DateTimeImmutable('2026-11-01'));
    }

    private function convenio(string $chave, BeneficioDoConvenio $beneficio, string $validoDe = '2026-10-26', ?string $validoAte = null): Convenio
    {
        $convenio = new Convenio($chave, 'Convênio ' . $chave, 'OAB', 'oab', AbrangenciaDoConvenio::Nacional, null, ProvaDoConvenio::Oab, $beneficio, 1, 'CONVÊNIO', new \DateTimeImmutable($validoDe), new \DateTimeImmutable('2026-10-01'));
        if ($validoAte !== null) {
            (new \ReflectionProperty(Convenio::class, 'validoAte'))->setValue($convenio, new \DateTimeImmutable($validoAte));
        }

        return $convenio;
    }
}
