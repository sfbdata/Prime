<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\DTO\ResultadoDaCandidatura;
use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Enum\StatusCandidatura;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Service\ApresentadorDeCandidatura;
use App\TrabalhoExterno\Service\CalculadoraDeImportanciaDaVaga;
use App\TrabalhoExterno\Service\CalculadoraDeImportanciaDoCurriculo;
use App\TrabalhoExterno\Service\CompatibilidadeDaVaga;
use App\TrabalhoExterno\Service\EtapasDaCandidatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A candidatura, o aceite da oferta por rodada, a linha do tempo, a compatibilidade e a importância (spec VS 18.10.26 §4.6). */
#[CoversClass(Candidatura::class)]
#[CoversClass(Vaga::class)]
#[CoversClass(EtapasDaCandidatura::class)]
#[CoversClass(CompatibilidadeDaVaga::class)]
#[CoversClass(CalculadoraDeImportanciaDaVaga::class)]
#[CoversClass(CalculadoraDeImportanciaDoCurriculo::class)]
#[CoversClass(ApresentadorDeCandidatura::class)]
#[CoversClass(ResultadoDaCandidatura::class)]
final class CandidaturaTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-10-19 10:00:00');
    }

    #[TestDox('candidatar entra como Recebida; enviar ao escritório vira Em análise uma vez só; o aceite em vaga com dono nasce Aprovado e já enviado')]
    public function testCandidaturaEAceite(): void
    {
        $c = Candidatura::candidatar($this->vagaDoExterno(), $this->curriculo(), $this->agora);
        self::assertSame(StatusCandidatura::Recebida, $c->getStatus());
        self::assertTrue($c->enviarAoEscritorio($this->agora));
        self::assertFalse($c->enviarAoEscritorio($this->agora), 'sem envio repetido');
        self::assertSame(StatusCandidatura::Analise, $c->getStatus());
        self::assertSame(['analise', 'enviado'], array_keys($c->getDatas()));

        $aceite = Candidatura::aceitarOferta($this->vagaDoExterno(), $this->curriculo(), $this->agora);
        self::assertSame(StatusCandidatura::Aprovado, $aceite->getStatus());
        self::assertTrue($aceite->isAceiteDeOferta() && $aceite->isEnviadoAoEscritorio());
        $daCentral = Candidatura::aceitarOferta($this->oferta(1), $this->curriculo(), $this->agora);
        self::assertFalse($daCentral->isEnviadoAoEscritorio(), 'a vaga da Central não tem escritório para receber');
    }

    #[TestDox('aceite: "Aceita por X (n de q)"; ao completar, preenchida com o tempo da rodada; o seguinte é recusado')]
    public function testAceiteAteCompletar(): void
    {
        $v = $this->oferta(2);
        $v->registrarAceite('Ana', 0, $this->agora->modify('+30 seconds'));
        self::assertSame('Aceita por Ana (1 de 2)', end($v->getHistorico())->getTexto());
        self::assertSame(SituacaoVaga::Aberta, $v->getSituacao());

        $v->registrarAceite('Bia', 1, $this->agora->modify('+95 seconds'));
        $textos = array_map(static fn ($h): string => $h->getTexto(), $v->getHistorico());
        self::assertSame(['Aceita por Bia (2 de 2)', 'Oferta preenchida em 01:35 min'], array_slice($textos, -2));
        self::assertSame(SituacaoVaga::Ocupada, $v->getSituacao());

        $this->expectException(TransicaoInvalida::class);
        $v->registrarAceite('Caio', 2, $this->agora->modify('+2 minutes'));
    }

    #[TestDox('cada rodada conta do zero: "Ofertar novamente" recomeça o início; o vencimento conta os aceites da rodada')]
    public function testRodadaRecomeca(): void
    {
        $v = $this->oferta(1);
        self::assertEquals($this->agora, $v->getInicioDaRodada());
        $depois = $this->agora->modify('+20 minutes');
        $v->aplicar(AcaoVaga::OfertarNovamente, null, 'Ana', 15, 0, $depois);
        self::assertEquals($depois, $v->getInicioDaRodada());
        self::assertSame(2, $v->getRodada(), 'o aceite guarda o número da rodada; a contagem não depende do relógio');
        self::assertSame('Oferta expirou sem aceite', $v->getHistorico()[1]->getTexto());

        $v->registrarAceite('Bia', 0, $depois->modify('+1 minute'));
        self::assertSame(SituacaoVaga::Ocupada, $v->getSituacao(), 'o aceite da rodada anterior não conta');

        $outra = $this->oferta(3);
        $outra->aplicar(AcaoVaga::OfertarNovamente, null, 'Ana', 15, 0, $depois, 1);
        self::assertSame('Oferta expirou com 1 de 3 vaga(s) preenchida(s)', $outra->getHistorico()[1]->getTexto());
    }

    #[TestDox('inscrição: só aberta e com o prazo em dia (o próprio dia do prazo vale)')]
    public function testAceitaInscricao(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dadosDaVaga(['inscricoesAte' => '2026-10-19']), $this->agora);
        self::assertTrue($v->aceitaInscricao($this->agora->setTime(23, 0)));
        self::assertFalse($v->aceitaInscricao($this->agora->modify('+1 day')));
        $v->aplicar(AcaoVaga::Pausar, null, 'Ana', 0, 0, $this->agora);
        self::assertFalse($v->aceitaInscricao($this->agora));
    }

    #[TestDox('etapas: na vaga com dono entra "Enviado ao escritório", o atual leva os dias "(atual)" e as datas saem em dd/mm/aa')]
    public function testEtapasComDono(): void
    {
        $c = Candidatura::candidatar($this->vagaDoExterno(), $this->curriculo(), $this->agora);
        $c->enviarAoEscritorio($this->agora->modify('+2 days'));
        $e = (new EtapasDaCandidatura())->calcular($c, $this->agora->modify('+5 days'));

        self::assertSame(['recebida', 'analise', 'enviado', 'entrevista', 'aprovado', 'contratado'], array_column($e['passos'], 'k'));
        self::assertSame(['feito', 'feito', 'atual', 'futuro', 'futuro', 'futuro'], array_column($e['passos'], 'estado'));
        self::assertSame(['bi-send', 'bi-check-lg', 'bi-building-check'], array_slice(array_column($e['passos'], 'ic'), 0, 3));
        self::assertSame(['19/10/26', '21/10/26', '21/10/26', ''], array_slice(array_column($e['passos'], 'data'), 0, 4));
        self::assertSame(['2 dias', '0 dias', '3 dias (atual)', ''], array_slice(array_column($e['passos'], 'dias'), 0, 4));
        self::assertSame('5 dias', $e['total']);
    }

    #[TestDox('etapas: sem dono não há envio; "Não selecionado" deixa feito o último passo alcançado (lacuna 16); contratado fecha o total')]
    public function testEtapasNaoSelecionadoEContratado(): void
    {
        $c = Candidatura::candidatar($this->oferta(1), $this->curriculo(), $this->agora);
        $c->mudarStatus(StatusCandidatura::Entrevista, $this->agora->modify('+1 day'));
        $c->mudarStatus(StatusCandidatura::Nao, $this->agora->modify('+3 days'));
        $e = (new EtapasDaCandidatura())->calcular($c, $this->agora->modify('+10 days'));
        self::assertSame(['recebida', 'analise', 'entrevista', 'aprovado', 'contratado', 'nao'], array_column($e['passos'], 'k'));
        self::assertSame(['feito', 'feito', 'feito', 'futuro', 'futuro', 'nao'], array_column($e['passos'], 'estado'));
        self::assertSame('22/10/26', $e['passos'][5]['data']);

        $contratado = Candidatura::candidatar($this->oferta(1), $this->curriculo(), $this->agora);
        $contratado->mudarStatus(StatusCandidatura::Contratado, $this->agora->modify('+1 day'));
        $e = (new EtapasDaCandidatura())->calcular($contratado, $this->agora->modify('+30 days'));
        self::assertSame('feito', end($e['passos'])['estado']);
        self::assertSame('1 dia', $e['total']);
    }

    #[TestDox('compatibilidade: áreas 60 (sem área, metade), UF ou remoto 25, cargo 15; sem currículo, null; cores do selo')]
    public function testCompatibilidade(): void
    {
        $calc = new CompatibilidadeDaVaga();
        $cv = $this->curriculo();
        self::assertNull($calc->calcular($this->oferta(1), null));
        $igual = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dadosDaVaga(['areas' => ['Cível'], 'uf' => 'DF']), $this->agora);
        self::assertSame(100, $calc->calcular($igual, $cv));
        $metade = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dadosDaVaga(['areas' => ['Cível', 'Criminal'], 'uf' => 'SP', 'cargo' => 'Correspondente']), $this->agora);
        self::assertSame(30, $calc->calcular($metade, $cv));
        $remota = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dadosDaVaga(['areas' => [], 'uf' => 'SP', 'modalidade' => 'Remoto']), $this->agora);
        self::assertSame(70, $calc->calcular($remota, $cv));
        self::assertSame(['#1e7b45', '#0a6fa0', '#6b8494'], [CompatibilidadeDaVaga::cor(70), CompatibilidadeDaVaga::cor(40), CompatibilidadeDaVaga::cor(39)]);
    }

    #[TestDox('importância: compatível a partir de 70% (+2) e candidaturas para analisar (até +3) na vaga; +2 no currículo com candidatura; minhas candidaturas 3, 2 (entrevista ou aprovado), 1, 0')]
    public function testImportancia(): void
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dadosDaVaga(), $this->agora);
        $calc = new CalculadoraDeImportanciaDaVaga();
        self::assertSame(0, $calc->calcular($v, null, false, $this->agora)['nivel']);
        self::assertSame(['nivel' => 1, 'motivos' => ['85% compatível com você']], $calc->calcular($v, null, false, $this->agora, 85));
        self::assertSame(['nivel' => 2, 'motivos' => ['5 candidatura(s) para analisar']], $calc->calcular($v, null, false, $this->agora, null, 5));

        $cv = $this->curriculo();
        self::assertContains('2 candidatura(s)', (new CalculadoraDeImportanciaDoCurriculo())->calcular($cv, null, $this->agora, 2)['motivos']);

        $c = Candidatura::candidatar($v, $cv, $this->agora);
        self::assertSame([1, 3, 0], [ApresentadorDeCandidatura::importancia($c, null)['nivel'], ApresentadorDeCandidatura::importancia($c, true)['nivel'], ApresentadorDeCandidatura::importancia($c, false)['nivel']]);
        $c->mudarStatus(StatusCandidatura::Aprovado, $this->agora);
        self::assertSame(['nivel' => 2, 'motivos' => ['etapa avançada: Aprovado']], ApresentadorDeCandidatura::importancia($c, null));
        $c->mudarStatus(StatusCandidatura::Contratado, $this->agora);
        self::assertSame(1, ApresentadorDeCandidatura::importancia($c, null)['nivel'], 'o desenho (l.1154) pesa só entrevista e aprovado');
    }

    #[TestDox('"Salvar e candidatar-se": "Currículo salvo e candidatura enviada" só quando criou a candidatura comum')]
    public function testToastAposSalvarOCurriculo(): void
    {
        self::assertSame(['titulo' => 'Currículo salvo e candidatura enviada', 'texto' => 'Vaga X'], (new ResultadoDaCandidatura(true, false, 'Candidatura enviada', 't', 'Vaga X'))->toastAposSalvarOCurriculo());
        self::assertSame(['titulo' => 'Oferta aceita', 'texto' => 't'], (new ResultadoDaCandidatura(true, true, 'Oferta aceita', 't', 'Vaga X'))->toastAposSalvarOCurriculo());
        self::assertSame(['titulo' => 'Candidatura já enviada', 'texto' => 't'], (new ResultadoDaCandidatura(false, false, 'Candidatura já enviada', 't', 'Vaga X'))->toastAposSalvarOCurriculo());
    }

    private function oferta(int $qtd): Vaga
    {
        return Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dadosDaVaga(['oferta' => true, 'duracaoDaOferta' => 15, 'qtd' => $qtd]), $this->agora);
    }

    private function vagaDoExterno(): Vaga
    {
        return Vaga::doExterno(Apoio::tenant(3), Apoio::usuario(3), 'Bia', $this->dadosDaVaga(), $this->agora);
    }

    private function curriculo(): Curriculo
    {
        return Curriculo::criar(Apoio::usuario(5), DadosDoCurriculo::deArray([
            'nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'situacao' => 'adv', 'oabNum' => '123', 'oabUf' => 'DF',
            'cargo' => 'Advogado(a)', 'uf' => 'DF', 'areas' => ['Cível'], 'lgpd' => true,
        ]), $this->agora);
    }

    /** @param array<string, mixed> $extra */
    private function dadosDaVaga(array $extra = []): DadosDaVaga
    {
        return DadosDaVaga::deArray($extra + ['titulo' => 'Advogado cível', 'cargo' => 'Advogado(a)', 'ofertante' => ['nome' => 'Escritório']]);
    }
}
