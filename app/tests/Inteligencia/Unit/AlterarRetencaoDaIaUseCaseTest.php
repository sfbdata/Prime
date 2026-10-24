<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\DTO\AlterarRetencaoDaIaInput;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\UseCase\AlterarRetencaoDaIaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validation;

#[CoversClass(AlterarRetencaoDaIaUseCase::class)]
#[CoversClass(AlterarRetencaoDaIaInput::class)]
final class AlterarRetencaoDaIaUseCaseTest extends TestCase
{
    #[TestDox('o Master define os meses de retenção: grava o valor e quem alterou')]
    public function testDefine(): void
    {
        $linha = new ConfiguracaoDaPlataformaDeIa();
        $repositorio = $this->createMock(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('obterParaAlterar')->willReturn($linha);
        $repositorio->expects($this->once())->method('salvar')->with($linha, true);
        $master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);

        (new AlterarRetencaoDaIaUseCase($repositorio, new MockClock()))->executar(new AlterarRetencaoDaIaInput(36), $master);

        self::assertSame(36, $linha->getMesesDeRetencao());
        self::assertSame($master, $linha->getAlteradaPor());
    }

    #[TestDox('fora de 1 a 120: o DTO acusa, e a entidade recusa de novo se escapar da validação (nada é gravado)')]
    public function testForaDaFaixa(): void
    {
        $validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        self::assertCount(1, $validador->validate(new AlterarRetencaoDaIaInput(0)));
        self::assertCount(1, $validador->validate(new AlterarRetencaoDaIaInput(121)));
        self::assertCount(0, $validador->validate(new AlterarRetencaoDaIaInput(120)));

        $repositorio = $this->createMock(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('obterParaAlterar')->willReturn(new ConfiguracaoDaPlataformaDeIa());
        $repositorio->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);
        (new AlterarRetencaoDaIaUseCase($repositorio, new MockClock()))->executar(new AlterarRetencaoDaIaInput(0), (new User())->setRoles(['ROLE_SUPER_ADMIN']));
    }

    #[TestDox('quem não é Master: AccessDenied e nada é gravado')]
    public function testNaoMaster(): void
    {
        $repositorio = $this->createMock(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);
        (new AlterarRetencaoDaIaUseCase($repositorio, new MockClock()))->executar(new AlterarRetencaoDaIaInput(12), new User());
    }
}
