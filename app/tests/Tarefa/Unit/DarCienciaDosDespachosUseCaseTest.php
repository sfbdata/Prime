<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
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
        $this->meta->setPasta(new Pasta());
        $this->meta->setCriadoPor($this->criador);
        $this->meta->addResponsavel($this->responsavel);
        $this->mensagens = $this->createMock(TarefaMensagemRepository::class);
        $this->agora = new \DateTimeImmutable('2026-10-22 10:00');
        // O repositório grava por UPDATE condicional (`ciente_em IS NULL`); aqui, a primeira ciência vence.
        $this->mensagens->method('darCienciaSeAinda')->willReturnCallback(static function (TarefaMensagem $d, User $quem, \DateTimeImmutable $em): bool {
            if ($d->temCiencia()) {
                return false;
            }
            $d->darCiencia($quem, $em);

            return true;
        });
    }

    #[TestDox('Responsável abre: marca os despachos do criador; os dele mesmo ficam sem ciência')]
    public function testResponsavelDaCiencia(): void
    {
        $doCriador = $this->despachoDe($this->criador);
        $dele = $this->despachoDe($this->responsavel);

        self::assertSame(1, $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio, $this->agora));
        self::assertSame($this->responsavel, $doCriador->getCientePor());
        self::assertSame($this->agora, $doCriador->getCienteEm());
        self::assertFalse($dele->temCiencia());
    }

    #[TestDox('Quem só olha (nem responsável nem criador) não dá ciência')]
    public function testQuemSoOlhaNaoDaCiencia(): void
    {
        $despacho = $this->despachoDe($this->criador);
        $this->mensagens->expects(self::never())->method('darCienciaSeAinda');

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

    #[TestDox('Mensagem antiga (sem código do registro) não recebe ciência')]
    public function testMensagemAntigaSemCiencia(): void
    {
        $antiga = $this->despachoDe($this->criador, null);
        $this->mensagens->expects(self::never())->method('darCienciaSeAinda');

        self::assertSame(0, $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio, $this->agora));
        self::assertFalse($antiga->temCiencia());
    }

    #[TestDox('Pasta excluída: abrir a meta não dá ciência')]
    public function testPastaExcluidaSemCiencia(): void
    {
        $despacho = $this->despachoDe($this->criador);
        $this->meta->getPasta()->marcarExcluida($this->criador, $this->agora);
        $this->mensagens->expects(self::never())->method('darCienciaSeAinda');

        self::assertSame(0, $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio, $this->agora));
        self::assertFalse($despacho->temCiencia());
    }

    #[TestDox('Outra pessoa deu ciência no meio do caminho (UPDATE não pegou linha): não conta')]
    public function testCienciaConcorrenteNaoConta(): void
    {
        $this->despachoDe($this->criador);
        $mensagens = $this->createStub(TarefaMensagemRepository::class);
        $mensagens->method('darCienciaSeAinda')->willReturn(false);

        self::assertSame(0, (new DarCienciaDosDespachosUseCase($mensagens, new QuemCuidaDaMeta()))->executar($this->meta, $this->responsavel, $this->escritorio, $this->agora));
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

    private function despachoDe(User $autor, ?string $codigo = '1234.5678'): TarefaMensagem
    {
        $despacho = (new TarefaMensagem())->setUsuario($autor)->setMensagem('Texto');
        if ($codigo !== null) {
            $despacho->registrar($codigo, (string) $autor->getFullName(), null);
        }
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
