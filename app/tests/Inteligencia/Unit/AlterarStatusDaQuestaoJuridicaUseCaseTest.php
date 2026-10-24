<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\EstadoDaQuestaoJuridica;
use App\Inteligencia\Entity\MudancaDeStatusDaQuestaoJuridica;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use App\Inteligencia\Exception\QuestaoJuridicaNaoEncontradaException;
use App\Inteligencia\Repository\EstadoDaQuestaoJuridicaRepository;
use App\Inteligencia\UseCase\AlterarStatusDaQuestaoJuridicaUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Inteligencia\Support\CatalogoJuridicoDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(AlterarStatusDaQuestaoJuridicaUseCase::class)]
#[CoversClass(EstadoDaQuestaoJuridica::class)]
#[CoversClass(MudancaDeStatusDaQuestaoJuridica::class)]
final class AlterarStatusDaQuestaoJuridicaUseCaseTest extends TestCase
{
    #[TestDox('o Master muda o status: grava o estado e a linha do histórico (de, para, quem, quando) num flush, no escopo de plataforma')]
    public function testMudaEGravaHistorico(): void
    {
        $estado = new EstadoDaQuestaoJuridica('primeira', new \DateTimeImmutable('2026-10-01'));
        $escopo = new EscopoDeAuditoriaDePlataforma();
        $gravado = null;
        $repo = $this->createMock(EstadoDaQuestaoJuridicaRepository::class);
        $repo->method('obterParaAlterar')->with('primeira')->willReturn($estado);
        $repo->expects($this->once())->method('salvarMudanca')->willReturnCallback(function (EstadoDaQuestaoJuridica $e, MudancaDeStatusDaQuestaoJuridica $m) use ($estado, $escopo, &$gravado): void {
            self::assertSame($estado, $e);
            self::assertTrue($escopo->estaLigado(), 'grava dentro do escopo de plataforma');
            self::assertNull($escopo->contaAlvo(), 'sem escritório');
            $gravado = $m;
        });
        $master = (new User())->setFullName('Dra. Master')->setRoles(['ROLE_SUPER_ADMIN']);

        (new AlterarStatusDaQuestaoJuridicaUseCase(CatalogoJuridicoDeTeste::criar(), $repo, new MockClock('2026-10-24 09:30:00'), $escopo))
            ->executar('primeira', StatusDaQuestaoJuridica::Resolvida, $master);

        self::assertSame(StatusDaQuestaoJuridica::Resolvida, $estado->getStatus());
        self::assertSame($master, $estado->getAlteradaPor());
        self::assertSame('2026-10-24 09:30', $estado->getAlteradaEm()->format('Y-m-d H:i'));
        self::assertInstanceOf(MudancaDeStatusDaQuestaoJuridica::class, $gravado);
        self::assertSame(['primeira', StatusDaQuestaoJuridica::Pendente, StatusDaQuestaoJuridica::Resolvida, 'Dra. Master', '2026-10-24 09:30'], [$gravado->getChave(), $gravado->getDe(), $gravado->getPara(), $gravado->getMudadaPorNome(), $gravado->getMudadaEm()->format('Y-m-d H:i')]);
    }

    #[TestDox('o mesmo status não grava nada')]
    public function testMesmoStatusNaoGrava(): void
    {
        $repo = $this->createMock(EstadoDaQuestaoJuridicaRepository::class);
        $repo->method('obterParaAlterar')->willReturn(new EstadoDaQuestaoJuridica('primeira', new \DateTimeImmutable()));
        $repo->expects($this->never())->method('salvarMudanca');

        $this->sut($repo)->executar('primeira', StatusDaQuestaoJuridica::Pendente, self::master());
    }

    #[TestDox('questão fora do catálogo: QuestaoJuridicaNaoEncontrada e nada é gravado')]
    public function testQuestaoInexistente(): void
    {
        $repo = $this->createMock(EstadoDaQuestaoJuridicaRepository::class);
        $repo->expects($this->never())->method('obterParaAlterar');
        $repo->expects($this->never())->method('salvarMudanca');

        $this->expectException(QuestaoJuridicaNaoEncontradaException::class);
        $this->sut($repo)->executar('nao_existe', StatusDaQuestaoJuridica::Resolvida, self::master());
    }

    #[TestDox('quem não é Master: AccessDenied e nada é gravado')]
    public function testNaoMaster(): void
    {
        $repo = $this->createMock(EstadoDaQuestaoJuridicaRepository::class);
        $repo->expects($this->never())->method('salvarMudanca');

        $this->expectException(AccessDeniedException::class);
        $this->sut($repo)->executar('primeira', StatusDaQuestaoJuridica::Resolvida, (new User())->setRoles(['ROLE_ADMIN']));
    }

    #[TestDox('o nome gravado no histórico cai para o e-mail quando a pessoa não tem nome')]
    public function testNomeCaiParaOEmail(): void
    {
        $mudanca = new MudancaDeStatusDaQuestaoJuridica('a', StatusDaQuestaoJuridica::Pendente, StatusDaQuestaoJuridica::EmAnalise, (new User())->setEmail('m@bluejus.com.br'), new \DateTimeImmutable());

        self::assertSame('m@bluejus.com.br', $mudanca->getMudadaPorNome());
    }

    private function sut(EstadoDaQuestaoJuridicaRepository $repo): AlterarStatusDaQuestaoJuridicaUseCase
    {
        return new AlterarStatusDaQuestaoJuridicaUseCase(CatalogoJuridicoDeTeste::criar(), $repo, new MockClock(), new EscopoDeAuditoriaDePlataforma());
    }

    private static function master(): User
    {
        return (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }
}
