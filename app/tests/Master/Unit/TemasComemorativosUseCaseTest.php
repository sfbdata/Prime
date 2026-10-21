<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\AlternarTemaInput;
use App\Master\DTO\DefinirModoDoTemaInput;
use App\Master\Entity\TemaConfig;
use App\Master\Exception\TemaRecusado;
use App\Master\Repository\TemaConfigRepository;
use App\Master\UseCase\AlternarTemaComemorativoUseCase;
use App\Master\UseCase\DefinirModoDoTemaUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Salvar modo e tema fixado; ligar e desligar um tema (spec docs/specs/temas-comemorativos.md, "Controle").
 */
#[CoversClass(DefinirModoDoTemaUseCase::class)]
#[CoversClass(AlternarTemaComemorativoUseCase::class)]
#[CoversClass(DefinirModoDoTemaInput::class)]
#[CoversClass(AlternarTemaInput::class)]
final class TemasComemorativosUseCaseTest extends TestCase
{
    private TemaConfigRepository&MockObject $repositorio;
    private MockClock $relogio;
    private User $master;
    private EscopoDeAuditoriaDePlataforma $escopo;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(TemaConfigRepository::class);
        $this->relogio = new MockClock('2026-10-21 10:00:00');
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
    }

    #[TestDox('modo manual com tema: grava modo, tema, quem e quando, no escopo de plataforma')]
    public function testDefinirManual(): void
    {
        $this->repositorio->method('buscarUnica')->willReturn(null);
        $gravada = null;
        $this->repositorio->expects(self::once())->method('salvar')
            ->willReturnCallback(function (TemaConfig $c, bool $flush) use (&$gravada): void {
                self::assertTrue($flush);
                self::assertTrue($this->escopo->estaLigado(), 'grava dentro do escopo de plataforma');
                $gravada = $c;
            });
        $config = $this->definir()->executar(new DefinirModoDoTemaInput($this->master, 'manual', 'natal'));

        self::assertSame($config, $gravada);
        self::assertSame('manual', $config->getModo());
        self::assertSame('natal', $config->getManual());
        self::assertSame($this->master, $config->getAlteradoPor());
        self::assertSame('2026-10-21 10:00:00', $config->getAlteradoEm()->format('Y-m-d H:i:s'));
        self::assertFalse($this->escopo->estaLigado(), 'o escopo fecha depois do flush');
    }

    #[TestDox('modo inválido e tema desconhecido são recusados sem gravar')]
    public function testDefinirRecusa(): void
    {
        $this->repositorio->expects(self::never())->method('salvar');

        foreach ([new DefinirModoDoTemaInput($this->master, 'festa'), new DefinirModoDoTemaInput($this->master, 'manual', 'carnaval')] as $input) {
            try {
                $this->definir()->executar($input);
                self::fail('deveria recusar');
            } catch (TemaRecusado $e) {
                self::assertContains($e->getMessage(), ['Modo de tema inválido.', 'Tema desconhecido.']);
            }
        }
    }

    #[TestDox('pedido igual ao que já vale não grava (nem o automático sem linha)')]
    public function testDefinirSemMudanca(): void
    {
        $existente = new TemaConfig();
        $existente->definirModo('desligado', null, null, new \DateTimeImmutable('2026-01-01'));
        $this->repositorio->method('buscarUnica')->willReturnOnConsecutiveCalls($existente, null);
        $this->repositorio->expects(self::never())->method('salvar');

        self::assertSame($existente, $this->definir()->executar(new DefinirModoDoTemaInput($this->master, 'desligado')));
        self::assertSame('automatico', $this->definir()->executar(new DefinirModoDoTemaInput($this->master, 'automatico'))->getModo());
    }

    #[TestDox('doJson: manual vazio vira nenhum; modo que não é texto vira vazio (recusado no UseCase)')]
    public function testDoJson(): void
    {
        $input = DefinirModoDoTemaInput::doJson($this->master, ['modo' => 'manual', 'manual' => '  ']);
        self::assertSame('manual', $input->modo);
        self::assertNull($input->manual);

        self::assertSame('', DefinirModoDoTemaInput::doJson(null, ['modo' => 3])->modo);
        self::assertNull(AlternarTemaInput::doJson(null, 'natal', ['ligado' => 'sim']));
        self::assertFalse(AlternarTemaInput::doJson(null, 'natal', ['ligado' => false])?->ligado);
    }

    #[TestDox('desligar um tema grava a lista nova com quem e quando')]
    public function testDesligar(): void
    {
        $this->repositorio->method('buscarUnica')->willReturn(null);
        $this->repositorio->expects(self::once())->method('salvar');

        $config = $this->alternar()->executar(new AlternarTemaInput($this->master, 'outrosa', false));

        self::assertSame(['outrosa'], $config->getDesligados());
        self::assertFalse($config->estaLigado('outrosa'));
        self::assertSame($this->master, $config->getAlteradoPor());
    }

    #[TestDox('religar o que já está ligado (ou sem linha) não grava')]
    public function testReligarSemMudanca(): void
    {
        $existente = new TemaConfig();
        $this->repositorio->method('buscarUnica')->willReturnOnConsecutiveCalls($existente, null);
        $this->repositorio->expects(self::never())->method('salvar');

        self::assertSame($existente, $this->alternar()->executar(new AlternarTemaInput($this->master, 'natal', true)));
        self::assertTrue($this->alternar()->executar(new AlternarTemaInput($this->master, 'natal', true))->estaLigado('natal'));
    }

    #[TestDox('religar um desligado tira da lista')]
    public function testReligar(): void
    {
        $existente = new TemaConfig();
        $existente->ligarTema('natal', false, null, new \DateTimeImmutable('2026-01-01'));
        $this->repositorio->method('buscarUnica')->willReturn($existente);
        $this->repositorio->expects(self::once())->method('salvar')->with($existente, true);

        $config = $this->alternar()->executar(new AlternarTemaInput($this->master, 'natal', true));

        self::assertSame([], $config->getDesligados());
    }

    #[TestDox('tema desconhecido é recusado sem gravar')]
    public function testAlternarDesconhecido(): void
    {
        $this->repositorio->expects(self::never())->method('salvar');
        $this->expectException(TemaRecusado::class);
        $this->expectExceptionMessage('Tema desconhecido.');

        $this->alternar()->executar(new AlternarTemaInput($this->master, 'carnaval', false));
    }

    private function definir(): DefinirModoDoTemaUseCase
    {
        return new DefinirModoDoTemaUseCase($this->repositorio, $this->relogio, $this->escopo);
    }

    private function alternar(): AlternarTemaComemorativoUseCase
    {
        return new AlternarTemaComemorativoUseCase($this->repositorio, $this->relogio, $this->escopo);
    }
}
