<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ModeloDePecaInput;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\TipoDePeca;
use App\Pasta\Exception\ModeloDePecaInvalidoException;
use App\Pasta\Repository\ModeloDePecaRepository;
use App\Pasta\Service\SanitizadorDeModeloDePeca;
use App\Pasta\UseCase\SalvarModeloDePecaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(SalvarModeloDePecaUseCase::class)]
#[Group('pasta')]
final class SalvarModeloDePecaUseCaseTest extends TestCase
{
    private function useCase(?ModeloDePecaRepository $repo = null): SalvarModeloDePecaUseCase
    {
        return new SalvarModeloDePecaUseCase($repo ?? $this->createStub(ModeloDePecaRepository::class), new SanitizadorDeModeloDePeca());
    }

    private function input(string $titulo = 'Cobrança de cotas', string $materia = 'condominial', string $tipo = 'peticao_inicial', string $corpo = '<p>Texto</p>'): ModeloDePecaInput
    {
        return new ModeloDePecaInput($titulo, $materia, $tipo, $corpo);
    }

    #[TestDox('Novo modelo: grava no escritório, com o autor, aparando o título, e salva com flush')]
    public function testCriaModeloDoEscritorio(): void
    {
        $tenant = new Tenant();
        $autor  = new User();
        $repo   = $this->createMock(ModeloDePecaRepository::class);
        $repo->expects(self::once())->method('salvar')->with(self::isInstanceOf(ModeloDePeca::class), true);

        $modelo = $this->useCase($repo)->executar($this->input(titulo: '  Cobrança de cotas  '), $tenant, $autor);

        self::assertSame($tenant, $modelo->getTenant());
        self::assertSame($autor, $modelo->getAutor());
        self::assertSame('Cobrança de cotas', $modelo->getTitulo());
        self::assertSame(MateriaDeModelo::Condominial, $modelo->getMateria());
        self::assertSame(TipoDePeca::PeticaoInicial, $modelo->getTipo());
        self::assertSame(0, $modelo->getUsos());
    }

    #[TestDox('Editar: muda o conteúdo e mantém o autor original')]
    public function testEditaSemTrocarAutor(): void
    {
        $tenant   = new Tenant();
        $original = new User();
        $existente = (new ModeloDePeca($tenant))->setAutor($original);

        $modelo = $this->useCase()->executar($this->input(titulo: 'Novo nome', tipo: 'contestacao'), $tenant, new User(), $existente);

        self::assertSame($existente, $modelo);
        self::assertSame($original, $modelo->getAutor());
        self::assertSame('Novo nome', $modelo->getTitulo());
        self::assertSame(TipoDePeca::Contestacao, $modelo->getTipo());
    }

    #[TestDox('Modelo de outro escritório: acesso negado, nada é salvo')]
    public function testOutroEscritorioNegado(): void
    {
        $repo = $this->createMock(ModeloDePecaRepository::class);
        $repo->expects(self::never())->method('salvar');
        $deOutro = new ModeloDePeca(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->useCase($repo)->executar($this->input(), new Tenant(), new User(), $deOutro);
    }

    /** @return iterable<string, array{0: ModeloDePecaInput, 1: string}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'título vazio' => [new ModeloDePecaInput('   ', 'civel', 'parecer', '<p>x</p>'), 'Informe o título.'];
        yield 'matéria desconhecida' => [new ModeloDePecaInput('T', 'astrologia', 'parecer', '<p>x</p>'), 'Escolha uma matéria da lista.'];
        yield 'tipo desconhecido' => [new ModeloDePecaInput('T', 'civel', 'poema', '<p>x</p>'), 'Escolha um tipo de peça da lista.'];
        yield 'corpo vazio' => [new ModeloDePecaInput('T', 'civel', 'parecer', ''), 'Escreva o texto do modelo.'];
        yield 'corpo só com tags e espaços' => [new ModeloDePecaInput('T', 'civel', 'parecer', '<p>&nbsp;</p><br>'), 'Escreva o texto do modelo.'];
        yield 'corpo só de script' => [new ModeloDePecaInput('T', 'civel', 'parecer', '<script>alert(1)</script>'), 'Escreva o texto do modelo.'];
    }

    #[DataProvider('entradasInvalidas')]
    #[TestDox('Entrada inválida ($_dataName): recusa com a mensagem da tela e não salva')]
    public function testEntradaInvalida(ModeloDePecaInput $input, string $mensagem): void
    {
        $repo = $this->createMock(ModeloDePecaRepository::class);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(ModeloDePecaInvalidoException::class);
        $this->expectExceptionMessage($mensagem);
        $this->useCase($repo)->executar($input, new Tenant(), new User());
    }

    #[TestDox('O corpo é sanitizado no servidor: script, evento e iframe caem; o texto fica')]
    public function testSanitizaCorpo(): void
    {
        $corpo = '<p onclick="x()">Olá <b>mundo</b></p><script>alert(1)</script><iframe src="http://x"></iframe>';

        $modelo = $this->useCase()->executar($this->input(corpo: $corpo), new Tenant(), new User());

        self::assertSame('<p>Olá <b>mundo</b></p>', $modelo->getCorpo());
    }
}
