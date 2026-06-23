<?php

declare(strict_types=1);

namespace App\Tests\Termo\Unit;

use App\Entity\Auth\User;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarAceiteTermoUseCase::class)]
final class RegistrarAceiteTermoUseCaseTest extends TestCase
{
    #[TestDox('Registra o aceite quando o usuário ainda não aceitou a versão vigente')]
    public function testRegistraQuandoNaoExisteAceite(): void
    {
        $user = (new User())->setEmail('alguem@exemplo.com');
        $repo = $this->createMock(AceiteTermoRepository::class);

        $repo->method('existeAceiteVigente')
            ->with($user, TermoVigente::VERSAO)
            ->willReturn(false);

        $aceiteSalvo = null;
        $repo->expects(self::once())
            ->method('salvar')
            ->willReturnCallback(function (AceiteTermo $a) use (&$aceiteSalvo): void {
                $aceiteSalvo = $a;
            });

        $sut = new RegistrarAceiteTermoUseCase($repo, new TermoVigente());

        $sut->executar(new RegistrarAceiteTermoInput($user, '203.0.113.7', 'Mozilla/5.0'));

        self::assertInstanceOf(AceiteTermo::class, $aceiteSalvo);
        self::assertSame($user, $aceiteSalvo->getUser());
        self::assertSame(TermoVigente::VERSAO, $aceiteSalvo->getVersao());
        self::assertSame('203.0.113.7', $aceiteSalvo->getIp());
        self::assertSame('Mozilla/5.0', $aceiteSalvo->getUserAgent());
    }

    #[TestDox('Não duplica registro quando o usuário já aceitou a versão vigente (idempotente)')]
    public function testNaoDuplicaQuandoJaAceitou(): void
    {
        $user = (new User())->setEmail('alguem@exemplo.com');
        $repo = $this->createMock(AceiteTermoRepository::class);

        $repo->method('existeAceiteVigente')->willReturn(true);
        $repo->expects(self::never())->method('salvar');

        $sut = new RegistrarAceiteTermoUseCase($repo, new TermoVigente());

        $sut->executar(new RegistrarAceiteTermoInput($user, '203.0.113.7'));
    }
}
