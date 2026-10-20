<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\StatusCandidatura;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\CandidaturaRecusada;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\SemCurriculo;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Repository\DestinatariosDosAvisosRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\Service\ResolvedorDePerfil;
use App\TrabalhoExterno\UseCase\CandidatarOuAceitarUseCase;
use App\TrabalhoExterno\UseCase\EnviarCandidatosAoEscritorioUseCase;
use App\TrabalhoExterno\UseCase\MarcarCandidaturasEmLoteUseCase;
use App\TrabalhoExterno\UseCase\MudarStatusDaCandidaturaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Os casos de uso da candidatura (spec VS 18.10.26 §4.6) com os repositórios do domínio mockados. A
 * trava da linha da vaga é do repositório: aqui ela só roda o trabalho. As consultas se provam no
 * teste funcional.
 */
#[CoversClass(CandidatarOuAceitarUseCase::class)]
#[CoversClass(EnviarCandidatosAoEscritorioUseCase::class)]
#[CoversClass(MudarStatusDaCandidaturaUseCase::class)]
#[CoversClass(MarcarCandidaturasEmLoteUseCase::class)]
final class CasosDeUsoDaCandidaturaTest extends TestCase
{
    private User $externo;
    private Tenant $escritorio;
    private VagaRepository&MockObject $vagas;
    private CurriculoRepository&MockObject $curriculos;
    private CandidaturaRepository&MockObject $candidaturas;
    private ?Vaga $vagaAtual = null;

    protected function setUp(): void
    {
        $this->externo = Apoio::usuario(20);
        $this->escritorio = Apoio::tenant(2);
        $this->vagas = $this->createMock(VagaRepository::class);
        $this->vagas->method('visivelAoExternoPorId')->willReturnCallback(fn (): ?Vaga => $this->vagaAtual);
        $this->vagas->method('daCentralPorId')->willReturnCallback(fn (): ?Vaga => $this->vagaAtual);
        $this->vagas->method('comAVagaTravada')->willReturnCallback(static fn (Vaga $v, callable $trabalho): mixed => $trabalho());
        $this->curriculos = $this->createMock(CurriculoRepository::class);
        $this->candidaturas = $this->createMock(CandidaturaRepository::class);
    }

    #[TestDox('candidatar: cria "Recebida" com o escritório do contexto, "Candidatura de {nome}" no histórico e o toast do desenho')]
    public function testCandidatarCria(): void
    {
        $vaga = $this->vaga();
        $this->comCurriculo();
        $escritorio = $this->escritorio;
        $this->candidaturas->expects(self::once())->method('salvar')->with(self::callback(static fn (Candidatura $c): bool => $c->getStatus() === StatusCandidatura::Recebida && $c->getEscritorioDoCandidato() === $escritorio));

        $r = $this->candidatar()->executar($this->ctx(), $vaga->getCodigo());

        self::assertTrue($r->criou && !$r->aceite);
        self::assertSame(['titulo' => 'Candidatura enviada', 'texto' => 'Acompanhe em Meu currículo · Minhas candidaturas.'], $r->toast());
        self::assertSame('Candidatura de Ana Souza', end($vaga->getHistorico())->getTexto());
    }

    #[TestDox('candidatar: a repetida não grava; sem currículo é SemCurriculo; interno e vaga própria são recusados')]
    public function testCandidatarRecusas(): void
    {
        $vaga = $this->vaga();
        $cv = $this->comCurriculo();
        $this->candidaturas->method('daVagaEDoCurriculo')->willReturn(Candidatura::candidatar($vaga, $cv, new \DateTimeImmutable()));
        $this->candidaturas->expects(self::never())->method('salvar');
        self::assertSame('Candidatura já enviada', $this->candidatar()->executar($this->ctx(), $vaga->getCodigo())->titulo);

        $this->recusa(fn () => $this->candidatar()->executar($this->ctx(Perfil::Master), $vaga->getCodigo()), 'Só profissionais externos se candidatam às vagas.');

        $propria = $this->vaga(Vaga::doExterno($this->escritorio, $this->externo, 'Ana', $this->dados(), new \DateTimeImmutable()));
        $this->recusa(fn () => $this->candidatar()->executar($this->ctx(), $propria->getCodigo()), 'Você não pode se candidatar à sua própria vaga.');
    }

    #[TestDox('candidatar sem currículo: 409 semCurriculo, antes de qualquer gravação')]
    public function testSemCurriculo(): void
    {
        $vaga = $this->vaga();
        $this->vagas->expects(self::never())->method('comAVagaTravada');
        $this->expectException(SemCurriculo::class);
        $this->candidatar()->executar($this->ctx(), $vaga->getCodigo());
    }

