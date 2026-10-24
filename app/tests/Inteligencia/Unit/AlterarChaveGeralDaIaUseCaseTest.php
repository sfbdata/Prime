<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Inteligencia\UseCase\AlterarChaveGeralDaIaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(AlterarChaveGeralDaIaUseCase::class)]
#[CoversClass(GuardaDoMasterDaIa::class)]
final class AlterarChaveGeralDaIaUseCaseTest extends TestCase
{
    private static function master(): User
    {
        return (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('o Master liga e desliga: grava quem e quando, com um flush')]
    public function testLigaEDesliga(): void
    {
        $linha = new ConfiguracaoDaPlataformaDeIa();
        $repositorio = $this->createMock(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('obterParaAlterar')->willReturn($linha);
        $repositorio->expects($this->exactly(2))->method('salvar')->with($linha, true);
        $sut = new AlterarChaveGeralDaIaUseCase($repositorio, new MockClock('2026-10-24 09:00:00'));
        $master = self::master();

        $sut->executar(true, $master);
        self::assertTrue($linha->isLigadaParaTodos());
        self::assertSame($master, $linha->getAlteradaPor());
        self::assertSame('2026-10-24 09:00', $linha->getAlteradaEm()->format('Y-m-d H:i'));

        $sut->executar(false, $master);
        self::assertFalse($linha->isLigadaParaTodos());
    }

    #[TestDox('quem não é Master: AccessDenied e nada é gravado')]
    public function testNaoMaster(): void
    {
        $repositorio = $this->createMock(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->expects($this->never())->method('obterParaAlterar');
        $repositorio->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);
        (new AlterarChaveGeralDaIaUseCase($repositorio, new MockClock()))->executar(true, (new User())->setRoles(['ROLE_ADMIN']));
    }
}
