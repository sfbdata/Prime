<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Auth\User;
use App\Voto\DTO\EscritorioNaLiberacaoDoVotoOutput;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\UseCase\ObterLiberacoesDoVotoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** A grade "Liberação por escritório" da aba "Voto" do Master (docs/specs/bluejus-voto.md, fase A1b). */
#[CoversClass(ObterLiberacoesDoVotoUseCase::class)]
#[CoversClass(EscritorioNaLiberacaoDoVotoOutput::class)]
final class ObterLiberacoesDoVotoUseCaseTest extends TestCase
{
    #[TestDox('uma linha por escritório ativo, na ordem do repositório, com o id, o nome e se está liberado')]
    public function testMontaAGrade(): void
    {
        $liberacoes = $this->createStub(LiberacaoDoVotoRepository::class);
        $liberacoes->method('listarEscritoriosParaOMaster')->willReturn([
            ['tenant_id' => 3, 'nome' => 'Alfa Advogados', 'liberado' => true],
            ['tenant_id' => 9, 'nome' => 'Beta Advocacia', 'liberado' => false],
        ]);

        $grade = (new ObterLiberacoesDoVotoUseCase($liberacoes))->executar((new User())->setRoles(['ROLE_SUPER_ADMIN']));

        self::assertCount(2, $grade);
        self::assertContainsOnlyInstancesOf(EscritorioNaLiberacaoDoVotoOutput::class, $grade);
        self::assertSame([3, 'Alfa Advogados', true], [$grade[0]->tenantId, $grade[0]->nome, $grade[0]->liberado]);
        self::assertSame([9, 'Beta Advocacia', false], [$grade[1]->tenantId, $grade[1]->nome, $grade[1]->liberado]);
    }

    #[TestDox('nenhum escritório ativo: grade vazia')]
    public function testSemEscritorios(): void
    {
        $liberacoes = $this->createStub(LiberacaoDoVotoRepository::class);
        $liberacoes->method('listarEscritoriosParaOMaster')->willReturn([]);

        self::assertSame([], (new ObterLiberacoesDoVotoUseCase($liberacoes))->executar((new User())->setRoles(['ROLE_SUPER_ADMIN'])));
    }

    #[TestDox('quem não é Master: AccessDenied sem consultar os escritórios')]
    public function testNaoMaster(): void
    {
        $liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $liberacoes->expects($this->never())->method('listarEscritoriosParaOMaster');

        $this->expectException(AccessDeniedException::class);
        (new ObterLiberacoesDoVotoUseCase($liberacoes))->executar((new User())->setRoles(['ROLE_USER']));
    }
}
