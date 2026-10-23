<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaNaoExcluivelException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\UseCase\ExcluirNotaTecnicaUseCase;
use App\Repository\NotificacaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Pasta\Support\CriaPrazoDoComentario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirNotaTecnicaUseCase::class)]
final class ExcluirNotaTecnicaUseCaseTest extends TestCase
{
    use CriaPrazoDoComentario;

    private NotaTecnicaRepository&MockObject $repository;
    private NotificacaoRepository&MockObject $notificacoes;
    private EntityManagerInterface&MockObject $em;
    private ExcluirNotaTecnicaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->repository   = $this->createMock(NotaTecnicaRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoRepository::class);
        $this->em           = $this->createMock(EntityManagerInterface::class);
        // A transação roda o bloco de verdade: o que se confere é o que acontece DENTRO dela.
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $bloco): mixed => $bloco($this->em));
        $this->useCase    = new ExcluirNotaTecnicaUseCase($this->repository, $this->prazoDoComentario(), $this->notificacoes, $this->em);
        $this->tenant     = new Tenant();
        $this->autor      = (new User())->setEmail('autor@test.com');
    }

    #[TestDox('NOT-01: apaga, dentro da transação e depois da nota, os avisos de menção da âncora exata desta nota, neste escritório')]
    public function testApagaOsAvisosDaNota(): void
    {
        $nota = $this->novaNota();
        (new \ReflectionProperty(NotaTecnica::class, 'id'))->setValue($nota, 7);
        $ordem = [];
        $this->em->expects($this->once())->method('wrapInTransaction');
        $this->repository->expects($this->once())->method('remover')->with($nota, true)
            ->willReturnCallback(static function () use (&$ordem): void { $ordem[] = 'remover'; });
        $this->notificacoes->expects($this->once())->method('excluirPelaAncoraDoLink')
            ->with($this->identicalTo($this->tenant), [Notificacao::TIPO_NOTA_TECNICA_MENCAO], '#nota-tecnica-7')
            ->willReturnCallback(static function () use (&$ordem): int { $ordem[] = 'avisos'; return 1; });

        $this->useCase->executar($nota, $this->autor, $this->tenant);

        self::assertSame(['remover', 'avisos'], $ordem);
    }

    #[TestDox('NOT-01: recusada a exclusão, nenhum aviso é apagado')]
    public function testRecusadaNaoApagaAvisos(): void
    {
        $nota = $this->novaNota();
        (new \ReflectionProperty(NotaTecnica::class, 'id'))->setValue($nota, 7);
        $this->notificacoes->expects($this->never())->method('excluirPelaAncoraDoLink');
        $this->expectException(NotaTecnicaNaoExcluivelException::class);

        $this->useCase->executar($nota, (new User())->setEmail('outro@test.com'), $this->tenant);
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
