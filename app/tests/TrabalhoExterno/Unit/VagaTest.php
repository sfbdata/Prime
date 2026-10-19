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
use App\TrabalhoExterno\Service\CalculadoraDeImportanciaDaVaga;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A vaga e quem pode o quê, como no desenho 06 (spec VS 18.10.26 §4.1). */
#[CoversClass(Vaga::class)]
#[CoversClass(DadosDaVaga::class)]
#[CoversClass(PoliticaDaVaga::class)]
#[CoversClass(CalculadoraDeImportanciaDaVaga::class)]
final class VagaTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-10-19 10:00:00');
    }

    #[TestDox('a vaga da Central nasce aberta; com o período 0 ("até ser preenchida") não tem fim de divulgação')]
    public function testDaCentralNasceAberta(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['periodoDias' => 7]), $this->agora);
        self::assertSame(SituacaoVaga::Aberta, $v->getSituacao());
        self::assertSame('2026-10-26 10:00', $v->getFimDivulgacao()?->format('Y-m-d H:i'));
        self::assertSame('Vaga publicada por Ana', end($v->getHistorico())->getTexto());

        $semFim = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['periodoDias' => 0]), $this->agora);
        self::assertNull($semFim->getFimDivulgacao());
    }

    #[TestDox('aprovar: teto de dias da vaga externa, o tempo padrão da configuração vira oferta se o Master não escolheu o tipo, e o prazo é o fim da divulgação')]
    public function testAprovarAplicaConfiguracao(): void
    {
        $v = Vaga::doExterno(Apoio::tenant(1), Apoio::usuario(1), 'Bia', $this->dados(['periodoDias' => 30]), $this->agora);
        self::assertSame('Pedido de divulgação enviado por Bia', end($v->getHistorico())->getTexto());

        $v->aplicar(AcaoVaga::Aprovar, null, 'Ana', 15, 7, $this->agora);
        self::assertSame(SituacaoVaga::Aberta, $v->getSituacao());
        self::assertSame(7, $v->getPeriodoDias());
        self::assertTrue($v->isOferta(), 'dur 15 min > 0: a vaga sem tipo vira oferta com tempo');
        self::assertSame('2026-10-19 10:15', $v->getExpiraEm()?->format('Y-m-d H:i'));
        self::assertSame('2026-10-26', $v->getInscricoesAte()?->format('Y-m-d'));
        self::assertSame('Aprovada e publicada por Ana', end($v->getHistorico())->getTexto());
    }

    #[TestDox('não aprovar: o motivo é opcional; corrigir e reenviar devolve à aprovação')]
    public function testReprovarECorrigir(): void
    {
        $v = Vaga::doExterno(Apoio::tenant(1), Apoio::usuario(1), 'Bia', $this->dados(), $this->agora);
        $v->aplicar(AcaoVaga::Reprovar, '  ', 'Ana', 0, 0, $this->agora);
        self::assertSame(SituacaoVaga::Reprovada, $v->getSituacao());
        self::assertSame('Não aprovada por Ana', end($v->getHistorico())->getTexto());

        $v->editar($this->dados(), 'Bia', false, false, $this->agora);
        self::assertSame(SituacaoVaga::Pendente, $v->getSituacao());
        self::assertSame('Corrigida e reenviada por Bia', end($v->getHistorico())->getTexto());
    }

    #[TestDox('o tempo vence sem cron; reabrir e ofertar novamente recomeçam o contador')]
    public function testVencimentoEReabertura(): void
    {
        $oferta = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'duracaoDaOferta' => 10]), $this->agora);
        $depois = $this->agora->modify('+11 minutes');
        self::assertSame(SituacaoVaga::Expirada, $oferta->situacaoEm($depois));
        self::assertSame(SituacaoVaga::Aberta, $oferta->getSituacao(), 'ler não grava');

        $oferta->aplicar(AcaoVaga::OfertarNovamente, null, 'Ana', 0, 0, $depois);
        self::assertSame(SituacaoVaga::Aberta, $oferta->getSituacao());
        self::assertSame('Ofertada novamente por 10 min', end($oferta->getHistorico())->getTexto());
        self::assertSame($depois->modify('+10 minutes')->format('H:i'), $oferta->getExpiraEm()?->format('H:i'));
    }

    #[TestDox('ação fora da situação é 409; "Ofertar novamente" não existe para vaga comum')]
    public function testAcaoForaDaSituacao(): void
    {
        $p = new PoliticaDaVaga(new PoliticaDaDemanda());
        $comum = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(), $this->agora);
        self::assertFalse($p->podeExecutar(Apoio::contexto(Perfil::Master), $comum, AcaoVaga::OfertarNovamente));

        $this->expectException(TransicaoInvalida::class);
        $comum->aplicar(AcaoVaga::Aprovar, null, 'Ana', 0, 0, $this->agora);
    }

    #[TestDox('obrigatórios na ordem do desenho; quem não é Master não escolhe oferta nem "até preencher"; e-mail e telefone nascem ocultos')]
    public function testDadosDaVaga(): void
    {
        try {
            DadosDaVaga::deArray(['titulo' => 'X', 'cargo' => 'Advogado(a)'])->exigirCompleta();
            self::fail('sem ofertante');
        } catch (DadosInvalidos $e) {
            self::assertSame('Informe o escritório que está oferecendo a vaga.', $e->getMessage());
        }
        $d = $this->dados(['oferta' => true, 'periodoDias' => 0]);
        $d->limitarParaQuemNaoEMaster(7);
        self::assertFalse($d->oferta);
        self::assertSame(7, $d->periodoDias);
        self::assertSame(['email', 'tel'], $this->dados()->ofertante['ocultos']);
    }

    #[TestDox('política: interno (até a Equipe sem talentos) vê tudo; só quem gere age; o externo vê abertas e as dele, divulga e só edita a dele pendente ou reprovada')]
    public function testPolitica(): void
    {
        $p = new PoliticaDaVaga(new PoliticaDaDemanda());
        $meu = Apoio::tenant(1);
        $minha = Vaga::doExterno($meu, Apoio::usuario(1), 'Bia', $this->dados(), $this->agora);
        $alheia = Vaga::doExterno(Apoio::tenant(2), Apoio::usuario(2), 'Caio', $this->dados(), $this->agora);
        $master = Apoio::contexto(Perfil::Master);
        $solicitante = Apoio::contexto(Perfil::Solicitante, $meu);
        $convidado = Apoio::contexto(Perfil::Convidado, null, null, Apoio::usuario(3));
        $doConvidado = Vaga::doExterno(null, $convidado->usuario, 'Davi', $this->dados(), $this->agora);

        self::assertTrue($p->podeVer($master, $alheia, $this->agora));
        self::assertTrue($p->podeExecutar($master, $alheia, AcaoVaga::Aprovar));
        self::assertTrue($p->podeVer($solicitante, $minha, $this->agora));
        self::assertFalse($p->podeVer($solicitante, $alheia, $this->agora), 'pendente de outro escritório');
        self::assertFalse($p->podeExecutar($solicitante, $minha, AcaoVaga::Encerrar), 'o dono não muda a situação');
        self::assertTrue($p->podeEditar($solicitante, $minha));
        self::assertTrue($p->podeDivulgar($convidado), 'o Convidado também divulga');
        self::assertTrue($p->ehDono($convidado, $doConvidado));
        self::assertFalse($p->podeCriar(new Contexto($master->usuario, $master->tenant, Perfil::Master, $master->configuracao, previa: true)));
    }

    #[TestDox('importância da vaga: oferta ao vivo e prazo curto pesam; vaga não aberta fica no máximo em 1; a escolha manual vence')]
    public function testImportancia(): void
    {
        $calc = new CalculadoraDeImportanciaDaVaga();
        $oferta = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'inscricoesAte' => '2026-10-21', 'remuneracao' => 'R$ 5 mil']), $this->agora);
        self::assertSame(3, $calc->calcular($oferta, null, true, $this->agora)['nivel']);
        self::assertSame(1, $calc->calcular($oferta, null, true, $this->agora->modify('+1 day'))['nivel'], 'a oferta expirou');
        self::assertSame(0, $calc->calcular($oferta, false, true, $this->agora)['nivel']);
    }

    /** @param array<string, mixed> $extra */
    private function dados(array $extra = []): DadosDaVaga
    {
        return DadosDaVaga::deArray($extra + ['titulo' => 'Advogado trabalhista', 'cargo' => 'Advogado(a)', 'ofertante' => ['nome' => 'Escritório']]);
    }
}
