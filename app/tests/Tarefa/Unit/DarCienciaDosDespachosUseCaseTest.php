<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\UseCase\DarCienciaDosDespachosUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** C14: abrir a meta dá ciência nos despachos de outra pessoa, só para responsável ou criador. */
#[CoversClass(DarCienciaDosDespachosUseCase::class)]
#[CoversClass(QuemCuidaDaMeta::class)]
#[Group('tarefa')]
final class DarCienciaDosDespachosUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private User $outro;
    private Tarefa $meta;
    private TarefaMensagemRepository&MockObject $mensagens;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->escritorio, 3);
        $this->criador = self::pessoa(1, 'Carla Dias');
        $this->responsavel = self::pessoa(2, 'Rui Prado');
        $this->outro = self::pessoa(3, 'Olga Lima');
        $this->meta = new Tarefa();
        $this->meta->setTenant($this->escritorio);
        $this->meta->setCriadoPor($this->criador);
        $this->meta->addResponsavel($this->responsavel);
        $this->mensagens = $this->createMock(TarefaMensagemRepository::class);
        $this->agora = new \DateTimeImmutable('2026-10-22 10:00');
    }

    #[TestDox('Responsável abre: marca os despachos do criador; os dele mesmo ficam sem ciência')]
    public function testResponsavelDaCiencia(): void
    {
        $doCriador = $this->despachoDe($this->criador);
        $dele = $this->despachoDe($this->responsavel);
        $this->mensagens->expects(self::once())->method('confirmar');

        self::assertSame(1, $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio, $this->agora));
        self::assertSame($this->responsavel, $doCriador->getCientePor());
        self::assertSame($this->agora, $doCriador->getCienteEm());
        self::assertFalse($dele->temCiencia());
    }

    #[TestDox('Quem só olha (nem responsável nem criador) não dá ciência')]
    public function testQuemSoOlhaNaoDaCiencia(): void
    {
        $despacho = $this->despachoDe($this->criador);
        $this->mensagens->expects(self::never())->method('confirmar');

        self::assertSame(0, $this->useCase()->executar($this->meta, $this->outro, $this->escritorio, $this->agora));
        self::assertFalse($despacho->temCiencia());
    }

    #[TestDox('Vale a primeira ciência: abrir de novo, ou outro cuidador, não muda nada')]
    public function testPrimeiraCienciaVale(): void
    {
        $despacho = $this->despachoDe($this->outro);
        $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio, $this->agora);

        self::assertSame(0, $this->useCase()->executar($this->meta, $this->criador, $this->escritorio, $this->agora->modify('+1 hour')));
        self::assertSame($this->responsavel, $despacho->getCientePor());
    }

    #[TestDox('Meta de outro escritório: nada marcado')]
    public function testOutroEscritorio(): void
    {
        $this->despachoDe($this->criador);
        $outro = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 99);

        self::assertSame(0, $this->useCase()->executar($this->meta, $this->responsavel, $outro, $this->agora));
    }

    private function useCase(): DarCienciaDosDespachosUseCase
    {
        return new DarCienciaDosDespachosUseCase($this->mensagens, new QuemCuidaDaMeta());
    }

    private function despachoDe(User $autor): TarefaMensagem
    {
        $despacho = (new TarefaMensagem())->setUsuario($autor)->setMensagem('Texto');
        $this->meta->addMensagem($despacho);

        return $despacho;
    }

    private static function pessoa(int $id, string $nome): User
    {
        $pessoa = (new User())->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, $id);

        return $pessoa;
    }
}
