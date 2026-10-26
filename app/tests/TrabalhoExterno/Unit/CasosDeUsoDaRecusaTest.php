<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\RecusaDeOferta;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\DestinatariosDosAvisosRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Repository\RecusaDeOfertaRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\ResolvedorDePerfil;
use App\TrabalhoExterno\UseCase\DesfazerRecusaDeOfertaUseCase;
use App\TrabalhoExterno\UseCase\RecusarOfertaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Recusar" a oferta e "Você recusou · aceitar mesmo assim" (D11b, desenho 06 de 24/10, l.1169), com os
 * repositórios mockados: a trava da linha só roda o trabalho. Quem recebe o aviso se prova no
 * `AvisosDoTrabalhoExternoTest`; aqui, que o aviso sai uma vez só.
 */
#[CoversClass(RecusarOfertaUseCase::class)]
#[CoversClass(DesfazerRecusaDeOfertaUseCase::class)]
#[CoversClass(RecusaDeOferta::class)]
final class CasosDeUsoDaRecusaTest extends TestCase
{
    private User $externo;
    private Tenant $escritorio;
    private VagaRepository&MockObject $vagas;
    private RecusaDeOfertaRepository&MockObject $recusas;
    private CurriculoRepository&MockObject $curriculos;
    private CandidaturaRepository&MockObject $candidaturas;
    private NotificacaoService&MockObject $notificacoes;
    private ?Vaga $vagaAtual = null;
    private ?RecusaDeOferta $gravada = null;

    protected function setUp(): void
    {
        $this->externo = Apoio::usuario(20);
        $this->escritorio = Apoio::tenant(2);
        $this->vagas = $this->createMock(VagaRepository::class);
        $this->vagas->method('visivelAoExternoPorId')->willReturnCallback(fn (): ?Vaga => $this->vagaAtual);
        $this->vagas->method('daCentralPorId')->willReturnCallback(fn (): ?Vaga => $this->vagaAtual);
        $this->vagas->method('comAVagaTravada')->willReturnCallback(static fn (Vaga $v, callable $trabalho): mixed => $trabalho());
        $this->recusas = $this->createMock(RecusaDeOfertaRepository::class);
        $this->recusas->method('daVagaEDoUsuario')->willReturnCallback(fn (): ?RecusaDeOferta => $this->gravada);
        $this->curriculos = $this->createMock(CurriculoRepository::class);
        $this->candidaturas = $this->createMock(CandidaturaRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoService::class);
    }

    #[TestDox('recusar: grava uma recusa da vaga e de quem leu, "Oferta recusada por {quem}" no histórico e um aviso; a segunda vez não grava nem avisa')]
    public function testRecusarGravaUmaVez(): void
    {
        $vaga = $this->oferta();
        $agora = new \DateTimeImmutable();
        $this->recusas->expects(self::once())->method('salvar')->willReturnCallback(function (RecusaDeOferta $r): void {
            $this->gravada = $r;
        });
        $this->vagas->expects(self::once())->method('salvar')->with($vaga, true);
        $this->notificacoes->expects(self::once())->method('criar')->willReturnCallback(fn (): \App\Entity\Notificacao => new \App\Entity\Notificacao());

        self::assertTrue($this->recusar()->executar($this->ctx(), $vaga->getCodigo()));
        self::assertSame($vaga, $this->gravada?->getVaga());
        self::assertSame($this->externo, $this->gravada?->getUsuario());
        self::assertGreaterThanOrEqual($agora->getTimestamp(), $this->gravada?->getEm()->getTimestamp());
        $ultima = end($vaga->getHistorico());
        self::assertSame(['bi-x-circle', '#a3232b', 'Oferta recusada por Usuário 20'], [$ultima->getIcone(), $ultima->getCor(), $ultima->getTexto()]);

        $linhas = count($vaga->getHistorico());
        self::assertFalse($this->recusar()->executar($this->ctx(), $vaga->getCodigo()), 'idempotente');
        self::assertCount($linhas, $vaga->getHistorico());
    }

