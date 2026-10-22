<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Service\SorteioDeCodigoDoDespacho;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\UseCase\DespacharNaMetaUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DespacharNaMetaUseCase::class)]
#[Group('tarefa')]
final class DespacharNaMetaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private Tenant $escritorio;
    private User $autor;
    private Tarefa $meta;
    private TarefaMensagemRepository&MockObject $mensagens;
    private NotificacaoService&MockObject $notificacoes;
    private SorteioDeCodigoDoDespacho&MockObject $sorteio;

    protected function setUp(): void
    {
        $this->escritorio = self::comId(new Tenant(), 7);
        $this->autor = self::comId((new User())->setFullName('Ana Souza'), 1);
        $this->meta = new Tarefa();
        $this->meta->setTitulo('Contestação');
        $this->meta->setDescricao('...');
        $this->meta->setTenant($this->escritorio);
        $this->meta->setStatus(Tarefa::STATUS_PENDENTE);
        $this->mensagens = $this->createMock(TarefaMensagemRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->sorteio = $this->createMock(SorteioDeCodigoDoDespacho::class);
    }

    #[TestDox('Despachar grava texto em HTML, natureza, prazo, o código sorteado e quem assinou, sem mudar o status')]
    public function testDespacharGravaODespacho(): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->mensagens->method('codigoJaUsado')->willReturn(false);
        $this->mensagens->expects(self::once())->method('salvar');
        $this->mensagens->expects(self::once())->method('confirmar');
        $this->notificacoes->expects(self::never())->method(self::anything());

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input("Cumpra-se:\njuntar", '', 'cumpra_se', '2026-10-29'));

        $despacho = $this->unicoDespacho();
        self::assertSame('1234.5678', $saida->codigo);
        self::assertSame('1234.5678', $despacho->getCodigo());
        self::assertSame('<p>Cumpra-se:<br />juntar</p>', $despacho->getMensagem());
        self::assertSame(NaturezaDoDespacho::CumpraSe, $despacho->getNatureza());
        self::assertSame('2026-10-29', $despacho->getPrazoCumprimento()?->format('Y-m-d'));
        self::assertSame($this->escritorio, $despacho->getTenant());
        self::assertSame('Ana Souza', $despacho->getRegistradoPorNome());
        self::assertSame('Advogada', $despacho->getRegistradoPorCargo());
        self::assertFalse($despacho->podeSerEditado());
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->meta->getStatus());
        self::assertFalse($saida->enviada);
    }

    #[TestDox('Código já usado no escritório: sorteia de novo até achar um livre')]
    public function testColisaoSorteiaDeNovo(): void
    {
        $this->sorteio->expects(self::exactly(3))->method('sortear')->willReturnOnConsecutiveCalls('1111.1111', '2222.2222', '3333.3333');
        $this->mensagens->method('codigoJaUsado')->willReturnCallback(fn (Tenant $t, string $c): bool => $c !== '3333.3333');

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Ok.'));

        self::assertSame('3333.3333', $saida->codigo);
    }

    #[TestDox('Sem código livre depois das tentativas: recusa e não grava')]
    public function testSemCodigoLivre(): void
    {
        $this->sorteio->method('sortear')->willReturn('1111.1111');
        $this->mensagens->method('codigoJaUsado')->willReturn(true);
        $this->mensagens->expects(self::never())->method('confirmar');

        $this->expectExceptionObject(DespachoRecusadoException::semCodigoLivre());

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Ok.'));
    }

    #[TestDox('Despachar sem texto e sem anexo: recusa, nada gravado')]
    public function testSemConteudo(): void
    {
        $this->mensagens->expects(self::never())->method('salvar');
        $this->mensagens->expects(self::never())->method('confirmar');

        $this->expectExceptionObject(DespachoRecusadoException::semConteudo());

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input(''));
    }

    #[TestDox('Só anexo: grava "[Arquivo anexado]" com o caminho, como antes')]
    public function testSoAnexo(): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('')->comAnexo('/uploads/tarefas/chat/a.pdf'));

        self::assertSame('[Arquivo anexado]', $this->unicoDespacho()->getMensagem());
        self::assertSame('/uploads/tarefas/chat/a.pdf', $this->unicoDespacho()->getArquivoAnexo());
    }

    #[TestDox('Enviar para revisão: muda o status, avisa e não leva natureza nem prazo (desenho L146)')]
    public function testEnviarParaRevisao(): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->notificacoes->expects(self::once())->method('notificarTarefaEmRevisao')->with($this->meta);

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Pronto.', 'enviar', 'ciencia', '2026-10-29'));

        self::assertTrue($saida->enviada);
        self::assertSame(Tarefa::STATUS_EM_REVISAO, $this->meta->getStatus());
        self::assertNull($this->unicoDespacho()->getNatureza());
        self::assertNull($this->unicoDespacho()->getPrazoCumprimento());
    }

    #[TestDox('Enviar para revisão sem texto: só muda o status, sem despacho (como antes)')]
    public function testEnviarSemTexto(): void
    {
        $this->mensagens->expects(self::never())->method('salvar');
        $this->mensagens->expects(self::once())->method('confirmar');
        $this->sorteio->expects(self::never())->method('sortear');

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('', 'enviar'));

        self::assertNull($saida->codigo);
        self::assertCount(0, $this->meta->getMensagens());
        self::assertSame(Tarefa::STATUS_EM_REVISAO, $this->meta->getStatus());
    }

    #[TestDox('Devolver: em revisão volta a pendente e avisa os responsáveis')]
    public function testDevolver(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_EM_REVISAO);
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->notificacoes->expects(self::once())->method('notificarTarefaPendente')->with($this->meta);

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Ajustar.', 'devolver', 'providencia'));

        self::assertTrue($saida->devolvida);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->meta->getStatus());
        self::assertSame(NaturezaDoDespacho::Providencia, $this->unicoDespacho()->getNatureza());
    }

    #[TestDox('Meta de outro escritório: recusa, nada gravado')]
    public function testOutroEscritorio(): void
    {
        $this->mensagens->expects(self::never())->method('confirmar');

        $this->expectExceptionObject(DespachoRecusadoException::foraDoEscritorio());

        $this->useCase()->executar($this->meta, $this->autor, self::comId(new Tenant(), 8), $this->input('Ok.'));
    }

    private function useCase(): DespacharNaMetaUseCase
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findPorUserETenant')->willReturn((new UserTenant($this->autor, $this->escritorio))->setCargo((new Cargo())->setNome('Advogada')));

        return new DespacharNaMetaUseCase(
            $this->mensagens,
            new TextoDoDespacho($this->criarSanitizadorTextoRico()),
            $this->sorteio,
            $this->notificacoes,
            $vinculos,
        );
    }

    private function input(string $texto, string $acao = '', ?string $natureza = null, ?string $prazo = null): DespacharNaMetaInput
    {
        return new DespacharNaMetaInput($texto, $acao, $natureza, $prazo, new \DateTimeImmutable('2026-10-22'));
    }

    private function unicoDespacho(): TarefaMensagem
    {
        self::assertCount(1, $this->meta->getMensagens());

        return $this->meta->getMensagens()->first();
    }

    /**
     * @template T of object
     * @param T $entidade
     * @return T
     */
    private static function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade::class, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
