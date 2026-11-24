<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\UseCase\AlterarTetoPadraoDaIaUseCase;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** O Master define o teto padrão da I.A. ligada pelo dono (spec docs/specs/multiproduto-contratacao-e-voto.md, §10). */
#[CoversClass(AlterarTetoPadraoDaIaUseCase::class)]
#[CoversClass(ConfiguracaoGlobal::class)]
final class AlterarTetoPadraoDaIaUseCaseTest extends TestCase
{
    private const AGORA = '2026-11-23 10:00:00';

    private static function master(): User
    {
        return (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    private function sut(ConfiguracaoGlobalRepository $repositorio): AlterarTetoPadraoDaIaUseCase
    {
        return new AlterarTetoPadraoDaIaUseCase($repositorio, new EscopoDeAuditoriaDePlataforma(), new MockClock(new \DateTimeImmutable(self::AGORA)));
    }

    #[TestDox('sem linha global ainda: cria a linha, grava o teto em centavos e registra quem e quando')]
    public function testCriaALinha(): void
    {
        $repositorio = $this->createMock(ConfiguracaoGlobalRepository::class);
        $repositorio->method('buscarUnica')->willReturn(null);
        $gravada = null;
        $repositorio->expects($this->once())->method('salvar')->with($this->callback(static function (ConfiguracaoGlobal $linha) use (&$gravada): bool {
            $gravada = $linha;

            return true;
        }), true);
        $master = self::master();

        $this->sut($repositorio)->executar(750, $master);

        self::assertNotNull($gravada);
        self::assertSame(750, $gravada->getTetoPadraoDaIa());
        self::assertSame($master, $gravada->getAlteradoPor());
        self::assertSame(self::AGORA, $gravada->getAlteradoEm()->format('Y-m-d H:i:s'));
    }

    #[TestDox('com a linha global já gravada: troca só o teto e preserva o resto (campos do catálogo e outras chaves fora dele)')]
    public function testPreservaOResto(): void
    {
        $linha = new ConfiguracaoGlobal();
        $linha->definirEnvioDeCampanhas(true, null, new \DateTimeImmutable('-1 day'));
        $linha->definirValores(['manutencao' => '0'], null, new \DateTimeImmutable('-1 day'));
        $repositorio = $this->createMock(ConfiguracaoGlobalRepository::class);
        $repositorio->method('buscarUnica')->willReturn($linha);
        $repositorio->expects($this->once())->method('salvar')->with($linha, true);

        $this->sut($repositorio)->executar(1234, self::master());

        self::assertSame(1234, $linha->getTetoPadraoDaIa());
        self::assertTrue($linha->getEnvioDeCampanhas());
        self::assertSame(['manutencao' => '0'], $linha->getValores(), 'a chave `ia.` fica fora do catálogo');
        $linha->definirValores(['manutencao' => '1'], null, new \DateTimeImmutable());
        self::assertSame(1234, $linha->getTetoPadraoDaIa(), 'salvar o catálogo não apaga o teto');
    }

    /** @return iterable<string, array{int}> */
    public static function foraDaFaixa(): iterable
    {
        yield 'negativo' => [-1];
        yield 'um centavo acima do máximo' => [ConfiguracaoDeInteligencia::ORCAMENTO_MAXIMO_CENTAVOS + 1];
    }

    #[DataProvider('foraDaFaixa')]
    #[TestDox('fora da faixa de 0 a US$ 100.000,00: recusa e nada é gravado')]
    public function testForaDaFaixa(int $centavos): void
    {
        $repositorio = $this->createMock(ConfiguracaoGlobalRepository::class);
        $repositorio->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);
        $this->sut($repositorio)->executar($centavos, self::master());
    }

    #[TestDox('os dois limites da faixa passam')]
    public function testLimites(): void
    {
        $repositorio = $this->createMock(ConfiguracaoGlobalRepository::class);
        $repositorio->method('buscarUnica')->willReturn(null);
        $repositorio->expects($this->exactly(2))->method('salvar');
        $sut = $this->sut($repositorio);

        $sut->executar(0, self::master());
        $sut->executar(ConfiguracaoDeInteligencia::ORCAMENTO_MAXIMO_CENTAVOS, self::master());
    }

    #[TestDox('quem não é Master: AccessDenied e nada é gravado')]
    public function testNaoMaster(): void
    {
        $repositorio = $this->createMock(ConfiguracaoGlobalRepository::class);
        $repositorio->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);
        $this->sut($repositorio)->executar(500, new User());
    }
}
