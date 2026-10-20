<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\LinkExterno;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\DTO\PaginaDoPushCompartilhadoOutput;
use App\Pasta\Entity\AcessoAoLinkExternoDoPush;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Repository\AcessoAoLinkExternoDoPushRepository;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\ConteudoDoPushCompartilhado;
use App\Pasta\UseCase\AbrirLinkExternoDoPushUseCase;
use App\Pasta\Entity\Pasta;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AbrirLinkExternoDoPushUseCase::class)]
#[CoversClass(PaginaDoPushCompartilhadoOutput::class)]
final class AbrirLinkExternoDoPushUseCaseTest extends TestCase
{
    use CriaLinkExternoTrait;

    private LinkExternoDoPushRepository&MockObject $links;
    private AcessoAoLinkExternoDoPushRepository&MockObject $acessos;
    private PublicacaoDjenRepository&MockObject $publicacoes;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    /** @var AcessoAoLinkExternoDoPush[] */
    private array $acessosGravados = [];

    protected function setUp(): void
    {
        $this->links = $this->createMock(LinkExternoDoPushRepository::class);
        $this->links->method('alterarComTrava')->willReturnCallback(static fn (LinkExternoDoPush $l, callable $f): mixed => $f($l));
        $this->acessos = $this->createMock(AcessoAoLinkExternoDoPushRepository::class);
        $this->acessos->method('salvar')->willReturnCallback(function (AcessoAoLinkExternoDoPush $a): void {
            $this->acessosGravados[] = $a;
        });
        $this->publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $this->publicacoes->method('listarItensPorNumerosDoTenant')->willReturn([$this->item(1), $this->item(2, 'Sentença', null)]);
        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
    }

    private function abrir(LinkExternoDoPush $link, ?string $codigo = null, int $minutosDepois = 0, bool $pedidoDeExclusao = false): PaginaDoPushCompartilhadoOutput
    {
        $this->links->method('findOnePeloEndereco')->willReturn($link);
        $pedidos = $this->createMock(PedidoDeExclusaoDeContaRepository::class);
        $pedidos->method('existePendente')->willReturn($pedidoDeExclusao);

        return (new AbrirLinkExternoDoPushUseCase(
            $this->links,
            $this->acessos,
            new ConteudoDoPushCompartilhado($this->publicacoes, $this->analises, $pedidos),
            new MockClock($this->agora()->modify('+' . $minutosDepois . ' minutes')),
        ))->executar($link->getEndereco(), $codigo);
    }

    #[TestDox('Endereço desconhecido: nao_encontrado, sem nenhum dado')]
    public function testNaoEncontrado(): void
    {
        $this->links->method('findOnePeloEndereco')->willReturn(null);
        $useCase = new AbrirLinkExternoDoPushUseCase($this->links, $this->acessos, new ConteudoDoPushCompartilhado($this->publicacoes, $this->analises, $this->createMock(PedidoDeExclusaoDeContaRepository::class)), new MockClock($this->agora()));

        $pagina = $useCase->executar('bluejus-nada-aaaaaaaa');

        self::assertSame('nao_encontrado', $pagina->estado);
        self::assertNull($pagina->advogado);
        self::assertSame([], $this->acessosGravados);
    }

    #[TestDox('Pasta excluída (lápide): nao_encontrado')]
    public function testPastaExcluida(): void
    {
        $link = $this->link();
        $link->getPasta()->marcarExcluida($this->usuario(), $this->agora());

        self::assertSame('nao_encontrado', $this->abrir($link)->estado);
    }

    #[TestDox('Pasta trancada depois de criar o link: nao_encontrado')]
    public function testPastaTrancada(): void
    {
        $link = $this->link();
        $link->getPasta()->trancar($this->usuario(), $this->agora());

        self::assertSame('nao_encontrado', $this->abrir($link)->estado);
    }

    #[TestDox('Processo que virou segredo de justiça depois de criar o link: nao_encontrado')]
    public function testProcessoEmSegredo(): void
    {
        $link = $this->link();
        $link->getPasta()->getProcessos()[0]->setNivelSigilo(1);

        self::assertSame('nao_encontrado', $this->abrir($link)->estado);
    }

    #[TestDox('Correção de segurança (PR 5): escritório suspenso pelo Master, em quarentena ou "suspenso a pedido" desliga o link')]
    public function testEscritorioForaDoArDesligaOLink(): void
    {
        $suspenso = $this->link();
        $suspenso->getTenant()->setIsActive(false);
        self::assertSame('nao_encontrado', $this->abrir($suspenso)->estado, 'suspenso pelo Master');

        $this->setUp();
        $quarentena = $this->link();
        $quarentena->getTenant()->setExcluidoEm($this->agora());
        self::assertSame('nao_encontrado', $this->abrir($quarentena)->estado, 'em quarentena');

        $this->setUp();
        self::assertSame('nao_encontrado', $this->abrir($this->link(), pedidoDeExclusao: true)->estado, 'suspenso a pedido');

        $this->setUp();
        self::assertNotSame('nao_encontrado', $this->abrir($this->link())->estado, 'escritório no ar continua abrindo');
    }

    #[TestDox('Pasta cancelada ou arquivada depois de criar o link: nao_encontrado')]
    #[DataProvider('situacoesQueDesligam')]
    public function testSituacaoDesliga(string $situacao): void
    {
        $link = $this->link();
        $link->getPasta()->setSituacao($situacao);

        self::assertSame('nao_encontrado', $this->abrir($link)->estado);
    }

    public static function situacoesQueDesligam(): iterable
    {
        yield 'cancelada' => [Pasta::SITUACAO_CANCELADA];
        yield 'arquivada' => [Pasta::SITUACAO_ARQUIVADA];
    }

