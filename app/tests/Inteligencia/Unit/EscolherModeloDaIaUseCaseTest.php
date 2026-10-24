<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Entity\Auth\User;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\UseCase\EscolherModeloDaIaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(EscolherModeloDaIaUseCase::class)]
final class EscolherModeloDaIaUseCaseTest extends TestCase
{
    #[TestDox('o Master escolhe o modelo: grava o valor do enum e quem escolheu')]
    public function testEscolhe(): void
    {
        $linha = new ConfiguracaoDaPlataformaDeIa();
        $repositorio = $this->createMock(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('obterParaAlterar')->willReturn($linha);
        $repositorio->expects($this->once())->method('salvar')->with($linha, true);
        $master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);

        (new EscolherModeloDaIaUseCase($repositorio, new MockClock(), new EscopoDeAuditoriaDePlataforma()))->executar(ModeloDeIa::Sonnet55, $master);

        self::assertSame('claude-sonnet-5-5', $linha->getModelo());
        self::assertSame($master, $linha->getAlteradaPor());
    }

    #[TestDox('quem não é Master: AccessDenied e nada é gravado')]
    public function testNaoMaster(): void
    {
        $repositorio = $this->createMock(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);
        (new EscolherModeloDaIaUseCase($repositorio, new MockClock(), new EscopoDeAuditoriaDePlataforma()))->executar(ModeloDeIa::Opus55, new User());
    }
}
