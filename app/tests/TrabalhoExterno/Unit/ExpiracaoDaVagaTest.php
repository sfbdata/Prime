<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Service\ExpiracaoDaVaga;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** `expDe`, `velha` e `expRec` do desenho 06 de 24/10 (l.1063 e l.1124), cada ramo e as bordas de 30 dias (D4). */
#[CoversClass(ExpiracaoDaVaga::class)]
final class ExpiracaoDaVagaTest extends TestCase
{
    private \DateTimeImmutable $t0;
    private ExpiracaoDaVaga $exp;

    protected function setUp(): void
    {
        $this->t0 = new \DateTimeImmutable('2026-10-19 10:00:00');
        $this->exp = new ExpiracaoDaVaga();
    }

    #[TestDox('ramo 1: a oferta com o tempo vencido expira no próprio `expira`; velha só depois de 30 dias inteiros')]
    public function testOfertaVencida(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'duracaoDaOferta' => 10, 'periodoDias' => 0]), $this->t0);
        $expira = $this->t0->modify('+10 minutes');

        self::assertNull($this->exp->expiradaEm($v, $this->t0->modify('+5 minutes')), 'no tempo: não expirou');
        self::assertEquals($expira, $this->exp->expiradaEm($v, $this->t0->modify('+11 minutes')));
        self::assertTrue($this->exp->recente($v, $this->t0->modify('+11 minutes')));

        $borda = $expira->modify('+30 days');
        self::assertFalse($this->exp->velha($v, $borda), '30 dias exatos: ainda não é velha');
        self::assertTrue($this->exp->recente($v, $borda));
        self::assertTrue($this->exp->velha($v, $borda->modify('+1 second')), 'um segundo depois: velha');
        self::assertFalse($this->exp->recente($v, $borda->modify('+1 second')));
    }

    #[TestDox('ramo 2: o prazo de inscrição vencido expira às 23:59:59 do último dia; no próprio dia ainda não expirou')]
    public function testPrazoVencido(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['periodoDias' => 0, 'inscricoesAte' => '2026-10-20']), $this->t0);

        self::assertNull($this->exp->expiradaEm($v, new \DateTimeImmutable('2026-10-20 23:00:00')), 'último dia: aberta');
        self::assertSame('2026-10-20 23:59:59', $this->exp->expiradaEm($v, new \DateTimeImmutable('2026-10-21 08:00:00'))?->format('Y-m-d H:i:s'));

        self::assertFalse($this->exp->velha($v, new \DateTimeImmutable('2026-11-19 23:59:59')), '30 dias exatos');
        self::assertTrue($this->exp->recente($v, new \DateTimeImmutable('2026-11-19 23:59:59')));
        self::assertTrue($this->exp->velha($v, new \DateTimeImmutable('2026-11-20 00:00:00')));
        self::assertFalse($this->exp->recente($v, new \DateTimeImmutable('2026-11-20 00:00:00')));
    }

    #[TestDox('ramo 2 vale para a pausada (e a preenchida) com o prazo vencido: o externo a vê expirada, como no desenho')]
    public function testPausadaComPrazoVencido(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['periodoDias' => 0, 'inscricoesAte' => '2026-10-20']), $this->t0);
        $v->aplicar(AcaoVaga::Pausar, null, 'Ana', 0, 0, $this->t0);

        self::assertSame('2026-10-20 23:59:59', $this->exp->expiradaEm($v, new \DateTimeImmutable('2026-10-22 08:00:00'))?->format('Y-m-d H:i:s'));
    }

    #[TestDox('ramo 2 não vale para a pendente nem para a reprovada: nunca expira, nunca sai da lista nem entra na do externo')]
    public function testPendenteEReprovadaNaoExpiram(): void
    {
        $agora = new \DateTimeImmutable('2027-01-01 08:00:00');
        $pendente = Vaga::doExterno(Apoio::tenant(1), Apoio::usuario(1), 'Bia', $this->dados(['inscricoesAte' => '2026-10-20']), $this->t0);
        $reprovada = Vaga::doExterno(Apoio::tenant(1), Apoio::usuario(1), 'Bia', $this->dados(['inscricoesAte' => '2026-10-20']), $this->t0);
        $reprovada->aplicar(AcaoVaga::Reprovar, null, 'Ana', 0, 0, $this->t0);

        foreach ([$pendente, $reprovada] as $v) {
            self::assertNull($this->exp->expiradaEm($v, $agora));
            self::assertFalse($this->exp->velha($v, $agora));
            self::assertFalse($this->exp->recente($v, $agora));
        }
    }

    #[TestDox('ramo 3: a encerrada por "Encerrar vaga" expira no `encerradaEm`')]
    public function testEncerradaPelaAcao(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['periodoDias' => 0]), $this->t0);
        $encerrou = $this->t0->modify('+2 days');
        $v->aplicar(AcaoVaga::Encerrar, null, 'Ana', 0, 0, $encerrou);

        self::assertEquals($encerrou, $v->getEncerradaEm());
        self::assertEquals($encerrou, $this->exp->expiradaEm($v, $encerrou->modify('+1 hour')));
        self::assertFalse($this->exp->velha($v, $encerrou->modify('+30 days')));
        self::assertTrue($this->exp->velha($v, $encerrou->modify('+30 days +1 second')));
    }

    #[TestDox('ramo 3: "Finalizar oferta" grava o `encerradaEm`; sem ele (encerrada pelo fim da divulgação) cai no `expira` e depois na criação')]
    public function testEncerradaSemData(): void
    {
        $oferta = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'duracaoDaOferta' => 10, 'periodoDias' => 0]), $this->t0);
        $oferta->aplicar(AcaoVaga::Encerrar, null, 'Ana', 0, 0, $this->t0->modify('+2 minutes'));
        self::assertEquals($this->t0->modify('+2 minutes'), $oferta->getEncerradaEm(), '"Finalizar oferta" é a mesma ação');

        $comum = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['periodoDias' => 7]), $this->t0);
        $depois = $this->t0->modify('+8 days');
        self::assertNull($comum->getEncerradaEm());
        self::assertEquals($comum->getCriadaEm(), $this->exp->expiradaEm($comum, $depois), 'sem encerradaEm nem expira: a criação');
    }

    #[TestDox('aberta no prazo e sem tempo vencido: não expirou')]
    public function testAbertaNaoExpirou(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['periodoDias' => 0, 'inscricoesAte' => '2026-12-01']), $this->t0);

        self::assertNull($this->exp->expiradaEm($v, $this->t0->modify('+3 days')));
        self::assertFalse($this->exp->velha($v, $this->t0->modify('+3 days')));
        self::assertFalse($this->exp->recente($v, $this->t0->modify('+3 days')));
    }

    #[TestDox('`expRec` (l.1063) não confere a situação no ramo da oferta: a oferta preenchida com o tempo vencido entra na lista do externo, sem véu (`expDe` é null)')]
    public function testExpRecDiferenteDeExpDe(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'duracaoDaOferta' => 10, 'periodoDias' => 0, 'qtd' => 1]), $this->t0);
        $v->registrarAceite('Bia', 0, $this->t0->modify('+1 minute'));
        $agora = $this->t0->modify('+1 day');

        self::assertNull($this->exp->expiradaEm($v, $agora));
        self::assertTrue($this->exp->recente($v, $agora));
    }

    private function dados(array $extra = []): DadosDaVaga
    {
        return DadosDaVaga::deArray($extra + ['titulo' => 'Advogado trabalhista', 'cargo' => 'Advogado(a)', 'ofertante' => ['nome' => 'Escritório']]);
    }
}
