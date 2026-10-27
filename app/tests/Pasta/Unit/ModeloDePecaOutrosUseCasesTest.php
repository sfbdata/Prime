<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\MateriaFavoritaDeModelo;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\ModeloDePecaFavorito;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Repository\MateriaFavoritaDeModeloRepository;
use App\Pasta\Repository\ModeloDePecaFavoritoRepository;
use App\Pasta\Repository\ModeloDePecaRepository;
use App\Pasta\UseCase\AlternarFavoritoDeModeloUseCase;
use App\Pasta\UseCase\AlternarMateriaFavoritaUseCase;
use App\Pasta\UseCase\ExcluirModeloDePecaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(ExcluirModeloDePecaUseCase::class)]
#[CoversClass(AlternarFavoritoDeModeloUseCase::class)]
#[CoversClass(AlternarMateriaFavoritaUseCase::class)]
#[Group('pasta')]
final class ModeloDePecaOutrosUseCasesTest extends TestCase
{
    #[TestDox('Excluir: remove o modelo do próprio escritório com flush')]
    public function testExcluirRemove(): void
    {
        $tenant = new Tenant();
        $modelo = new ModeloDePeca($tenant);
        $repo   = $this->createMock(ModeloDePecaRepository::class);
        $repo->expects(self::once())->method('remover')->with($modelo, true);

        (new ExcluirModeloDePecaUseCase($repo))->executar($modelo, $tenant);
    }

    #[TestDox('Excluir modelo de outro escritório: negado, nada é removido')]
    public function testExcluirOutroEscritorio(): void
    {
        $repo = $this->createMock(ModeloDePecaRepository::class);
        $repo->expects(self::never())->method('remover');

        $this->expectException(AccessDeniedException::class);
        (new ExcluirModeloDePecaUseCase($repo))->executar(new ModeloDePeca(new Tenant()), new Tenant());
    }

    #[TestDox('Favoritar modelo: sem estrela cria e devolve true')]
    public function testFavoritaModelo(): void
    {
        $tenant = new Tenant();
        $modelo = new ModeloDePeca($tenant);
        $repo   = $this->createMock(ModeloDePecaFavoritoRepository::class);
        $repo->method('buscar')->willReturn(null);
        $repo->expects(self::once())->method('salvar')->with(self::isInstanceOf(ModeloDePecaFavorito::class), true);

        self::assertTrue((new AlternarFavoritoDeModeloUseCase($repo))->executar($modelo, new User(), $tenant));
    }

    #[TestDox('Favoritar modelo: com estrela remove e devolve false')]
    public function testDesfavoritaModelo(): void
    {
        $tenant = new Tenant();
        $modelo = new ModeloDePeca($tenant);
        $usuario = new User();
        $estrela = new ModeloDePecaFavorito($tenant, $usuario, $modelo);
        $repo    = $this->createMock(ModeloDePecaFavoritoRepository::class);
        $repo->method('buscar')->willReturn($estrela);
        $repo->expects(self::once())->method('remover')->with($estrela, true);

        self::assertFalse((new AlternarFavoritoDeModeloUseCase($repo))->executar($modelo, $usuario, $tenant));
    }

    #[TestDox('Favoritar modelo de outro escritório: negado')]
    public function testFavoritarOutroEscritorio(): void
    {
        $repo = $this->createMock(ModeloDePecaFavoritoRepository::class);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(AccessDeniedException::class);
        (new AlternarFavoritoDeModeloUseCase($repo))->executar(new ModeloDePeca(new Tenant()), new User(), new Tenant());
    }

    #[TestDox('Matéria favorita: alterna criando e removendo')]
    public function testAlternaMateria(): void
    {
        $tenant  = new Tenant();
        $usuario = new User();
        $repo    = $this->createMock(MateriaFavoritaDeModeloRepository::class);
        $existente = new MateriaFavoritaDeModelo($tenant, $usuario, MateriaDeModelo::Saude);
        $repo->method('buscar')->willReturnOnConsecutiveCalls(null, $existente);
        $repo->expects(self::once())->method('salvar');
        $repo->expects(self::once())->method('remover')->with($existente, true);

        $caso = new AlternarMateriaFavoritaUseCase($repo);

        self::assertTrue($caso->executar(MateriaDeModelo::Saude, $usuario, $tenant));
        self::assertFalse($caso->executar(MateriaDeModelo::Saude, $usuario, $tenant));
    }
}
