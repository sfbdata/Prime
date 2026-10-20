<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\LinkExterno;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\LinkExternoDoPushException;
use App\Pasta\Repository\AcessoAoLinkExternoDoPushRepository;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\ConteudoDoPushCompartilhado;
use App\Pasta\Service\GeradorDeEnderecoDoLinkExterno;
use App\Pasta\Service\MontadorDeLinksExternos;
use App\Pasta\UseCase\GerarLinkExternoDoPushUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(GerarLinkExternoDoPushUseCase::class)]
#[CoversClass(ConteudoDoPushCompartilhado::class)]
final class GerarLinkExternoDoPushUseCaseTest extends TestCase
{
    use CriaLinkExternoTrait;

    private LinkExternoDoPushRepository&MockObject $links;
    private PublicacaoDjenRepository&MockObject $publicacoes;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    private ?LinkExternoDoPush $salvo = null;

    protected function setUp(): void
    {
        $this->links = $this->createMock(LinkExternoDoPushRepository::class);
        $this->links->method('enderecoEmUso')->willReturn(false);
        $this->links->method('salvar')->willReturnCallback(function (LinkExternoDoPush $l): void {
            $this->salvo = $l;
        });
        $this->publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
    }

    private function useCase(): GerarLinkExternoDoPushUseCase
    {
        $acessos = $this->createMock(AcessoAoLinkExternoDoPushRepository::class);
        $acessos->method('estatisticasDosLinks')->willReturn([]);

        return new GerarLinkExternoDoPushUseCase(
            $this->links,
            new ConteudoDoPushCompartilhado($this->publicacoes, $this->analises, $this->cadeados()),
            new GeradorDeEnderecoDoLinkExterno($this->links),
            new MontadorDeLinksExternos($acessos, $this->urlGenerator(), new ConteudoDoPushCompartilhado($this->createMock(PublicacaoDjenRepository::class), $this->createMock(AnaliseDeInteligenciaRepository::class), $this->cadeados())),
            new MockClock($this->agora()),
        );
    }

    /** @return iterable<string, array{callable(self): Pasta, string}> */
    public static function bloqueios(): iterable
    {
        yield 'sem processo' => [static function (self $t): Pasta {
            $p = new Pasta();
            $p->setTenant($t->tenant());

            return $p;
        }, 'A pasta não tem processo vinculado.'];
        yield 'cancelada' => [static fn (self $t): Pasta => $t->pastaComProcesso($t->tenant())->setSituacao(Pasta::SITUACAO_CANCELADA), 'A pasta está cancelada: o link externo fica indisponível.'];
        yield 'excluída (lápide)' => [static fn (self $t): Pasta => $t->pastaComProcesso($t->tenant())->marcarExcluida($t->usuario(), $t->agora()), 'A pasta está cancelada: o link externo fica indisponível.'];
        yield 'arquivada' => [static fn (self $t): Pasta => $t->pastaComProcesso($t->tenant())->setSituacao(Pasta::SITUACAO_ARQUIVADA), 'A pasta está arquivada: reative para compartilhar o Push.'];
        yield 'trancada' => [static fn (self $t): Pasta => $t->comCadeado($t->pastaComProcesso($t->tenant())), 'A pasta está trancada: o link externo fica indisponível.'];
        yield 'processo em segredo de justiça' => [static function (self $t): Pasta {
            $processo = $t->processo($t->tenant());
            $processo->setNivelSigilo(1);

            return $t->pastaComProcesso($t->tenant(), $processo);
        }, 'O processo está em segredo de justiça: o link externo fica indisponível.'];
    }

    /** @param callable(self): Pasta $pasta */
    #[DataProvider('bloqueios')]
    #[TestDox('Bloqueia com o texto exato do desenho')]
    public function testBloqueios(callable $pasta, string $mensagem): void
    {
        $this->publicacoes->method('existePorNumerosDoTenant')->willReturn(true);
        $this->links->expects(self::never())->method('salvar');

        $this->expectException(LinkExternoDoPushException::class);
        $this->expectExceptionMessage($mensagem);
        $this->useCase()->executar($pasta($this), $this->usuario(), true, true, false);
    }

