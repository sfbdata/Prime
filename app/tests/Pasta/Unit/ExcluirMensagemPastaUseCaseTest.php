<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\MensagemPastaNaoExcluivelException;
use App\Pasta\UseCase\ExcluirMensagemPastaUseCase;
use App\Repository\NotificacaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Pasta\Support\CriaPrazoDoComentario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirMensagemPastaUseCase::class)]
final class ExcluirMensagemPastaUseCaseTest extends TestCase
{
    use CriaPrazoDoComentario;

    private EntityManagerInterface&MockObject $em;
    private NotificacaoRepository&MockObject $notificacoes;
    private ExcluirMensagemPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->notificacoes = $this->createMock(NotificacaoRepository::class);
        // A transação roda o bloco de verdade: o que se confere é o que acontece DENTRO dela.
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $bloco): mixed => $bloco($this->em));
        $this->useCase = new ExcluirMensagemPastaUseCase($this->em, $this->prazoDoComentario(), $this->notificacoes);
        $this->tenant  = new Tenant();
        $this->autor   = (new User())->setEmail('autor@test.com');
    }

    #[TestDox('NOT-01: apaga, dentro da transação, os avisos de menção/resposta da âncora exata desta mensagem, neste escritório')]
    public function testApagaOsAvisosDaMensagem(): void
    {
        $mensagem = $this->novaMensagem();
        (new \ReflectionProperty(PastaMensagem::class, 'id'))->setValue($mensagem, 12);
        $ordem = [];
        $this->em->expects($this->once())->method('wrapInTransaction');
        $this->em->expects($this->once())->method('remove')->willReturnCallback(static function () use (&$ordem): void { $ordem[] = 'remove'; });
        $this->notificacoes->expects($this->once())->method('excluirPelaAncoraDoLink')
            ->with(
                $this->identicalTo($this->tenant),
                [Notificacao::TIPO_PASTA_MENCAO_REGISTRO, Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO],
                '#pasta-msg-12',
            )
            ->willReturnCallback(static function () use (&$ordem): int { $ordem[] = 'avisos'; return 2; });

        $this->useCase->executar($mensagem, $this->autor, $this->tenant);

        self::assertSame(['remove', 'avisos'], $ordem);
    }

    #[TestDox('NOT-01: recusada a exclusão, nenhum aviso é apagado')]
    public function testRecusadaNaoApagaAvisos(): void
    {
        $mensagem = $this->novaMensagem();
        (new \ReflectionProperty(PastaMensagem::class, 'id'))->setValue($mensagem, 12);
        $this->notificacoes->expects($this->never())->method('excluirPelaAncoraDoLink');

        $this->expectException(MensagemPastaNaoExcluivelException::class);

        $this->useCase->executar($mensagem, (new User())->setEmail('outro@test.com'), $this->tenant);
    }

    private function novaMensagem(): PastaMensagem
    {
        return (new PastaMensagem())
            ->setPasta(new Pasta())
            ->setAutor($this->autor)
            ->setTenant($this->tenant)
            ->setConteudo('Original');
    }

    public function testAutorDentroDaJanelaExclui(): void
    {
        $mensagem = $this->novaMensagem();
        $this->em->expects($this->once())->method('remove')->with($mensagem);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($mensagem, $this->autor, $this->tenant);
    }

    public function testNaoAutorLancaExcecao(): void
    {
        $mensagem = $this->novaMensagem();
        $outro    = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(MensagemPastaNaoExcluivelException::class);

        $this->useCase->executar($mensagem, $outro, $this->tenant);
    }

    public function testTenantDiferenteLancaExcecao(): void
    {
        $mensagem = $this->novaMensagem();

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(MensagemPastaNaoExcluivelException::class);

        $this->useCase->executar($mensagem, $this->autor, new Tenant());
    }

    public function testPodeExcluirRetornaFalseForaDaJanela(): void
    {
        $mensagem = $this->novaMensagem();
        $expirado = $mensagem->getCriadaEm()->add(new \DateInterval('PT15M1S'));

        self::assertFalse($this->useCase->podeExcluir($mensagem, $this->autor, $this->tenant, $expirado));
    }

    public function testPodeExcluirRetornaTrueDentroDaJanela(): void
    {
        $mensagem = $this->novaMensagem();
        $dentro   = $mensagem->getCriadaEm()->add(new \DateInterval('PT15M'));

        self::assertTrue($this->useCase->podeExcluir($mensagem, $this->autor, $this->tenant, $dentro));
    }
}
