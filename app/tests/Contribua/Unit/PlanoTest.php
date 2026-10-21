<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\AlterarPlanoInput;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\UseCase\AlterarPlanoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plano::class)]
#[CoversClass(AlterarPlanoUseCase::class)]
#[CoversClass(RetratoDoEspaco::class)]
final class PlanoTest extends TestCase
{
    #[TestDox('O Master muda preço, espaço e limite de usuários; nulo = sem limite')]
    public function testAlterar(): void
    {
        $plano = new Plano('essencial', 'Essencial', 20, 1990, 'Pequeno', 2, 8);
        $repositorio = $this->createMock(PlanoRepository::class);
        $repositorio->method('findOneBy')->willReturn($plano);
        $repositorio->expects(self::once())->method('salvar')->with($plano, true);

        (new AlterarPlanoUseCase($repositorio))->executar(new AlterarPlanoInput('essencial', ' Essencial+ ', 25, 2490, null, 'Pequeno ', true));

        self::assertSame(['Essencial+', 25, 2490, null, 'Pequeno'], [$plano->getNome(), $plano->getCotaGb(), $plano->getPrecoCentavos(), $plano->getLimiteUsuarios(), $plano->getDescricao()]);
    }

    /** @return iterable<string, array{string, int, int, ?int, bool, string}> */
    public static function invalidos(): iterable
    {
        yield 'sem nome' => ['essencial', 20, 1990, 8, true, 'Informe o nome do plano.'];
        yield 'espaço zero' => ['essencial', 0, 1990, 8, true, 'O espaço do plano precisa ser maior que zero.'];
        yield 'preço negativo' => ['essencial', 20, -1, 8, true, 'O preço não pode ser negativo.'];
        yield 'usuários zero' => ['essencial', 20, 1990, 0, true, 'O limite de usuários precisa ser maior que zero, ou vazio para sem limite.'];
        yield 'Free pago' => ['free', 10, 990, 3, true, 'O plano Free é o de toda conta nova: continua grátis e ativo.'];
        yield 'Free inativo' => ['free', 10, 0, 3, false, 'O plano Free é o de toda conta nova: continua grátis e ativo.'];
    }

    #[TestDox('Recusa: $caso')]
    #[DataProvider('invalidos')]
    public function testRecusa(string $codigo, int $cota, int $preco, ?int $usuarios, bool $ativo, string $mensagem): void
    {
        $plano = new Plano($codigo, 'Plano', 10, 0, 'x', 1, 3);
        $this->expectExceptionMessage($mensagem);

        $plano->alterar($codigo === 'essencial' && $mensagem === 'Informe o nome do plano.' ? '  ' : 'Plano', $cota, $preco, $usuarios, 'x', $ativo);
    }

    #[TestDox('Plano inexistente: recusa com texto para a tela')]
    public function testPlanoInexistente(): void
    {
        $repositorio = $this->createStub(PlanoRepository::class);
        $repositorio->method('findOneBy')->willReturn(null);
        $this->expectExceptionMessage('Plano não encontrado.');

        (new AlterarPlanoUseCase($repositorio))->executar(new AlterarPlanoInput('nao-existe', 'X', 1, 0, null, 'x', true));
    }

    #[TestDox('Limite de usuários só trava com o bloqueio ligado: ninguém é travado no deploy')]
    public function testLimiteDeUsuariosSoComBloqueioLigado(): void
    {
        $desligado = self::retrato(limiteUsuarios: 3, bloqueioAtivo: false);
        $ligado = self::retrato(limiteUsuarios: 3, bloqueioAtivo: true);
        $semLimite = self::retrato(limiteUsuarios: null, bloqueioAtivo: true);

        self::assertTrue($desligado->aceitaMaisUmUsuario(11), 'com a chave desligada, 11 usuários num Free de 3 continuam convidando');
        self::assertTrue($ligado->aceitaMaisUmUsuario(2));
        self::assertFalse($ligado->aceitaMaisUmUsuario(3));
        self::assertTrue($semLimite->aceitaMaisUmUsuario(500));
    }

    private static function retrato(?int $limiteUsuarios, bool $bloqueioAtivo): RetratoDoEspaco
    {
        return new RetratoDoEspaco(7, 0, 10, 0, FaixaDoEspaco::Livre, OrigemDoLimite::Plano, 'free', 'Free', $limiteUsuarios, OrigemDoLimite::Plano, $bloqueioAtivo);
    }
}
