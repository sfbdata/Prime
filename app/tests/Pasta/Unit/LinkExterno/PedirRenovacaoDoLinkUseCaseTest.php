<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\LinkExterno;

use App\Entity\Notificacao;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\UseCase\PedirRenovacaoDoLinkUseCase;
use App\Service\NotificacaoService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PedirRenovacaoDoLinkUseCase::class)]
final class PedirRenovacaoDoLinkUseCaseTest extends TestCase
{
    use CriaLinkExternoTrait;

    private LinkExternoDoPushRepository&MockObject $links;
    private NotificacaoService&MockObject $notificacoes;

    protected function setUp(): void
    {
        $this->links = $this->createMock(LinkExternoDoPushRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoService::class);
    }

    private function pedir(?LinkExternoDoPush $link, ?string $nome, ?string $mensagem = null): bool
    {
        $this->links->method('findOnePeloEndereco')->willReturn($link);

        return (new PedirRenovacaoDoLinkUseCase($this->links, $this->notificacoes, $this->urlGenerator(), new MockClock($this->agora())))
            ->executar('bluejus-maria-silva-abcdefgh', $nome, $mensagem);
    }

    #[TestDox('Expirado: grava o pedido e avisa quem criou, com o nome e o link para a aba Push')]
    public function testAvisaQuemCriou(): void
    {
        $link = $this->linkExpirado();
        $this->notificacoes->expects(self::once())->method('criarNotificacao')->with(
            $link->getCriadoPor(),
            $link->getTenant(),
            Notificacao::TIPO_PUSH_LINK_RENOVACAO,
            'Carlos Terceiro pediu a renovação do link externo do Push da Pasta 2026-001.',
            '/pasta/42#push',
        );

        self::assertTrue($this->pedir($link, ' Carlos Terceiro ', 'Preciso acompanhar'));
        self::assertTrue($link->temPedidoPendente());
        self::assertSame('Preciso acompanhar', $link->getRenovacaoMensagem());
    }

    #[TestDox('Sem nome, o aviso diz "Quem recebeu o link"')]
    public function testSemNome(): void
    {
        $this->notificacoes->expects(self::once())->method('criarNotificacao')->with(
            self::anything(),
            self::anything(),
            Notificacao::TIPO_PUSH_LINK_RENOVACAO,
            'Quem recebeu o link pediu a renovação do link externo do Push da Pasta 2026-001.',
            self::anything(),
        );

        self::assertTrue($this->pedir($this->linkExpirado(), ''));
    }

    #[TestDox('Ativo, revogado, inexistente, já pedido ou recusado: não grava nem avisa')]
    public function testRecusas(): void
    {
        $this->notificacoes->expects(self::never())->method('criarNotificacao');
        $this->links->expects(self::never())->method('salvar');

        $revogado = $this->linkExpirado();
        $revogado->revogar($this->usuario(), $this->agora());
        $pendente = $this->linkExpirado();
        $pendente->pedirRenovacao('X', null, $this->agora());
        $recusado = $this->linkExpirado();
        $recusado->pedirRenovacao('X', null, $this->agora());
        $recusado->recusarRenovacao();

        foreach ([$this->link(), $revogado, null, $pendente, $recusado] as $link) {
            $this->links = $this->createMock(LinkExternoDoPushRepository::class);
            $this->links->expects(self::never())->method('salvar');
            self::assertFalse($this->pedir($link, 'Carlos'));
        }
    }
}
