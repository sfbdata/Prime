<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\LinkExterno;

use App\Pasta\Exception\LinkExternoDoPushException;
use App\Pasta\Repository\AcessoAoLinkExternoDoPushRepository;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\MontadorDeLinksExternos;
use App\Pasta\UseCase\RecusarRenovacaoDoLinkUseCase;
use App\Pasta\UseCase\RenovarLinkExternoDoPushUseCase;
use App\Pasta\UseCase\RevogarLinkExternoDoPushUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RevogarLinkExternoDoPushUseCase::class)]
#[CoversClass(RenovarLinkExternoDoPushUseCase::class)]
#[CoversClass(RecusarRenovacaoDoLinkUseCase::class)]
final class OperacoesDoLinkExternoUseCaseTest extends TestCase
{
    use CriaLinkExternoTrait;

    private LinkExternoDoPushRepository&MockObject $links;

    protected function setUp(): void
    {
        $this->links = $this->createMock(LinkExternoDoPushRepository::class);
    }

    private function montador(): MontadorDeLinksExternos
    {
        $acessos = $this->createMock(AcessoAoLinkExternoDoPushRepository::class);
        $acessos->method('estatisticasDosLinks')->willReturn([]);

        return new MontadorDeLinksExternos($acessos, $this->urlGenerator());
    }

    private function relogio(): MockClock
    {
        return new MockClock($this->agora());
    }

    #[TestDox('Revogar ativo grava; revogar de novo não grava (idempotente)')]
    public function testRevogar(): void
    {
        $link = $this->link();
        $this->links->expects(self::once())->method('salvar')->with($link, true);
        $useCase = new RevogarLinkExternoDoPushUseCase($this->links, $this->montador(), $this->relogio());

        $saida = $useCase->executar($link, $this->usuario());
        $denovo = $useCase->executar($link, $this->usuario());

        self::assertSame('revogado', $saida->estado);
        self::assertFalse($saida->podeRevogar);
        self::assertSame('revogado', $denovo->estado);
    }

    #[TestDox('Revogar vale para link expirado')]
    public function testRevogarExpirado(): void
    {
        $saida = (new RevogarLinkExternoDoPushUseCase($this->links, $this->montador(), $this->relogio()))
            ->executar($this->linkExpirado(), $this->usuario());

        self::assertSame('revogado', $saida->estado);
    }

    #[TestDox('Renovar expirado: ativo de novo por 30 dias')]
    public function testRenovar(): void
    {
        $link = $this->linkExpirado();
        $this->links->expects(self::once())->method('salvar')->with($link, true);

        $saida = (new RenovarLinkExternoDoPushUseCase($this->links, $this->montador(), $this->relogio()))->executar($link, $this->usuario());

        self::assertSame('ativo', $saida->estado);
        self::assertSame('18/11/2026 10:00', $saida->expiraEm);
    }

    #[TestDox('Renovar ativo é recusado com a mensagem e não grava')]
    public function testRenovarAtivo(): void
    {
        $this->links->expects(self::never())->method('salvar');

        $this->expectExceptionObject(LinkExternoDoPushException::renovarAtivo());
        (new RenovarLinkExternoDoPushUseCase($this->links, $this->montador(), $this->relogio()))->executar($this->link(), $this->usuario());
    }

    #[TestDox('Recusar com pedido pendente: resposta recusado, estado volta a expirado')]
    public function testRecusar(): void
    {
        $link = $this->linkExpirado();
        $link->pedirRenovacao('Carlos', null, $this->agora());
        $this->links->expects(self::once())->method('salvar')->with($link, true);

        $saida = (new RecusarRenovacaoDoLinkUseCase($this->links, $this->montador(), $this->relogio()))->executar($link, $this->usuario());

        self::assertSame('expirado', $saida->estado);
        self::assertSame('recusado', $saida->renovacao['resposta'] ?? null);
        self::assertFalse($saida->podeRecusar);
    }

    #[TestDox('Recusar sem pedido pendente é recusado e não grava')]
    public function testRecusarSemPedido(): void
    {
        $this->links->expects(self::never())->method('salvar');

        $this->expectExceptionObject(LinkExternoDoPushException::semPedidoPendente());
        (new RecusarRenovacaoDoLinkUseCase($this->links, $this->montador(), $this->relogio()))->executar($this->linkExpirado(), $this->usuario());
    }
}