    #[TestDox('Conta com o e-mail a confirmar não gera link: recusa com o pedido de confirmação e nada é salvo')]
    public function testEmailAConfirmarNaoGeraLink(): void
    {
        $this->publicacoes->method('existePorNumerosDoTenant')->willReturn(true);
        $this->links->expects(self::never())->method('salvar');
        $usuario = $this->usuario();
        $usuario->marcarEmailAConfirmar($this->agora());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Confirme o seu e-mail para compartilhar o processo por link.');
        $this->useCase()->executar($this->pastaComProcesso($this->tenant()), $usuario, false, true, false);
    }

    #[TestDox('Sem movimentações: "Ainda não há movimentações para compartilhar."')]
    public function testSemMovimentacoes(): void
    {
        $this->publicacoes->method('existePorNumerosDoTenant')->willReturn(false);
        $this->links->expects(self::never())->method('salvar');

        $this->expectExceptionObject(LinkExternoDoPushException::bloqueio('Ainda não há movimentações para compartilhar.'));
        $this->useCase()->executar($this->pastaComProcesso($this->tenant()), $this->usuario(), false, true, false);
    }

    #[TestDox('Gera com código de 6 dígitos, 30 dias de validade e o nome do cliente no endereço')]
    public function testGeraComCodigo(): void
    {
        $tenant = $this->tenant();
        $pasta = $this->pastaComProcesso($tenant);
        $pasta->setNomeCliente('Maria Silva');
        $this->publicacoes->method('existePorNumerosDoTenant')->willReturn(true);
        $this->analises->method('listarPorAlvo')->willReturn([]);

        $saida = $this->useCase()->executar($pasta, $this->usuario(), true, true, false);

        self::assertNotNull($this->salvo);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $saida->codigo);
        self::assertStringStartsWith('bluejus-maria-silva-', $saida->endereco);
        self::assertSame('https://jusprime.test/p/' . $saida->endereco, $saida->url);
        self::assertSame('ativo', $saida->estado);
        self::assertSame('18/11/2026 10:00', $saida->expiraEm);
        self::assertSame($pasta->getProcessoPrincipal(), $this->salvo->getProcesso());
        self::assertSame($saida->url . "\nCódigo de acesso: " . $saida->codigo, $saida->textoParaCopiar());
    }

    #[TestDox('Sem "mostrar cliente", o nome do cliente não entra no endereço; sem código, codigo nulo')]
    public function testSemClienteNoEndereco(): void
    {
        $pasta = $this->pastaComProcesso($this->tenant());
        $pasta->setNomeCliente('Maria Silva');
        $this->publicacoes->method('existePorNumerosDoTenant')->willReturn(true);
        $this->analises->method('listarPorAlvo')->willReturn([]);

        $saida = $this->useCase()->executar($pasta, $this->usuario(), false, false, false);

        self::assertStringStartsWith('bluejus-processo-0701134-', $saida->endereco);
        self::assertNull($saida->codigo);
        self::assertSame($saida->url, $saida->textoParaCopiar());
    }

    #[TestDox('Incluir análises sem nenhuma análise compartilhável vira false; com uma, fica true')]
    public function testIncluirAnalises(): void
    {
        $tenant = $this->tenant();
        $this->publicacoes->method('existePorNumerosDoTenant')->willReturn(true);
        $this->analises->method('listarPorAlvo')->willReturnOnConsecutiveCalls(
            [$this->analise($tenant, interna: true), $this->analise($tenant, interna: false, concluida: false)],
            [$this->analise($tenant, interna: false)],
        );

        $semCompartilhavel = $this->useCase()->executar($this->pastaComProcesso($tenant), $this->usuario(), false, true, true);
        $comCompartilhavel = $this->useCase()->executar($this->pastaComProcesso($tenant), $this->usuario(), false, true, true);

        self::assertFalse($semCompartilhavel->incluirAnalises);
        self::assertTrue($comCompartilhavel->incluirAnalises);
    }
}
