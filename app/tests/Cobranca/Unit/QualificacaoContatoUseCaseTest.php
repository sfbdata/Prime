<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\QualificacaoContato;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\EventoNaoEncontradoException;
use App\Cobranca\Exception\QualificacaoNaoDesfazivelException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\DesfazerQualificacaoContatoUseCase;
use App\Cobranca\UseCase\RegistrarQualificacaoContatoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Etapa 2 da frente do cabeçalho/aba Responsáveis: registrar e desfazer a qualificação de contato
 * (spec §3.3 e §3.5).
 *
 * O `RegistrarEventoHistorico` entra REAL (é `final`, e mockar o que grava esconderia justamente o que
 * precisa ser provado): o teste captura o `EventoHistorico` que ele manda salvar e confere tipo,
 * rótulo, payload e autor no objeto de verdade.
 */
#[CoversClass(RegistrarQualificacaoContatoUseCase::class)]
#[CoversClass(DesfazerQualificacaoContatoUseCase::class)]
final class QualificacaoContatoUseCaseTest extends TestCase
{
    /** Momento em que a qualificação foi registrada; as janelas do desfazer contam daqui. */
    private const REGISTRO = '2026-07-27 14:00:00';

    private CasoCobrancaRepository&MockObject $casoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->tenant = new Tenant();
        $this->autor = new User();
        // Entidade nova não tem id, e `podeSerDesfeitaPor` compara `getId()`: sem identidade explícita
        // dois usuários diferentes seriam "o mesmo" (null === null) e o teste de outro autor não provaria nada.
        $this->definirId($this->autor, 7);
    }

    // ----------------------------------------------------------------------------- registrar

    #[Test]
    #[TestDox('Um clique grava tipo, rótulo, payload e autor no histórico do caso')]
    public function registraComTodoOMetadado(): void
    {
        $caso = $this->caso();
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);

        $salvo = null;
        $this->eventoRepository->expects($this->once())
            ->method('salvar')
            ->willReturnCallback(function (EventoHistorico $evento, bool $flush) use (&$salvo): void {
                $salvo = $evento;
                // Registrar é a ÚNICA escrita deste UseCase: sem o flush aqui nada chegaria ao banco.
                self::assertTrue($flush, 'a qualificação precisa ser commitada no próprio UseCase');
            });

        $sut = new RegistrarQualificacaoContatoUseCase(
            $this->casoRepository,
            new RegistrarEventoHistorico($this->eventoRepository),
            $this->relogio(self::REGISTRO),
        );

        $evento = $sut->executar(42, QualificacaoContato::RecusaPagamento, $this->tenant, $this->autor);

        self::assertSame($salvo, $evento);
        self::assertSame(TipoEventoHistorico::QualificacaoContato, $evento->getTipo());
        self::assertSame('Recusa de pagamento', $evento->getDescricao());
        self::assertSame(['qualificacao' => 'recusa_pagamento'], $evento->getDados());
        self::assertSame($this->autor, $evento->getUsuario());
        self::assertSame($caso, $evento->getCaso());
        self::assertSame($this->tenant, $evento->getTenant(), 'o tenant vem do caso, nunca do parâmetro');
        // O mesmo relógio que mede a janela do desfazer tem de carimbar o registro — senão o marco vem
        // do `new \DateTimeImmutable()` do construtor da entidade e os dois lados da regra andam separados.
        self::assertEquals(
            new \DateTimeImmutable(self::REGISTRO),
            $evento->getOcorridoEm(),
            'o ocorridoEm vem do relógio injetado, não do relógio do sistema',
        );
    }

    #[Test]
    #[TestDox('O payload guarda o valor do enum, não o rótulo — é ele que reidrata a listinha')]
    public function payloadGuardaOValorDoEnum(): void
    {
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($this->caso());

        $sut = new RegistrarQualificacaoContatoUseCase(
            $this->casoRepository,
            new RegistrarEventoHistorico($this->eventoRepository),
            $this->relogio(self::REGISTRO),
        );

        $evento = $sut->executar(42, QualificacaoContato::PromessaPagamento, $this->tenant, $this->autor);

        self::assertSame(
            QualificacaoContato::PromessaPagamento,
            QualificacaoContato::tentarDe($evento->getDados()['qualificacao'] ?? null),
            'o que foi gravado tem de voltar a ser o mesmo case do enum',
        );
    }

    #[Test]
    #[TestDox('Caso encerrado não recebe qualificação')]
    public function casoEncerradoNaoQualifica(): void
    {
        $caso = $this->caso();
        $caso->setStatus(StatusCaso::Encerrado);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $sut = new RegistrarQualificacaoContatoUseCase(
            $this->casoRepository,
            new RegistrarEventoHistorico($this->eventoRepository),
            $this->relogio(self::REGISTRO),
        );

        $this->expectException(CasoEncerradoException::class);
        $sut->executar(42, QualificacaoContato::RecusaPagamento, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Caso de outro escritório não existe para quem pede')]
    public function casoDeOutroTenantNaoQualifica(): void
    {
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $sut = new RegistrarQualificacaoContatoUseCase(
            $this->casoRepository,
            new RegistrarEventoHistorico($this->eventoRepository),
            $this->relogio(self::REGISTRO),
        );

        $this->expectException(CasoNaoEncontradoException::class);
        $sut->executar(999, QualificacaoContato::RecusaPagamento, $this->tenant, $this->autor);
    }

    // ------------------------------------------------------------------------------ desfazer

    #[Test]
    #[TestDox('O autor desfaz o próprio clique dentro dos 5 minutos')]
    public function autorDesfazDentroDaJanela(): void
    {
        // O UseCase é `void` desde a Etapa 8 (o id do caso que ele devolvia não era consumido por
        // ninguém). Quem prova o happy path é a expectativa sobre `remover`, que é mais forte do que o
        // retorno era: ela exige que o evento removido seja EXATAMENTE o resolvido, e com flush.
        $evento = $this->qualificacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($evento);
        $this->eventoRepository->expects($this->once())->method('remover')->with($evento, true);

        $this->desfazer('2026-07-27 14:03:00')->executar(5, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Exatamente 5 minutos ainda desfaz (limite fechado)')]
    public function limiteExatoAindaDesfaz(): void
    {
        $evento = $this->qualificacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($evento);
        $this->eventoRepository->expects($this->once())->method('remover');

        $this->desfazer('2026-07-27 14:05:00')->executar(5, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Passados os 5 minutos a qualificação fica firme')]
    public function foraDaJanelaNaoDesfaz(): void
    {
        $evento = $this->qualificacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($evento);
        $this->eventoRepository->expects($this->never())->method('remover');

        $this->expectException(QualificacaoNaoDesfazivelException::class);
        $this->desfazer('2026-07-27 14:05:01')->executar(5, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Qualificação de outra pessoa não é desfeita, mesmo dentro da janela')]
    public function outroUsuarioNaoDesfaz(): void
    {
        $evento = $this->qualificacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($evento);
        $this->eventoRepository->expects($this->never())->method('remover');

        $outro = new User();
        $this->definirId($outro, 99);

        $this->expectException(QualificacaoNaoDesfazivelException::class);
        $this->desfazer('2026-07-27 14:01:00')->executar(5, $this->tenant, $outro);
    }

    #[Test]
    #[TestDox('Só a MAIS RECENTE é desfazível: a penúltima é recusada mesmo dentro dos 5 minutos')]
    public function penultimaNaoDesfaz(): void
    {
        $penultima = $this->qualificacao();
        $ultima = $this->qualificacao(idEvento: 6, ocorridoEm: '2026-07-27 14:01:00');

        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($penultima);
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($ultima);
        $this->eventoRepository->expects($this->never())->method('remover');

        // 14:02 — as três primeiras condições passam para AMBAS; só a quarta separa.
        $this->expectException(QualificacaoNaoDesfazivelException::class);
        $this->desfazer('2026-07-27 14:02:00')->executar(5, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Desfeita a última, a penúltima passa a ser desfazível — a cascata dentro da janela é permitida')]
    public function cascataDentroDaJanela(): void
    {
        // Trava o comportamento apontado pela revisão de 2026-07-27: a condição 4 ordena as remoções,
        // não as limita. Se o dono decidir limitar a UMA remoção por janela, é ESTE teste que quebra —
        // e é assim que se descobre que a decisão mudou, em vez de o comportamento mudar em silêncio.
        $penultima = $this->qualificacao();
        $ultima = $this->qualificacao(idEvento: 6, ocorridoEm: '2026-07-27 14:01:00');

        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($penultima);
        // Já sem a de id 6: é o estado do banco DEPOIS de o autor ter desfeito a última.
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($penultima);
        $this->eventoRepository->expects($this->once())->method('remover')->with($penultima, true);

        $this->desfazer('2026-07-27 14:02:00')->executar(5, $this->tenant, $this->autor);
        self::assertSame(6, $ultima->getId(), 'a que saiu antes era mesmo outra entrada');
    }

    #[Test]
    #[TestDox('Evento sem id não é comparável com "a última do caso" e é recusado (nunca fail-open)')]
    public function eventoSemIdNaoDesfaz(): void
    {
        // Dois ids nulos são "iguais" para o `!==`. Sem a guarda explícita, a comparação da condição 4
        // liberaria a remoção justamente quando não há como provar que ela é a última.
        $evento = $this->qualificacao();
        $this->definirId($evento, null);

        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($this->qualificacao(idEvento: null));
        $this->eventoRepository->expects($this->never())->method('remover');

        $this->expectException(QualificacaoNaoDesfazivelException::class);
        $this->desfazer('2026-07-27 14:01:00')->executar(5, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Evento de outro tipo (anotação, contato, pagamento) nunca é desfeito por aqui')]
    public function outroTipoNaoDesfaz(): void
    {
        $evento = $this->qualificacao(tipo: TipoEventoHistorico::Anotacao);
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($evento);
        $this->eventoRepository->expects($this->never())->method('remover');

        $this->expectException(QualificacaoNaoDesfazivelException::class);
        $this->desfazer('2026-07-27 14:01:00')->executar(5, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Evento de outro escritório não existe para quem pede')]
    public function eventoDeOutroTenantNaoDesfaz(): void
    {
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('remover');

        $this->expectException(EventoNaoEncontradoException::class);
        $this->desfazer('2026-07-27 14:01:00')->executar(999, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Caso encerrado NÃO impede desfazer: nele nem a correção por cima seria possível')]
    public function casoEncerradoAindaDesfaz(): void
    {
        $evento = $this->qualificacao();
        $evento->getCaso()?->setStatus(StatusCaso::Encerrado);
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->method('ultimaQualificacaoDoCaso')->willReturn($evento);
        $this->eventoRepository->expects($this->once())->method('remover')->with($evento, true);

        $this->desfazer('2026-07-27 14:01:00')->executar(5, $this->tenant, $this->autor);
    }

    // -------------------------------------------------------------------------------- apoio

    private function desfazer(string $agora): DesfazerQualificacaoContatoUseCase
    {
        return new DesfazerQualificacaoContatoUseCase($this->eventoRepository, $this->relogio($agora));
    }

    private function caso(): CasoCobranca
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->definirId($caso, 42);

        return $caso;
    }

    private function qualificacao(
        TipoEventoHistorico $tipo = TipoEventoHistorico::QualificacaoContato,
        ?int $idEvento = 5,
        string $ocorridoEm = self::REGISTRO,
    ): EventoHistorico {
        $evento = new EventoHistorico();
        $evento->setTenant($this->tenant)
            ->setCaso($this->caso())
            ->setTipo($tipo)
            ->setUsuario($this->autor)
            ->setDescricao('Recusa de pagamento')
            ->setDados(['qualificacao' => QualificacaoContato::RecusaPagamento->value])
            ->setOcorridoEm(new \DateTimeImmutable($ocorridoEm));

        // Id explícito: a quarta condição compara `getId()`, e dois eventos sem id seriam "o mesmo".
        $this->definirId($evento, $idEvento);

        return $evento;
    }

    private function relogio(string $agora): ClockInterface
    {
        return new MockClock(new \DateTimeImmutable($agora));
    }

    /** Entidades novas não têm id; o teste precisa de identidade para comparar autoria e recência. */
    private function definirId(object $entidade, ?int $id): void
    {
        $ref = new \ReflectionProperty($entidade, 'id');
        $ref->setValue($entidade, $id);
    }
}
