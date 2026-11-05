<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\UseCase\SairDeTodosOsAparelhosUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\Shared\Contract\ManterConectadoDoUsuario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SairDeTodosOsAparelhosUseCase::class)]
final class SairDeTodosOsAparelhosUseCaseTest extends TestCase
{
    #[TestDox('Grava o corte em segundos inteiros e salva dentro da transação que apaga as séries do e-mail da pessoa')]
    public function testGravaOCorteJuntoComAsSeries(): void
    {
        $user = (new User())->setEmail('ana@adv.com');
        $dentroDaTransacao = false;
        $manterConectado = new class ($dentroDaTransacao) implements ManterConectadoDoUsuario {
            /** @var list<string> */
            public array $identificadores = [];

            public function __construct(private bool &$dentro)
            {
            }

            public function encerrarJunto(string $identificador, callable $gravar): mixed
            {
                $this->identificadores[] = $identificador;
                $this->dentro = true;
                try {
                    return $gravar();
                } finally {
                    $this->dentro = false;
                }
            }
        };
        $repositorio = $this->createMock(UserRepository::class);
        $repositorio->expects($this->once())->method('salvar')->with($user, true)
            ->willReturnCallback(static function () use (&$dentroDaTransacao): void {
                self::assertTrue($dentroDaTransacao, 'salvar acontece dentro da transação das séries');
            });

        $corte = (new SairDeTodosOsAparelhosUseCase($repositorio, $manterConectado, new MockClock('2026-11-05 10:00:00.600000')))->executar($user);

        self::assertSame('2026-11-05 10:00:00.000000', $corte->format('Y-m-d H:i:s.u'), 'sem fração: o banco arredondaria para cima');
        self::assertSame($corte, $user->getSessoesEncerradasEm());
        self::assertSame(['ana@adv.com'], $manterConectado->identificadores);
    }
}
