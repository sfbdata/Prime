<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaNaoExcluivelException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\UseCase\ExcluirNotaTecnicaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirNotaTecnicaUseCase::class)]
final class ExcluirNotaTecnicaUseCaseTest extends TestCase
{
    private NotaTecnicaRepository&MockObject $repository;
    private ExcluirNotaTecnicaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(NotaTecnicaRepository::class);
        $this->useCase    = new ExcluirNotaTecnicaUseCase($this->repository, new JanelaDeEdicaoDeComentario());
        $this->tenant     = new Tenant();
        $this->autor      = (new User())->setEmail('autor@test.com');
    }

    private function novaNota(): NotaTecnica
    {
        return new NotaTecnica($this->tenant, (new Processo())->setTenant($this->tenant), $this->autor, '<p>Original</p>');
    }

    #[TestDox('o autor, dentro da janela, exclui de fato (remover + flush)')]
    public function testAutorDentroDaJanelaExclui(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->once())->method('remover')->with($nota, true);

        $this->useCase->executar($nota, $this->autor, $this->tenant);
    }

    #[TestDox('quem não é o autor não exclui')]
    public function testNaoAutorLancaExcecao(): void
    {
        $nota  = $this->novaNota();
        $outro = (new User())->setEmail('outro@test.com');
        $this->repository->expects($this->never())->method('remover');
        $this->expectException(NotaTecnicaNaoExcluivelException::class);

        $this->useCase->executar($nota, $outro, $this->tenant);
    }

    #[TestDox('escritório diferente não exclui')]
    public function testTenantDiferenteLancaExcecao(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->never())->method('remover');
        $this->expectException(NotaTecnicaNaoExcluivelException::class);

        $this->useCase->executar($nota, $this->autor, new Tenant());
    }

    #[TestDox('podeExcluir: falso um segundo depois dos 15 min')]
    public function testPodeExcluirRetornaFalseForaDaJanela(): void
    {
        $nota     = $this->novaNota();
        $expirado = $nota->getCriadaEm()->add(new \DateInterval('PT15M1S'));

        self::assertFalse($this->useCase->podeExcluir($nota, $this->autor, $this->tenant, $expirado));
    }

    #[TestDox('podeExcluir: verdadeiro no último instante dos 15 min')]
    public function testPodeExcluirRetornaTrueDentroDaJanela(): void
    {
        $nota   = $this->novaNota();
        $dentro = $nota->getCriadaEm()->add(new \DateInterval('PT15M'));

        self::assertTrue($this->useCase->podeExcluir($nota, $this->autor, $this->tenant, $dentro));
    }
}