    #[TestDox('aceite: com vaga na rodada, "Aprovado" e "Oferta aceita"; com a rodada cheia, "Vaga indisponível" com quase (motiv ligado)')]
    public function testAceite(): void
    {
        $vaga = $this->vaga(Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'qtd' => 1]), new \DateTimeImmutable()));
        $cv = $this->comCurriculo();
        $this->candidaturas->method('aceitesDaRodada')->willReturnOnConsecutiveCalls([], [Candidatura::aceitarOferta($vaga, $cv, new \DateTimeImmutable())]);
        $this->candidaturas->expects(self::once())->method('salvar')->with(self::callback(static fn (Candidatura $c): bool => $c->getStatus() === StatusCandidatura::Aprovado && $c->isAceiteDeOferta()));

        $r = $this->candidatar()->executar($this->ctx(), $vaga->getCodigo());
        self::assertSame('Oferta aceita', $r->titulo);
        self::assertSame('Aceita por Ana Souza (1 de 1)', $vaga->getHistorico()[1]->getTexto());

        $outra = $this->vaga(Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'qtd' => 1]), new \DateTimeImmutable()));
        try {
            $this->candidatar()->executar($this->ctx(), $outra->getCodigo());
            self::fail('a rodada cheia recusa');
        } catch (CandidaturaRecusada $e) {
            self::assertSame(['Vaga indisponível', 'Esta vaga já foi preenchida ou encerrada.', true], [$e->titulo, $e->getMessage(), $e->quase]);
        }
    }

    #[TestDox('enviar: só os ids desta vaga, ainda não enviados; histórico e toast; sem seleção recusa; vaga da Central e Equipe sem talentos não enviam')]
    public function testEnviar(): void
    {
        $vaga = $this->vaga(Vaga::doExterno($this->escritorio, $this->externo, 'Ana', $this->dados(), new \DateTimeImmutable()));
        [$a, $b] = [$this->comId(Candidatura::candidatar($vaga, $this->curriculo(), new \DateTimeImmutable()), 1), $this->comId(Candidatura::candidatar($vaga, $this->curriculo(), new \DateTimeImmutable()), 2)];
        $this->candidaturas->method('daVaga')->willReturn([$a, $b]);
        $this->vagas->expects(self::once())->method('salvar')->with($vaga, true);

        $toast = $this->enviar()->executar($this->ctx(Perfil::Master), $vaga->getCodigo(), [2, 99]);
        self::assertSame(['titulo' => 'Candidatos enviados', 'texto' => '1 currículo(s) disponível(is) para Escritório.'], $toast);
        self::assertSame([false, true, StatusCandidatura::Analise], [$a->isEnviadoAoEscritorio(), $b->isEnviadoAoEscritorio(), $b->getStatus()]);
        self::assertStringStartsWith('1 candidato(s) enviado(s) ao escritório por', end($vaga->getHistorico())->getTexto());

        $this->recusa(fn () => $this->enviar()->executar($this->ctx(Perfil::Master), $vaga->getCodigo(), [2]), 'Marque quem será enviado ao escritório.');

        $semTalentos = new ConfiguracaoCentral();
        $semTalentos->definirEquipe('talentos', false);
        $this->expectException(AcaoNaoPermitida::class);
        $this->enviar()->executar($this->ctx(Perfil::Equipe, $semTalentos), $vaga->getCodigo(), [1]);
    }

    #[TestDox('enviar na vaga da Central: não há escritório para receber (403)')]
    public function testEnviarVagaDaCentral(): void
    {
        $vaga = $this->vaga();
        $this->expectException(AcaoNaoPermitida::class);
        $this->expectExceptionMessage('A vaga da Central não tem escritório para receber candidatos.');
        $this->enviar()->executar($this->ctx(Perfil::Master), $vaga->getCodigo(), [1]);
    }

    #[TestDox('status: só talentos; a candidatura que não existe é 404; a data da etapa é gravada')]
    public function testMudarStatus(): void
    {
        $c = Candidatura::candidatar($this->vaga(), $this->curriculo(), new \DateTimeImmutable());
        $this->candidaturas->method('daCentralPorId')->willReturnMap([[5, $c], [6, null]]);
        $this->candidaturas->expects(self::once())->method('salvar')->with($c, true);
        $mudar = new MudarStatusDaCandidaturaUseCase($this->politicaDaVaga(), $this->candidaturas, $this->avisos());

        self::assertSame(StatusCandidatura::Entrevista, $mudar->executar($this->ctx(Perfil::Master), 5, StatusCandidatura::Entrevista)->getStatus());
        self::assertArrayHasKey('entrevista', $c->getDatas());
        try {
            $mudar->executar($this->ctx(Perfil::Master), 6, StatusCandidatura::Nao);
            self::fail('404');
        } catch (DemandaNaoEncontrada) {
        }
        $this->expectException(AcaoNaoPermitida::class);
        $mudar->executar($this->ctx(), 5, StatusCandidatura::Nao);
    }

    #[TestDox('marcações: só as candidaturas do currículo de quem marca, num flush; a prévia é negada')]
    public function testMarcar(): void
    {
        $cv = $this->comCurriculo();
        $vaga = $this->vaga();
        $this->candidaturas->method('doCurriculo')->willReturn([$this->comId(Candidatura::candidatar($vaga, $cv, new \DateTimeImmutable()), 1), $this->comId(Candidatura::candidatar($vaga, $cv, new \DateTimeImmutable()), 2)]);
        $marcacoes = $this->createMock(MarcacaoDoUsuarioRepository::class);
        $marcacoes->method('doUsuario')->willReturn([]);
        $marcacoes->expects(self::once())->method('salvarTodas')->with(self::callback(static fn (array $m): bool => count($m) === 1 && $m[0] instanceof MarcacaoDoUsuario && $m[0]->getItemId() === 2 && $m[0]->temEstrela()));
        $politica = new PoliticaDoCurriculo($this->politicaDaVaga(), new PoliticaDaDemanda());
        $marcar = new MarcarCandidaturasEmLoteUseCase(new LocalizadorDeCurriculo($this->curriculos, $politica, $this->candidaturas, $this->politicaDaVaga()), $this->candidaturas, $marcacoes);

        self::assertSame(['aplicados' => 1, 'texto' => '1 item(ns) com estrela'], $marcar->executar($this->ctx(), [2, 99], OperacaoDeMarcacao::Estrela));
        $this->expectException(AcaoNaoPermitida::class);
        $marcar->executar(new Contexto($this->externo, $this->escritorio, Perfil::Solicitante, new ConfiguracaoCentral(), previa: true), [1], OperacaoDeMarcacao::Estrela);
    }

    private function candidatar(): CandidatarOuAceitarUseCase
    {
        return new CandidatarOuAceitarUseCase($this->localizador(), $this->politicaDaVaga(), $this->curriculos, $this->candidaturas, $this->vagas, $this->avisos());
    }

    private function enviar(): EnviarCandidatosAoEscritorioUseCase
    {
        return new EnviarCandidatosAoEscritorioUseCase($this->localizador(), $this->politicaDaVaga(), $this->candidaturas, $this->vagas, $this->avisos());
    }

    /** Os avisos com o sino vazio (sem sede, sem ninguém): o que cada evento cria se prova no `AvisosDoTrabalhoExternoTest`. */
    private function avisos(): AvisosDoTrabalhoExterno
    {
        return new AvisosDoTrabalhoExterno(
            $this->createStub(NotificacaoService::class),
            $this->createStub(DestinatariosDosAvisosRepository::class),
            $this->createStub(PreferenciaDoUsuarioRepository::class),
            $this->curriculos,
            new ResolvedorDePerfil($this->createStub(PermissionChecker::class)),
            $this->politicaDaVaga(),
            $this->createStub(ConviteDemandaRepository::class),
        );
    }

    private function localizador(): LocalizadorDeVaga
    {
        return new LocalizadorDeVaga($this->vagas, $this->politicaDaVaga(), $this->curriculos, $this->candidaturas);
    }

    private function politicaDaVaga(): PoliticaDaVaga
    {
        return new PoliticaDaVaga(new PoliticaDaDemanda());
    }

    private function ctx(Perfil $perfil = Perfil::Solicitante, ?ConfiguracaoCentral $cfg = null): Contexto
    {
        return new Contexto($perfil === Perfil::Solicitante ? $this->externo : Apoio::usuario(1), $this->escritorio, $perfil, $cfg ?? new ConfiguracaoCentral());
    }

    /** A vaga que o repositório devolve ao externo (aberta ou dele), com id. */
    private function vaga(?Vaga $vaga = null): Vaga
    {
        $vaga ??= Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(), new \DateTimeImmutable());
        $this->comId($vaga, 40);
        $this->vagaAtual = $vaga;

        return $vaga;
    }

    private function comCurriculo(): Curriculo
    {
        $cv = $this->curriculo();
        $this->curriculos->method('doUsuario')->willReturn($cv);

        return $cv;
    }

    private function curriculo(): Curriculo
    {
        return $this->comId(Curriculo::criar($this->externo, DadosDoCurriculo::deArray(['nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'situacao' => 'bach', 'lgpd' => true]), new \DateTimeImmutable()), 7);
    }

    /** @param array<string, mixed> $extra */
    private function dados(array $extra = []): DadosDaVaga
    {
        return DadosDaVaga::deArray($extra + ['titulo' => 'Advogado cível', 'cargo' => 'Advogado(a)', 'ofertante' => ['nome' => 'Escritório']]);
    }

    /**
     * @template T of object
     *
     * @param T $objeto
     *
     * @return T
     */
    private function comId(object $objeto, int $id): object
    {
        (new \ReflectionProperty($objeto, 'id'))->setValue($objeto, $id);

        return $objeto;
    }

    private function recusa(callable $acao, string $mensagem): void
    {
        try {
            $acao();
            self::fail('esperava a recusa: ' . $mensagem);
        } catch (CandidaturaRecusada $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
    }
}
