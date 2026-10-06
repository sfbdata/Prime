<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaNaoEditavelException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\UseCase\EditarNotaTecnicaUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarNotaTecnicaUseCase::class)]
final class EditarNotaTecnicaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private NotaTecnicaRepository&MockObject $repository;
    private EditarNotaTecnicaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(NotaTecnicaRepository::class);
        $this->useCase    = new EditarNotaTecnicaUseCase($this->repository, $this->criarSanitizadorTextoRico(), new JanelaDeEdicaoDeComentario());
        $this->tenant     = new Tenant();
        $this->autor      = (new User())->setEmail('autor@test.com');
    }

    private function novaNota(string $conteudo = '<p>Original</p>'): NotaTecnica
    {
        $processo = (new Processo())->setTenant($this->tenant);

        return new NotaTecnica($this->tenant, $processo, $this->autor, $conteudo);
    }

    #[TestDox('o autor, dentro da janela, edita — e a nota registra editadaEm')]
    public function testAutorDentroDaJanelaEdita(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->once())->method('salvar')->with($nota, true);

        $saida = $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Corrigido</p>');

        self::assertSame('<p>Corrigido</p>', $nota->getConteudo());
        self::assertNotNull($nota->getEditadaEm());
        self::assertSame('<p>Corrigido</p>', $saida->conteudo);
        self::assertNotNull($saida->editadaEm);
    }

    #[TestDox('a edição também sanitiza — segunda porta do mesmo campo')]
    public function testEdicaoSanitiza(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->once())->method('salvar');

        $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Corrigido</p><img src=x onerror="alert(1)">');

        self::assertStringNotContainsStringIgnoringCase('onerror', $nota->getConteudo());
        self::assertStringContainsString('Corrigido', $nota->getConteudo());
    }

    #[TestDox('quem não é o autor não edita')]
    public function testNaoAutorLancaExcecao(): void
    {
        $nota  = $this->novaNota();
        $outro = (new User())->setEmail('outro@test.com');
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaNaoEditavelException::class);

        $this->useCase->executar($nota, $outro, $this->tenant, '<p>Corrigido</p>');
    }

    #[TestDox('escritório diferente não edita')]
    public function testTenantDiferenteLancaExcecao(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaNaoEditavelException::class);

        $this->useCase->executar($nota, $this->autor, new Tenant(), '<p>Corrigido</p>');
    }

    #[TestDox('conteúdo vazio é recusado e a nota não muda')]
    public function testConteudoVazioLancaInvalidArgument(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($nota, $this->autor, $this->tenant, '<p><br></p>');
        } finally {
            self::assertSame('<p>Original</p>', $nota->getConteudo());
            self::assertNull($nota->getEditadaEm());
        }
    }

    #[TestDox('acima de 5000 caracteres visíveis é recusado')]
    public function testConteudoAcimaDe5000LancaInvalidArgument(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($nota, $this->autor, $this->tenant, str_repeat('a', 5001));
    }

    #[TestDox('podeEditar: falso um segundo depois dos 15 min')]
    public function testPodeEditarRetornaFalseForaDaJanela(): void
    {
        $nota     = $this->novaNota();
        $expirado = $nota->getCriadaEm()->add(new \DateInterval('PT15M1S'));

        self::assertFalse($this->useCase->podeEditar($nota, $this->autor, $this->tenant, $expirado));
    }

    #[TestDox('podeEditar: verdadeiro no último instante dos 15 min')]
    public function testPodeEditarRetornaTrueDentroDaJanela(): void
    {
        $nota   = $this->novaNota();
        $dentro = $nota->getCriadaEm()->add(new \DateInterval('PT15M'));

        self::assertTrue($this->useCase->podeEditar($nota, $this->autor, $this->tenant, $dentro));
    }

    #[TestDox('nota sem autor (colaborador desvinculado) não é de ninguém')]
    public function testNotaSemAutorNaoEEditavel(): void
    {
        $nota = $this->novaNota();
        (new \ReflectionProperty(NotaTecnica::class, 'autor'))->setValue($nota, null);

        self::assertFalse($this->useCase->podeEditar($nota, $this->autor, $this->tenant));
    }
}
