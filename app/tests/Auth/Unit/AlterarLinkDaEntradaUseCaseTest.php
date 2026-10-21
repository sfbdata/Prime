<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\AlterarLinkDaEntradaInput;
use App\Auth\Entity\AjusteEntrada;
use App\Auth\Repository\AjusteEntradaRepository;
use App\Auth\UseCase\AlterarLinkDaEntradaUseCase;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlterarLinkDaEntradaUseCase::class)]
final class AlterarLinkDaEntradaUseCaseTest extends TestCase
{
    private function input(string $acao, ?string $url = null, string $chave = 'linkedin'): AlterarLinkDaEntradaInput
    {
        return new AlterarLinkDaEntradaInput($chave, $acao, $url, new User());
    }

    #[TestDox('Salvar grava o endereço aparado num ajuste novo, com quem alterou')]
    public function testSalvarCriaAjusteComEndereco(): void
    {
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->method('daChave')->with('link.linkedin')->willReturn(null);
        $gravado = null;
        $repo->expects(self::once())->method('salvar')
            ->with(self::callback(static function (AjusteEntrada $a) use (&$gravado): bool {
                $gravado = $a;

                return true;
            }), true);

        $input = $this->input('salvar', '  https://www.linkedin.com/company/bluejus  ');
        (new AlterarLinkDaEntradaUseCase($repo))->executar($input);

        self::assertInstanceOf(AjusteEntrada::class, $gravado);
        self::assertSame('link.linkedin', $gravado->getChave());
        self::assertSame('https://www.linkedin.com/company/bluejus', $gravado->getValor());
        self::assertSame($input->alteradoPor, $gravado->getAlteradoPor());
        self::assertFalse($gravado->isOculto());
    }

    #[TestDox('Salvar troca o endereço do ajuste existente sem mexer no oculto')]
    public function testSalvarAtualizaAjusteExistente(): void
    {
        $existente = new AjusteEntrada('link.termos');
        $existente->definirOculto(true, new User(), new \DateTimeImmutable('-1 day'));
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->method('daChave')->willReturn($existente);
        $repo->expects(self::once())->method('salvar')->with($existente, true);

        (new AlterarLinkDaEntradaUseCase($repo))->executar($this->input('salvar', 'http://exemplo.com.br/termos', 'termos'));

        self::assertSame('http://exemplo.com.br/termos', $existente->getValor());
        self::assertTrue($existente->isOculto());
    }

    /** @return iterable<string, array{string}> */
    public static function enderecosInvalidos(): iterable
    {
        yield 'vazio' => [''];
        yield 'sem esquema' => ['www.bluejus.com.br'];
        yield 'outro esquema' => ['ftp://bluejus.com.br'];
        yield 'sem ponto' => ['https://localhost'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'com espaço' => ['https://blue jus.com.br'];
    }

    #[DataProvider('enderecosInvalidos')]
    #[TestDox('Endereço fora do padrão do desenho é recusado com o texto exato e nada é gravado')]
    public function testSalvarRecusaEnderecoInvalido(string $url): void
    {
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Informe um endereço começando com https://');

        (new AlterarLinkDaEntradaUseCase($repo))->executar($this->input('salvar', $url));
    }

    #[TestDox('Restaurar apaga o ajuste da chave')]
    public function testRestaurarRemoveAjuste(): void
    {
        $existente = new AjusteEntrada('link.priv');
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->method('daChave')->with('link.priv')->willReturn($existente);
        $repo->expects(self::once())->method('remover')->with($existente, true);
        $repo->expects(self::never())->method('salvar');

        (new AlterarLinkDaEntradaUseCase($repo))->executar($this->input('restaurar', null, 'priv'));
    }

    #[TestDox('Restaurar sem ajuste gravado não faz nada')]
    public function testRestaurarSemAjusteNaoFazNada(): void
    {
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->method('daChave')->willReturn(null);
        $repo->expects(self::never())->method('remover');
        $repo->expects(self::never())->method('salvar');

        (new AlterarLinkDaEntradaUseCase($repo))->executar($this->input('restaurar'));
    }

    #[TestDox('Ocultar cria o ajuste oculto, sem endereço (continua o padrão)')]
    public function testOcultarCriaAjusteOculto(): void
    {
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->method('daChave')->willReturn(null);
        $gravado = null;
        $repo->expects(self::once())->method('salvar')
            ->with(self::callback(static function (AjusteEntrada $a) use (&$gravado): bool {
                $gravado = $a;

                return true;
            }), true);

        (new AlterarLinkDaEntradaUseCase($repo))->executar($this->input('ocultar', null, 'termos'));

        self::assertInstanceOf(AjusteEntrada::class, $gravado);
        self::assertTrue($gravado->isOculto());
        self::assertNull($gravado->getValor());
    }

    #[TestDox('Mostrar desfaz o oculto e mantém o endereço gravado')]
    public function testMostrarDesfazOculto(): void
    {
        $existente = new AjusteEntrada('link.instagram');
        $existente->definirValor('https://instagram.com/bluejus', new User(), new \DateTimeImmutable());
        $existente->definirOculto(true, new User(), new \DateTimeImmutable());
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->method('daChave')->willReturn($existente);
        $repo->expects(self::once())->method('salvar')->with($existente, true);

        (new AlterarLinkDaEntradaUseCase($repo))->executar($this->input('mostrar', null, 'instagram'));

        self::assertFalse($existente->isOculto());
        self::assertSame('https://instagram.com/bluejus', $existente->getValor());
    }

    #[TestDox('Ação desconhecida é recusada')]
    public function testAcaoDesconhecida(): void
    {
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->expects(self::never())->method('salvar');
        $repo->expects(self::never())->method('remover');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ação inválida.');

        (new AlterarLinkDaEntradaUseCase($repo))->executar($this->input('apagar'));
    }

    #[TestDox('Link fora dos quatro do rodapé é recusado')]
    public function testLinkDesconhecido(): void
    {
        $repo = $this->createMock(AjusteEntradaRepository::class);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(\DomainException::class);

        (new AlterarLinkDaEntradaUseCase($repo))->executar($this->input('ocultar', null, 'suporte'));
    }
}
