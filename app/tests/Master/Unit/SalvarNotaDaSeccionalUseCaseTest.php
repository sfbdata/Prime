<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\SalvarNotaDaSeccionalInput;
use App\Master\Entity\NotaDaSeccional;
use App\Master\Entity\SeccionalOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\NotaDaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\UseCase\SalvarNotaDaSeccionalUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Salvar a nota explicativa da janela da OAB (spec master-oab-estados-que-faltam, "NOTA EXPLICATIVA"): uma por UF,
 * até 1000 caracteres, com quem e quando; texto vazio apaga. Tudo dentro do escopo de plataforma.
 */
#[CoversClass(SalvarNotaDaSeccionalUseCase::class)]
#[CoversClass(SalvarNotaDaSeccionalInput::class)]
#[CoversClass(NotaDaSeccional::class)]
final class SalvarNotaDaSeccionalUseCaseTest extends TestCase
{
    private SeccionalOabRepository&MockObject $seccionais;
    private NotaDaSeccionalRepository&MockObject $notas;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private SeccionalOab $seccional;
    private User $master;

    protected function setUp(): void
    {
        $this->seccionais = $this->createMock(SeccionalOabRepository::class);
        $this->notas = $this->createMock(NotaDaSeccionalRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->seccional = new SeccionalOab('AM', 'OAB Seccional Amazonas');
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
        $this->seccionais->method('buscarPorUf')->willReturnCallback(fn (string $uf): ?SeccionalOab => $uf === 'AM' ? $this->seccional : null);
    }

    #[TestDox('sem nota: cria a nota aparada com quem e quando, com a seccional travada e dentro do escopo de plataforma')]
    public function testCria(): void
    {
        $this->travaEntrega(null);
        $gravada = null;
        $this->notas->expects($this->once())->method('salvar')->with(self::isInstanceOf(NotaDaSeccional::class))
            ->willReturnCallback(function (NotaDaSeccional $nota) use (&$gravada): void {
                self::assertTrue($this->escopo->estaLigado(), 'grava dentro do escopo de plataforma');
                $gravada = $nota;
            });

        self::assertSame($this->seccional, $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'AM', ['texto' => '  Falar com a presidente  '])));

        self::assertInstanceOf(NotaDaSeccional::class, $gravada);
        self::assertSame('Falar com a presidente', $gravada->getTexto());
        self::assertSame($this->seccional, $gravada->getSeccional());
        self::assertSame($this->master, $gravada->getAtualizadaPor());
        self::assertSame('2026-10-21 09:00', $gravada->getAtualizadaEm()->format('Y-m-d H:i'));
        self::assertFalse($this->escopo->estaLigado());
    }

    #[TestDox('com nota: reescreve a MESMA nota (uma por UF), com o novo autor e a nova data, sem criar outra')]
    public function testReescreve(): void
    {
        $nota = new NotaDaSeccional($this->seccional, 'Antiga', null, new \DateTimeImmutable('2026-10-01'));
        $this->travaEntrega($nota);
        $this->notas->expects($this->never())->method('salvar');

        $this->useCase()->executar(new SalvarNotaDaSeccionalInput($this->master, 'AM', 'Nova'));

        self::assertSame('Nova', $nota->getTexto());
        self::assertSame($this->master, $nota->getAtualizadaPor());
        self::assertSame('2026-10-21', $nota->getAtualizadaEm()->format('Y-m-d'));
    }

    #[TestDox('corrida da primeira nota: outra gravação criou a nota enquanto esta esperava a trava; reescreve (a última vence), sem inserir de novo')]
    public function testCorridaDaPrimeiraNota(): void
    {
        // Antes da trava não havia nota; quem estava na frente da fila a criou. A leitura que vale é a de dentro da trava.
        $daOutraGravacao = new NotaDaSeccional($this->seccional, 'Da primeira gravação', null, new \DateTimeImmutable('2026-10-21 08:59'));
        $this->notas->expects($this->never())->method('daSeccional');
        $this->travaEntrega($daOutraGravacao);
        $this->notas->expects($this->never())->method('salvar');

        $this->useCase()->executar(new SalvarNotaDaSeccionalInput($this->master, 'AM', 'Da segunda gravação'));

        self::assertSame('Da segunda gravação', $daOutraGravacao->getTexto());
        self::assertSame($this->master, $daOutraGravacao->getAtualizadaPor());
    }

    #[TestDox('texto vazio apaga a nota que houver, com a seccional travada e dentro do escopo de plataforma')]
    public function testVazioApaga(): void
    {
        $nota = new NotaDaSeccional($this->seccional, 'Antiga', null, new \DateTimeImmutable('2026-10-01'));
        $this->travaEntrega($nota);
        $this->notas->expects($this->never())->method('salvar');
        $this->notas->expects($this->once())->method('remover')->with($nota)
            ->willReturnCallback(fn () => self::assertTrue($this->escopo->estaLigado()));

        $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'AM', ['texto' => "  \n "]));
    }

    #[TestDox('texto vazio sem nota gravada: não faz nada')]
    public function testVazioSemNota(): void
    {
        $this->travaEntrega(null);
        $this->notas->expects($this->never())->method('salvar');
        $this->notas->expects($this->never())->method('remover');

        $this->useCase()->executar(SalvarNotaDaSeccionalInput::doJson($this->master, 'AM', []));
    }

    #[TestDox('texto acima de 1000 caracteres: recusa com a mensagem e nem trava; 1000 exatos passam')]
    public function testLonga(): void
    {
        $this->notas->expects($this->once())->method('gravarComTrava')
            ->willReturnCallback(fn (SeccionalOab $s, callable $acao) => $acao(null));
        $this->notas->expects($this->once())->method('salvar');

        $this->useCase()->executar(new SalvarNotaDaSeccionalInput($this->master, 'AM', str_repeat('ç', 1000)));

        $this->expectException(SeccionalRecusada::class);
        $this->expectExceptionMessage('A nota passa de 1000 caracteres.');
        $this->useCase()->executar(new SalvarNotaDaSeccionalInput($this->master, 'AM', str_repeat('a', 1001)));
    }

    #[TestDox('linha da seccional ausente: SeccionalNaoEncontrada')]
    public function testSemLinha(): void
    {
        $this->notas->expects($this->never())->method('gravarComTrava');

        $this->expectException(SeccionalNaoEncontrada::class);
        $this->useCase()->executar(new SalvarNotaDaSeccionalInput($this->master, 'SP', 'Texto'));
    }

    /** A trava da seccional entrega à ação a nota lida DENTRO dela (com o escopo de plataforma ligado). */
    private function travaEntrega(?NotaDaSeccional $nota): void
    {
        $this->notas->expects($this->once())->method('gravarComTrava')->with($this->seccional)
            ->willReturnCallback(function (SeccionalOab $s, callable $acao) use ($nota): void {
                self::assertTrue($this->escopo->estaLigado(), 'a gravação travada roda dentro do escopo de plataforma');
                $acao($nota);
            });
    }

    private function useCase(): SalvarNotaDaSeccionalUseCase
    {
        return new SalvarNotaDaSeccionalUseCase($this->seccionais, $this->notas, new MockClock('2026-10-21 09:00:00'), $this->escopo);
    }
}