    #[TestDox('Processo do link desvinculado da pasta: nao_encontrado')]
    public function testProcessoDesvinculado(): void
    {
        $link = $this->link();
        $link->getPasta()->vincularProcesso($this->processo($link->getTenant(), '0000001-11.2024.8.07.0001'));
        $link->getPasta()->desvincularProcesso($link->getProcesso());

        self::assertSame('nao_encontrado', $this->abrir($link)->estado);
    }

    #[TestDox('Revogado e expirado não mostram dado do processo nem contam acesso')]
    public function testRevogadoEExpirado(): void
    {
        $revogado = $this->link();
        $revogado->revogar($this->usuario(), $this->agora());
        $paginaRevogada = $this->abrir($revogado);

        self::assertSame('revogado', $paginaRevogada->estado);
        self::assertNull($paginaRevogada->processo);
        self::assertSame([], $paginaRevogada->movimentacoes);
        self::assertSame([], $this->acessosGravados);
    }

    #[TestDox('Expirado traz validade e se aceita pedido; nada do processo')]
    public function testExpirado(): void
    {
        $pagina = $this->abrir($this->linkExpirado('123456'));

        self::assertSame('expirado', $pagina->estado);
        self::assertTrue($pagina->podePedirRenovacao);
        self::assertSame('09/10/2026 10:00', $pagina->expiraEm);
        self::assertNull($pagina->processo);
        self::assertSame([], $this->acessosGravados);
    }

    #[TestDox('Link sem código abre direto e grava 1 acesso')]
    public function testOkSemCodigo(): void
    {
        $link = $this->link();
        $link->getPasta()->setNomeCliente('Maria Silva');
        $link->getPasta()->setNomeAcao('Ação de cobrança');

        $pagina = $this->abrir($link);

        self::assertSame('ok', $pagina->estado);
        self::assertSame(self::NUMERO, $pagina->processo);
        self::assertSame('MARIA SILVA', $pagina->cliente);
        self::assertSame('AÇÃO DE COBRANÇA', $pagina->acao);
        self::assertCount(2, $pagina->movimentacoes);
        self::assertSame('Fica a parte intimada do despacho.', $pagina->movimentacoes[0]->texto);
        self::assertSame('vermelho', $pagina->movimentacoes[0]->cor);
        self::assertSame('TJDFT · 1ª Vara Cível de Brasília', $pagina->movimentacoes[0]->fonte);
        self::assertSame('ambar', $pagina->movimentacoes[1]->cor);
        self::assertSame('1ª Vara Cível de Brasília', $pagina->movimentacoes[1]->texto, 'sem teor, cai no órgão como o cartão da aba');
        self::assertSame('abcdefgh', $pagina->assinatura());
        self::assertSame('Ana Advogada', $pagina->advogado['nome'] ?? null);
        self::assertCount(1, $this->acessosGravados);
    }

    #[TestDox('Sem "mostrar cliente", o cliente não sai')]
    public function testSemCliente(): void
    {
        $link = $this->link(mostrarCliente: false);
        $link->getPasta()->setNomeCliente('Maria Silva');

        self::assertNull($this->abrir($link)->cliente);
    }

    #[TestDox('Análises só com "incluir análises", e só as não internas e concluídas')]
    public function testAnalises(): void
    {
        $link = $this->link(incluirAnalises: true);
        $this->analises->method('listarPorAlvo')->willReturn([
            $this->analise($link->getTenant(), interna: true),
            $this->analise($link->getTenant(), interna: false),
            $this->analise($link->getTenant(), interna: false, concluida: false),
        ]);

        $pagina = $this->abrir($link);

        self::assertCount(1, $pagina->analises);
        self::assertSame('Resumo da análise', $pagina->analises[0]->resumo);
        self::assertSame([['tipo' => 'prazo', 'texto' => 'Prazo de 15 dias']], $pagina->analises[0]->pontos);
    }

    #[TestDox('Com código: GET pede o código e não conta acesso')]
    public function testPedeCodigo(): void
    {
        $pagina = $this->abrir($this->link('123456'));

        self::assertSame('pede_codigo', $pagina->estado);
        self::assertNull($pagina->erroCodigo);
        self::assertSame([], $this->acessosGravados);
    }

    #[TestDox('Código errado: erro do desenho, tentativa somada, sem acesso; certo: ok com 1 acesso')]
    public function testCodigoErradoECerto(): void
    {
        $link = $this->link('123456');

        $errado = $this->abrir($link, '654321');
        self::assertSame('pede_codigo', $errado->estado);
        self::assertSame('Código incorreto. Confira e tente de novo.', $errado->erroCodigo);
        self::assertSame(1, $link->getTentativasCodigo());
        self::assertSame([], $this->acessosGravados);

        $certo = $this->abrir($link, '12 34 56');
        self::assertSame('ok', $certo->estado);
        self::assertSame(0, $link->getTentativasCodigo());
        self::assertCount(1, $this->acessosGravados);
    }

    #[TestDox('Cinco erros seguidos: bloqueado, e o código certo não passa durante o bloqueio')]
    public function testBloqueio(): void
    {
        $link = $this->link('123456');
        for ($i = 0; $i < 4; ++$i) {
            self::assertSame('pede_codigo', $this->abrir($link, '000000')->estado);
        }

        self::assertSame('bloqueado', $this->abrir($link, '000000')->estado);
        self::assertSame('bloqueado', $this->abrir($link, '123456', 5)->estado);
        self::assertSame('bloqueado', $this->abrir($link, null, 5)->estado);
        self::assertSame([], $this->acessosGravados);
    }
}
