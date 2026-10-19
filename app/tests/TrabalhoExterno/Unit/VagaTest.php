<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A vaga e quem pode o quê (spec VS 18.10.26 §4.1). */
#[CoversClass(Vaga::class)]
#[CoversClass(DadosDaVaga::class)]
#[CoversClass(PoliticaDaVaga::class)]
final class VagaTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-10-19 10:00:00');
    }

    #[TestDox('a vaga da Central nasce aberta, com o fim da divulgação contado do período')]
    public function testDaCentralNasceAberta(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), $this->dados(['periodoDias' => 10]), $this->agora);

        self::assertSame(SituacaoVaga::Aberta, $v->getSituacao());
        self::assertSame('2026-10-29 10:00', $v->getFimDivulgacao()?->format('Y-m-d H:i'));
        self::assertTrue($v->ehDaCentral());
    }

    #[TestDox('a do escritório nasce pendente; aprovar abre e conta o período a partir da aprovação')]
    public function testDoEscritorioEsperaAprovacao(): void
    {
        $v = Vaga::doEscritorio(Apoio::tenant(1), Apoio::usuario(1), $this->dados(['periodoDias' => 30]), $this->agora);
        $v->limitarPeriodo(15);
        self::assertSame(SituacaoVaga::Pendente, $v->getSituacao());

        $v->aplicar(AcaoVaga::Aprovar, null, $this->agora->modify('+1 day'));
        self::assertSame(SituacaoVaga::Aberta, $v->getSituacao());
        self::assertSame(15, $v->getPeriodoDias(), 'cortado na divulgação máxima');
        self::assertSame('2026-11-04', $v->getFimDivulgacao()?->format('Y-m-d'));
    }

    #[TestDox('não aprovar exige motivo e grava no histórico')]
    public function testReprovarComMotivo(): void
    {
        $v = Vaga::doEscritorio(Apoio::tenant(1), Apoio::usuario(1), $this->dados(), $this->agora);
        try {
            $v->aplicar(AcaoVaga::Reprovar, ' ', $this->agora);
            self::fail('sem motivo');
        } catch (TransicaoInvalida) {
        }
        $v->aplicar(AcaoVaga::Reprovar, 'Dados incompletos', $this->agora);

        self::assertSame(SituacaoVaga::Reprovada, $v->getSituacao());
        self::assertSame('Vaga não aprovada: Dados incompletos', end($v->getHistorico())->getTexto());
    }

    #[TestDox('o tempo vence sem cron: a divulgação encerra e a oferta expira na leitura, e a próxima escrita grava')]
    public function testVencimentoNaLeitura(): void
    {
        $divulgacao = Vaga::daCentral(Apoio::usuario(1), $this->dados(['periodoDias' => 5]), $this->agora);
        $oferta = Vaga::daCentral(Apoio::usuario(1), $this->dados(['oferta' => true, 'duracaoDaOferta' => 10]), $this->agora);
        $depois = $this->agora->modify('+6 days');

        self::assertSame(SituacaoVaga::Encerrada, $divulgacao->situacaoEm($depois));
        self::assertSame(SituacaoVaga::Aberta, $divulgacao->getSituacao(), 'ler não grava');
        self::assertSame(SituacaoVaga::Expirada, $oferta->situacaoEm($this->agora->modify('+11 minutes')));

        $divulgacao->gravarVencimento($depois);
        self::assertSame(SituacaoVaga::Encerrada, $divulgacao->getSituacao());
        self::assertSame('Período de divulgação de 5 dia(s) encerrado', end($divulgacao->getHistorico())->getTexto());
    }

    #[TestDox('ação fora da situação é 409, inclusive em vaga que venceu e ainda não foi gravada')]
    public function testAcaoForaDaSituacao(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), $this->dados(['periodoDias' => 5]), $this->agora);

        $this->expectException(TransicaoInvalida::class);
        $v->aplicar(AcaoVaga::Pausar, null, $this->agora->modify('+6 days'));
    }

    #[TestDox('título, cargo e o nome de quem oferece são obrigatórios; cargo fora da lista não entra')]
    public function testObrigatorios(): void
    {
        $this->expectException(DadosInvalidos::class);
        DadosDaVaga::deArray(['titulo' => 'Advogado trabalhista', 'cargo' => 'Diretor', 'ofertante' => ['nome' => 'X']])->exigirCompleta();
    }

    #[TestDox('política: a Central vê todas e aprova; o escritório vê as abertas e as dele, e só mexe nas dele; o Convidado não vê')]
    public function testPolitica(): void
    {
        $p = new PoliticaDaVaga(new PoliticaDaDemanda());
        $meu = Apoio::tenant(1);
        $outro = Apoio::tenant(2);
        $minha = Vaga::doEscritorio($meu, Apoio::usuario(1), $this->dados(), $this->agora);
        $alheia = Vaga::doEscritorio($outro, Apoio::usuario(2), $this->dados(), $this->agora);
        $aberta = Vaga::daCentral(Apoio::usuario(3), $this->dados(), $this->agora);
        $master = Apoio::contexto(Perfil::Master);
        $solicitante = Apoio::contexto(Perfil::Solicitante, $meu);

        self::assertTrue($p->podeVer($master, $alheia, $this->agora));
        self::assertTrue($p->podeExecutar($master, $alheia, AcaoVaga::Aprovar));
        self::assertTrue($p->podeVer($solicitante, $minha, $this->agora));
        self::assertTrue($p->podeVer($solicitante, $aberta, $this->agora));
        self::assertFalse($p->podeVer($solicitante, $alheia, $this->agora), 'pendente de outro escritório');
        self::assertFalse($p->podeExecutar($solicitante, $minha, AcaoVaga::Aprovar), 'o escritório não aprova a própria');
        self::assertTrue($p->podeExecutar($solicitante, $minha, AcaoVaga::Encerrar));
        self::assertFalse($p->podeExecutar($solicitante, $aberta, AcaoVaga::Pausar));
        self::assertFalse($p->veAba(Apoio::contexto(Perfil::Convidado)));
        self::assertFalse($p->podeCriar(new Contexto($master->usuario, $master->tenant, Perfil::Master, $master->configuracao, previa: true)));
    }

    /** @param array<string, mixed> $extra */
    private function dados(array $extra = []): DadosDaVaga
    {
        return DadosDaVaga::deArray($extra + ['titulo' => 'Advogado trabalhista', 'cargo' => 'Advogado(a)', 'ofertante' => ['nome' => 'Escritório']]);
    }
}