    #[TestDox('recusar: a pré-visualização, o interno (Master e Equipe) e o dono da vaga não recusam; a prévia e o interno não desfazem')]
    public function testQuemNaoRecusa(): void
    {
        $vaga = $this->oferta();
        $this->recusas->expects(self::never())->method('salvar');

        $this->falha(fn () => $this->recusar()->executar($this->ctx(Perfil::Master), $vaga->getCodigo()), AcaoNaoPermitida::class, 'Só quem recebeu a oferta pode recusá-la.');
        $this->falha(fn () => $this->recusar()->executar($this->ctx(Perfil::Equipe), $vaga->getCodigo()), AcaoNaoPermitida::class, 'Só quem recebeu a oferta pode recusá-la.');
        $this->falha(fn () => $this->desfazer()->executar($this->ctx(Perfil::Master), $vaga->getCodigo()), AcaoNaoPermitida::class, 'Só quem recebeu a oferta pode recusá-la.');

        $propria = $this->oferta(Vaga::doExterno($this->escritorio, $this->externo, 'Ana', $this->dados(['oferta' => true]), new \DateTimeImmutable()));
        $propria->aplicar(AcaoVaga::Aprovar, null, 'Ana', 15, 0, new \DateTimeImmutable());
        $this->falha(fn () => $this->recusar()->executar($this->ctx(), $propria->getCodigo()), AcaoNaoPermitida::class, 'Só quem recebeu a oferta pode recusá-la.');

        $previa = new Contexto($this->externo, $this->escritorio, Perfil::Solicitante, new ConfiguracaoCentral(), previa: true);
        $this->falha(fn () => $this->recusar()->executar($previa, $vaga->getCodigo()), AcaoNaoPermitida::class, 'Na pré-visualização não é possível alterar nada.');
        $this->falha(fn () => $this->desfazer()->executar($previa, $vaga->getCodigo()), AcaoNaoPermitida::class, 'Na pré-visualização não é possível alterar nada.');
    }

