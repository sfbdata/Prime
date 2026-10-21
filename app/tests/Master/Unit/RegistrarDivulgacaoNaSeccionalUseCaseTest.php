<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\RegistrarDivulgacaoNaSeccionalInput;
use App\Master\Entity\DivulgacaoNaSeccional;
use App\Master\Entity\SeccionalOab;
use App\Master\Enum\TipoDeDivulgacao;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\DivulgacaoNaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\UseCase\RegistrarDivulgacaoNaSeccionalUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Registrar divulgação numa seccional (spec master-seccionais-oab, "Registro de divulgação"): tipo entre os três do
 * desenho, data não futura (vazia = hoje), nota até 500; grava dentro do escopo de plataforma.
 */
#[CoversClass(RegistrarDivulgacaoNaSeccionalUseCase::class)]
#[CoversClass(RegistrarDivulgacaoNaSeccionalInput::class)]
#[CoversClass(DivulgacaoNaSeccional::class)]
#[CoversClass(TipoDeDivulgacao::class)]
final class RegistrarDivulgacaoNaSeccionalUseCaseTest extends TestCase
{
    private SeccionalOabRepository&MockObject $seccionais;
    private DivulgacaoNaSeccionalRepository&MockObject $divulgacoes;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private User $master;

    protected function setUp(): void
    {
        $this->seccionais = $this->createMock(SeccionalOabRepository::class);
        $this->seccionais->method('buscarPorUf')->willReturnCallback(static fn (string $uf): ?SeccionalOab => $uf === 'BA' ? new SeccionalOab('BA', 'OAB Seccional Bahia') : null);
        $this->divulgacoes = $this->createMock(DivulgacaoNaSeccionalRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('sem data vale hoje; grava tipo, nota, autor e o momento, dentro do escopo de plataforma')]
    public function testRegistraHoje(): void
    {
        $this->divulgacoes->expects($this->once())->method('salvar')->with($this->isInstanceOf(DivulgacaoNaSeccional::class), true)
            ->willReturnCallback(fn () => self::assertTrue($this->escopo->estaLigado()));

        $d = $this->useCase()->executar(RegistrarDivulgacaoNaSeccionalInput::doJson($this->master, 'BA', ['tipo' => 'reuniao', 'nota' => ' Reunião com a diretoria ']));

        self::assertSame(TipoDeDivulgacao::Reuniao, $d->getTipo());
        self::assertSame('2026-10-21', $d->getData()->format('Y-m-d'));
        self::assertSame('Reunião com a diretoria', $d->getNota());
        self::assertSame($this->master, $d->getAutor());
        self::assertSame('BA', $d->getSeccional()->getUf());
        self::assertSame('2026-10-21 15:30', $d->getCriadoEm()->format('Y-m-d H:i'));
    }

    #[TestDox('data no passado é aceita; nota vazia vira nula')]
    public function testDataNoPassado(): void
    {
        $this->divulgacoes->expects($this->once())->method('salvar');

        $d = $this->useCase()->executar(RegistrarDivulgacaoNaSeccionalInput::doJson($this->master, 'BA', ['tipo' => 'material', 'data' => '2026-09-30', 'nota' => '  ']));

        self::assertSame('2026-09-30', $d->getData()->format('Y-m-d'));
        self::assertNull($d->getNota());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function recusas(): iterable
    {
        yield 'tipo desconhecido' => [['tipo' => 'evento'], 'Escolha o tipo: material enviado, reunião ou parceria.'];
        yield 'sem tipo' => [[], 'Escolha o tipo: material enviado, reunião ou parceria.'];
        yield 'data no futuro' => [['tipo' => 'parceria', 'data' => '2026-10-22'], 'A data da divulgação não pode ser no futuro.'];
        yield 'data inexistente' => [['tipo' => 'parceria', 'data' => '2026-02-30'], 'Informe a data no formato dia, mês e ano.'];
        yield 'data em outro formato' => [['tipo' => 'parceria', 'data' => '21/10/2026'], 'Informe a data no formato dia, mês e ano.'];
        yield 'nota longa' => [['tipo' => 'parceria', 'nota' => str_repeat('n', 501)], 'A nota passa de 500 caracteres.'];
    }

    /** @param array<string, string> $corpo */
    #[DataProvider('recusas')]
    #[TestDox('recusa $_dataName com a mensagem clara e não grava')]
    public function testRecusa(array $corpo, string $mensagem): void
    {
        $this->divulgacoes->expects($this->never())->method('salvar');

        $this->expectException(SeccionalRecusada::class);
        $this->expectExceptionMessage($mensagem);
        $this->useCase()->executar(RegistrarDivulgacaoNaSeccionalInput::doJson($this->master, 'BA', $corpo));
    }

    #[TestDox('nota de exatamente 500 caracteres é aceita')]
    public function testNotaNoLimite(): void
    {
        $this->divulgacoes->expects($this->once())->method('salvar');

        $d = $this->useCase()->executar(new RegistrarDivulgacaoNaSeccionalInput($this->master, 'BA', 'parceria', null, str_repeat('ç', 500)));

        self::assertSame(500, mb_strlen((string) $d->getNota()));
    }

    #[TestDox('linha ausente: SeccionalNaoEncontrada e nada é gravado')]
    public function testSemLinha(): void
    {
        $this->divulgacoes->expects($this->never())->method('salvar');

        $this->expectException(SeccionalNaoEncontrada::class);
        $this->useCase()->executar(new RegistrarDivulgacaoNaSeccionalInput($this->master, 'SP', 'material'));
    }

    private function useCase(): RegistrarDivulgacaoNaSeccionalUseCase
    {
        return new RegistrarDivulgacaoNaSeccionalUseCase($this->seccionais, $this->divulgacoes, new MockClock('2026-10-21 15:30:00'), $this->escopo);
    }
}
