<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\MencionavelOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\UseCase\ListarMencionaveisDaPastaUseCase;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarMencionaveisDaPastaUseCase::class)]
#[CoversClass(MencionavelOutput::class)]
final class ListarMencionaveisDaPastaUseCaseTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private PermissionChecker&MockObject $permissionChecker;
    private ListarMencionaveisDaPastaUseCase $useCase;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $eu;
    /** @var array<int, bool> userId => pode ver a pasta 41 */
    private array $acesso = [];

    protected function setUp(): void
    {
        $this->userRepository    = $this->createMock(UserRepository::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->useCase           = new ListarMencionaveisDaPastaUseCase($this->userRepository, $this->permissionChecker);

        $this->tenant = $this->definirId(new Tenant(), 7);
        $this->pasta  = $this->definirId(new Pasta(), 41);
        $this->pasta->setTenant($this->tenant);
        $this->eu     = $this->usuario(1, 'Eu Mesmo');
        $this->acesso = [1 => true];

        $this->permissionChecker->method('canAccessResource')->willReturnCallback(
            function (User $u, ?Tenant $t, string $tipo, int $id, string $acao): bool {
                self::assertSame($this->tenant, $t);
                self::assertSame(AccessRequest::RESOURCE_PASTA, $tipo);
                self::assertSame(41, $id);
                self::assertSame(AccessRequest::ACTION_VIEW, $acao);

                return $this->acesso[(int) $u->getId()] ?? false;
            },
        );
        $this->userRepository->method('findCargoPorColaboradores')->willReturn([2 => 'Advogada']);
        $this->userRepository->method('findFotoPorColaboradores')->willReturn([2 => 'ana.jpg', 3 => null]);
    }

    /** @param list<User> $colegas */
    private function colegas(array $colegas): void
    {
        $this->userRepository->method('findColaboradoresAtivosPorTenant')
            ->with($this->identicalTo($this->tenant))
            ->willReturn($colegas);
    }

    private function usuario(int $id, string $nome, bool $ativo = true): User
    {
        $u = (new User())->setEmail('u' . $id . '@test.com')->setFullName($nome);
        $u->setIsActive($ativo);

        return $this->definirId($u, $id);
    }

    /** @param list<MencionavelOutput> $lista @return list<int> */
    private static function ids(array $lista): array
    {
        return array_map(static fn (MencionavelOutput $p): int => $p->id, $lista);
    }

    #[TestDox('Lista só colegas com acesso à pasta, sem quem pergunta, em ordem, com iniciais, cargo e foto — sem e-mail')]
    public function testListaColegasComAcesso(): void
    {
        $this->colegas([
            $this->usuario(2, 'Ana Paula Souza'),
            $this->eu,
            $this->usuario(3, 'Bruno Lima'),
            $this->usuario(4, 'Carla Sem Acesso'),
        ]);
        $this->acesso += [2 => true, 3 => true, 4 => false];

        $lista = $this->useCase->executar($this->pasta, $this->eu, $this->tenant, '');

        self::assertSame([2, 3], self::ids($lista));
        self::assertSame('Ana Paula Souza', $lista[0]->nome);
        self::assertSame('AS', $lista[0]->iniciais);
        self::assertSame('Advogada', $lista[0]->cargo);
        self::assertSame('ana.jpg', $lista[0]->foto);
        self::assertNull($lista[1]->cargo);
        self::assertSame(['id', 'nome', 'iniciais', 'cargo'], array_keys($lista[0]->paraArray()));
        self::assertStringNotContainsString('@test.com', (string) json_encode(array_map(static fn ($p) => $p->paraArray(), $lista)));
    }

    #[TestDox('Busca pelo começo do nome ou de qualquer palavra, sem acento e sem caixa')]
    public function testBuscaPorPalavraSemAcento(): void
    {
        $this->colegas([
            $this->usuario(2, 'Ana Paula Souza'),
            $this->usuario(3, 'Ângela Lima'),
            $this->usuario(5, 'Mariana Costa'),
        ]);
        $this->acesso += [2 => true, 3 => true, 5 => true];

        self::assertSame([2, 3], self::ids($this->useCase->executar($this->pasta, $this->eu, $this->tenant, 'an')), '"an" casa Ana e Ângela, não o meio de Mariana');
        self::assertSame([2], self::ids($this->useCase->executar($this->pasta, $this->eu, $this->tenant, 'SOU')));
        self::assertSame([3], self::ids($this->useCase->executar($this->pasta, $this->eu, $this->tenant, 'angela')));
        self::assertSame([], self::ids($this->useCase->executar($this->pasta, $this->eu, $this->tenant, 'xyz')));
    }

    #[TestDox('Usuário inativo e nome vazio ficam de fora')]
    public function testInativoENomeVazioFicamDeFora(): void
    {
        $this->colegas([$this->usuario(2, 'Ana', ativo: false), $this->usuario(3, '  ')]);
        $this->acesso += [2 => true, 3 => true];

        self::assertSame([], $this->useCase->executar($this->pasta, $this->eu, $this->tenant, ''));
    }

    #[TestDox('No máximo 7 pessoas (o desenho)')]
    public function testLimite(): void
    {
        $colegas = [];
        for ($i = 10; $i < 20; ++$i) {
            $colegas[] = $this->usuario($i, 'Pessoa ' . $i);
            $this->acesso[$i] = true;
        }
        $this->colegas($colegas);

        self::assertCount(ListarMencionaveisDaPastaUseCase::LIMITE, $this->useCase->executar($this->pasta, $this->eu, $this->tenant, ''));
    }

    #[TestDox('Pasta de outro escritório: recusa (404) sem listar ninguém')]
    public function testPastaDeOutroEscritorio(): void
    {
        $this->pasta->setTenant($this->definirId(new Tenant(), 8));
        $this->userRepository->expects($this->never())->method('findColaboradoresAtivosPorTenant');

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase->executar($this->pasta, $this->eu, $this->tenant, '');
    }

    #[TestDox('Quem pergunta sem acesso à pasta: recusa (403) sem listar ninguém')]
    public function testSolicitanteSemAcesso(): void
    {
        $this->acesso[1] = false;
        $this->userRepository->expects($this->never())->method('findColaboradoresAtivosPorTenant');

        $this->expectException(SemPermissaoParaVerPastaException::class);

        $this->useCase->executar($this->pasta, $this->eu, $this->tenant, '');
    }

    /**
     * @template T of object
     * @param T $entidade
     * @return T
     */
    private function definirId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