    #[TestDox('recusar: a vaga comum e a que a pessoa já aceitou não se recusam; a oferta vencida o externo nem localiza (404)')]
    public function testOQueNaoSeRecusa(): void
    {
        $this->recusas->expects(self::never())->method('salvar');
        $comum = $this->oferta(Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => false]), new \DateTimeImmutable()));
        $this->falha(fn () => $this->recusar()->executar($this->ctx(), $comum->getCodigo()), TransicaoInvalida::class, 'Esta oferta não está mais disponível.');

        $vencida = $this->oferta(Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'duracaoDaOferta' => 10]), new \DateTimeImmutable('-11 minutes')));
        $this->falha(fn () => $this->recusar()->executar($this->ctx(), $vencida->getCodigo()), DemandaNaoEncontrada::class, 'Vaga não encontrada.');

        $aceita = $this->oferta();
        $cv = Curriculo::criar($this->externo, DadosDoCurriculo::deArray(['nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'situacao' => 'bach', 'lgpd' => true]), new \DateTimeImmutable());
        $this->curriculos->method('doUsuario')->willReturn($cv);
        $this->candidaturas->method('daVagaEDoCurriculo')->willReturn(Candidatura::aceitarOferta($aceita, $cv, new \DateTimeImmutable()));
        $this->falha(fn () => $this->recusar()->executar($this->ctx(), $aceita->getCodigo()), TransicaoInvalida::class, 'Você já aceitou esta oferta.');
    }

    #[TestDox('desfazer: apaga a recusa de quem lê; sem recusa, nada muda')]
    public function testDesfazer(): void
    {
        $vaga = $this->oferta();
        $this->gravada = new RecusaDeOferta($vaga, $this->externo, new \DateTimeImmutable());
        $this->recusas->expects(self::once())->method('remover')->with($this->gravada, true);
        $this->desfazer()->executar($this->ctx(), $vaga->getCodigo());

        $this->gravada = null;
        $this->desfazer()->executar($this->ctx(), $vaga->getCodigo());
    }

    #[TestDox('"Ofertar novamente" não zera a recusa (P7): a recusa é da vaga e da pessoa, não da rodada')]
    public function testOfertarNovamenteNaoZera(): void
    {
        $vaga = $this->oferta();
        $this->gravada = new RecusaDeOferta($vaga, $this->externo, new \DateTimeImmutable());
        $vaga->aplicar(AcaoVaga::Encerrar, null, 'Ana', 0, 0, new \DateTimeImmutable());
        $vaga->aplicar(AcaoVaga::OfertarNovamente, null, 'Ana', 0, 0, new \DateTimeImmutable());
        $this->recusas->expects(self::never())->method('salvar');

        self::assertFalse($this->recusar()->executar($this->ctx(), $vaga->getCodigo()), 'continua recusada na rodada nova');
    }

    private function recusar(): RecusarOfertaUseCase
    {
        return new RecusarOfertaUseCase($this->localizador(), $this->politica(), $this->vagas, $this->recusas, $this->curriculos, $this->candidaturas, $this->avisos());
    }

    private function desfazer(): DesfazerRecusaDeOfertaUseCase
    {
        return new DesfazerRecusaDeOfertaUseCase($this->localizador(), $this->recusas);
    }

    /** Sino com um Master na sede: a vaga da Central avisa só ele. */
    private function avisos(): AvisosDoTrabalhoExterno
    {
        $destinatarios = $this->createMock(DestinatariosDosAvisosRepository::class);
        $destinatarios->method('masters')->willReturn([Apoio::usuario(1, ['ROLE_USER', 'ROLE_SUPER_ADMIN'])]);
        $destinatarios->method('temPendente')->willReturn(false);

        return new AvisosDoTrabalhoExterno(
            $this->notificacoes,
            $destinatarios,
            $this->createStub(PreferenciaDoUsuarioRepository::class),
            $this->curriculos,
            new ResolvedorDePerfil($this->createStub(PermissionChecker::class)),
            $this->politica(),
            $this->createStub(ConviteDemandaRepository::class),
            new PoliticaDaDemanda(),
        );
    }

    private function localizador(): LocalizadorDeVaga
    {
        return new LocalizadorDeVaga($this->vagas, $this->politica(), $this->curriculos, $this->candidaturas);
    }

    private function politica(): PoliticaDaVaga
    {
        return new PoliticaDaVaga(new PoliticaDaDemanda());
    }

    private function ctx(Perfil $perfil = Perfil::Solicitante): Contexto
    {
        $cfg = new ConfiguracaoCentral();
        $cfg->definirSede(Apoio::tenant(1));

        return new Contexto($perfil === Perfil::Solicitante ? $this->externo : Apoio::usuario(1), $this->escritorio, $perfil, $cfg);
    }

    /** A oferta aberta da Central que o repositório devolve, com id. */
    private function oferta(?Vaga $vaga = null): Vaga
    {
        $vaga ??= Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dados(['oferta' => true, 'duracaoDaOferta' => 60]), new \DateTimeImmutable());
        (new \ReflectionProperty($vaga, 'id'))->setValue($vaga, 40);
        $this->vagaAtual = $vaga;

        return $vaga;
    }

    /** @param array<string, mixed> $extra */
    private function dados(array $extra = []): DadosDaVaga
    {
        return DadosDaVaga::deArray($extra + ['titulo' => 'Advogado cível', 'cargo' => 'Advogado(a)', 'periodoDias' => 0, 'ofertante' => ['nome' => 'Escritório']]);
    }

    /** @param class-string<\Throwable> $classe */
    private function falha(callable $acao, string $classe, string $mensagem): void
    {
        try {
            $acao();
            self::fail('esperava ' . $classe . ': ' . $mensagem);
        } catch (\Throwable $e) {
            self::assertInstanceOf($classe, $e);
            self::assertSame($mensagem, $e->getMessage());
        }
    }
}
