<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\CorDaLinha;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\DocumentosDaSelecao;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\AssinaturaEletronica\UseCase\ExcluirRascunhosUseCase;
use App\AssinaturaEletronica\UseCase\MoverParaLixeiraUseCase;
use App\AssinaturaEletronica\UseCase\PintarDocumentosUseCase;
use App\AssinaturaEletronica\UseCase\RestaurarDaLixeiraUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MoverParaLixeiraUseCase::class)]
#[CoversClass(RestaurarDaLixeiraUseCase::class)]
#[CoversClass(ExcluirRascunhosUseCase::class)]
#[CoversClass(PintarDocumentosUseCase::class)]
#[CoversClass(DocumentosDaSelecao::class)]
final class UseCasesDaListaTest extends TestCase
{
    private DocumentoParaAssinarRepository&MockObject $documentos;

    /** @var list<EventoDaTrilha> */
    private array $eventos = [];

    private Tenant $tenant;

    /** @var array<int, callable(DocumentoParaAssinar): void|false> */
    private array $relidosPorId = [];

    private bool $travaConfigurada = false;

    /** @var array<int, list<VersaoDoPdf>> as versões guardadas do PDF, pelo id do documento */
    private array $versoesPorDocumento = [];

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 9);
        $this->documentos = $this->createMock(DocumentoParaAssinarRepository::class);
        $this->documentos->method('emTransacao')->willReturnCallback(static fn (callable $trabalho): mixed => $trabalho());
        $this->relidos([]);
    }

    #[TestDox('Lixeira: o que aguardava assinatura registra o cancelamento e depois a ida para a Lixeira; o que já estava lá fica como está')]
    public function testMoverParaLixeira(): void
    {
        $aguardando = $this->documento(1, SituacaoDoDocumento::Aguardando);
        $rascunho = $this->documento(2);
        $jaNaLixeira = $this->documento(3);
        $jaNaLixeira->moverParaLixeira(null, new \DateTimeImmutable('2026-11-01'));
        $this->selecionar([1, 2, 3], [$aguardando, $rascunho, $jaNaLixeira]);
        $eu = new User();

        $this->moverParaLixeira()->executar($eu, $this->tenant, [1, 2, 3]);

        self::assertSame(SituacaoDoDocumento::Cancelado, $aguardando->getSituacao());
        self::assertTrue($rascunho->estaNaLixeira());
        self::assertSame($eu, $rascunho->getExcluidoPor());
        self::assertSame('2026-11-01', $jaNaLixeira->getExcluidoEm()?->format('Y-m-d'), 'não é carimbado de novo');
        self::assertSame([
            [1, TipoDeEvento::EnvioCanceladoPelaExclusao],
            [1, TipoDeEvento::MovidoParaLixeira],
            [2, TipoDeEvento::MovidoParaLixeira],
        ], $this->eventosGravados());
    }

    #[TestDox('Lixeira: o vencido continua Expirado e só registra "Movido para a Lixeira", sem cancelamento')]
    public function testMoverParaLixeiraComVencido(): void
    {
        $vencido = $this->documento(1, SituacaoDoDocumento::Aguardando);
        // Um segundo antes do relógio do teste, que o MockClock monta em UTC.
        $venceu = new \DateTimeImmutable('2026-11-09 09:59:59', new \DateTimeZone('UTC'));
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'venceEm'))->setValue($vencido, $venceu);
        $this->selecionar([1], [$vencido]);

        $this->moverParaLixeira()->executar(new User(), $this->tenant, [1]);

        self::assertSame(SituacaoDoDocumento::Aguardando, $vencido->getSituacao(), 'não vira Cancelado');
        self::assertTrue($vencido->estaNaLixeira());
        self::assertSame([[1, TipoDeEvento::MovidoParaLixeira]], $this->eventosGravados());
    }

    #[TestDox('Lixeira: o que outra ação levou para a Lixeira depois da leitura não ganha evento de novo (decide com a linha travada e relida)')]
    public function testMoverParaLixeiraDecideComALinhaRelida(): void
    {
        $doc = $this->documento(1);
        $this->selecionar([1], [$doc]);
        $this->relidos([1 => static function (DocumentoParaAssinar $d): void {
            $d->moverParaLixeira(null, new \DateTimeImmutable('2026-11-09 09:59:00'));
        }]);

        $this->moverParaLixeira()->executar(new User(), $this->tenant, [1]);

        self::assertSame('2026-11-09 09:59:00', $doc->getExcluidoEm()?->format('Y-m-d H:i:s'), 'não é carimbado de novo');
        self::assertSame([], $this->eventos, 'nenhum evento repetido');
    }

    #[TestDox('restaurar: o que outra ação já tirou da Lixeira não ganha "Restaurado" de novo')]
    public function testRestaurarDecideComALinhaRelida(): void
    {
        $doc = $this->documento(1);
        $doc->moverParaLixeira(null, new \DateTimeImmutable('2026-11-01'));
        $this->selecionar([1], [$doc]);
        $this->relidos([1 => static function (DocumentoParaAssinar $d): void {
            $d->restaurar();
        }]);

        (new RestaurarDaLixeiraUseCase(new DocumentosDaSelecao($this->documentos), $this->documentos, $this->trilha()))
            ->executar(new User(), $this->tenant, [1]);

        self::assertSame([], $this->eventos);
    }

    #[TestDox('documento que sumiu entre a leitura e a trava: o lote inteiro vira 404, dentro da transação')]
    public function testDocumentoQueSumiuAntesDaTrava(): void
    {
        $this->selecionar([1, 2], [$this->documento(1), $this->documento(2)]);
        $this->relidos([2 => false]);

        $this->expectException(DocumentoNaoEncontrado::class);
        $this->moverParaLixeira()->executar(new User(), $this->tenant, [1, 2]);
    }

    #[TestDox('lote com um id de outro escritório: 404 e nada muda')]
    public function testLoteComIdAlheio(): void
    {
        $meu = $this->documento(1);
        $this->selecionar([1, 77], [$meu]);
        $this->documentos->expects(self::never())->method('emTransacao');

        try {
            $this->moverParaLixeira()->executar(new User(), $this->tenant, [1, 77]);
            self::fail('deveria ser 404');
        } catch (DocumentoNaoEncontrado) {
            self::assertFalse($meu->estaNaLixeira());
            self::assertSame([], $this->eventos);
        }
    }

    #[TestDox('restaurar: volta sem reativar o envio e registra "Restaurado da Lixeira"; o que não estava na Lixeira fica como está')]
    public function testRestaurar(): void
    {
        $cancelado = $this->documento(1, SituacaoDoDocumento::Aguardando);
        $cancelado->moverParaLixeira(null, new \DateTimeImmutable());
        $foraDaLixeira = $this->documento(2);
        $this->selecionar([1, 2], [$cancelado, $foraDaLixeira]);

        (new RestaurarDaLixeiraUseCase(new DocumentosDaSelecao($this->documentos), $this->documentos, $this->trilha()))
            ->executar(new User(), $this->tenant, [1, 2]);

        self::assertFalse($cancelado->estaNaLixeira());
        self::assertSame(SituacaoDoDocumento::Cancelado, $cancelado->getSituacao());
        self::assertSame([[1, TipoDeEvento::Restaurado]], $this->eventosGravados());
    }

    #[TestDox('excluir de vez: tudo ou nada; um enviado no lote recusa com o texto do desenho e nada sai')]
    public function testExcluirTudoOuNada(): void
    {
        $rascunho = $this->documento(1);
        $rascunho->moverParaLixeira(null, new \DateTimeImmutable());
        $enviado = $this->documento(2, SituacaoDoDocumento::Concluido);
        $enviado->moverParaLixeira(null, new \DateTimeImmutable());
        $this->selecionar([1, 2], [$rascunho, $enviado]);
        $this->documentos->expects(self::never())->method('remover');
        $disco = new ArmazenamentoEmMemoria();

        try {
            $this->excluir($disco)->executar($this->tenant, [1, 2]);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.excluir_enviado', $e->getMessage());
        }
    }

    #[TestDox('excluir de vez: rascunho fora da Lixeira também é recusado')]
    public function testExcluirForaDaLixeira(): void
    {
        $this->selecionar([1], [$this->documento(1)]);
        $this->documentos->expects(self::never())->method('remover');

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.excluir_fora_da_lixeira'));
        $this->excluir(new ArmazenamentoEmMemoria())->executar($this->tenant, [1]);
    }

    #[TestDox('excluir de vez: a conferência é feita com a linha travada e relida; o que foi enviado depois da leitura não sai')]
    public function testExcluirDecideComALinhaRelida(): void
    {
        $rascunho = $this->documento(1);
        $rascunho->moverParaLixeira(null, new \DateTimeImmutable());
        $this->selecionar([1], [$rascunho]);
        $this->relidos([1 => static function (DocumentoParaAssinar $d): void {
            (new \ReflectionProperty(DocumentoParaAssinar::class, 'situacao'))->setValue($d, SituacaoDoDocumento::Aguardando);
        }]);
        $this->documentos->expects(self::never())->method('remover');

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.excluir_enviado'));
        $this->excluir(new ArmazenamentoEmMemoria())->executar($this->tenant, [1]);
    }

    #[TestDox('excluir de vez: restaurado depois da leitura também não sai')]
    public function testExcluirRestauradoDepoisDaLeitura(): void
    {
        $rascunho = $this->documento(1);
        $rascunho->moverParaLixeira(null, new \DateTimeImmutable());
        $this->selecionar([1], [$rascunho]);
        $this->relidos([1 => static function (DocumentoParaAssinar $d): void {
            $d->restaurar();
        }]);
        $this->documentos->expects(self::never())->method('remover');

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.excluir_fora_da_lixeira'));
        $this->excluir(new ArmazenamentoEmMemoria())->executar($this->tenant, [1]);
    }

    #[TestDox('excluir de vez: os rascunhos saem do banco e, depois, o PDF e a logo própria saem do disco')]
    public function testExcluirApagaArquivosDepois(): void
    {
        $disco = new ArmazenamentoEmMemoria();
        $pdf = new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, 'a1.pdf');
        $logo = new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, 'l1.png');
        $disco->semear($pdf);
        $disco->semear($logo);
        $rascunho = $this->documento(1);
        $rascunho->definirArquivo('a1.pdf', 'contrato.pdf', 2);
        $rascunho->definirLogo(TipoDeLogo::Propria, 'l1.png');
        $rascunho->moverParaLixeira(null, new \DateTimeImmutable());
        $this->selecionar([1], [$rascunho]);
        $this->documentos->expects(self::once())->method('remover')->with($rascunho);

        $this->excluir($disco)->executar($this->tenant, [1]);

        self::assertFalse($disco->existe($pdf));
        self::assertFalse($disco->existe($logo));
    }

    #[TestDox('excluir de vez (etapa 5): o arquivo próprio do original já assinado também sai do disco, depois do banco')]
    public function testExcluirApagaArquivoDaVersao(): void
    {
        $disco = new ArmazenamentoEmMemoria();
        $pdf = new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, 'a1.pdf');
        $original = new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, 'v1.pdf');
        $alheia = new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, 'v2.pdf');
        $disco->semear($pdf);
        $disco->semear($original);
        $disco->semear($alheia);
        $rascunho = $this->documento(1);
        $rascunho->definirArquivo('a1.pdf', 'contrato.pdf', 2);
        $this->versoesPorDocumento[1] = [VersaoDoPdf::original($rascunho, 1, 'v1.pdf', 'contrato.pdf', str_repeat('a', 64), 10, ['v' => 1], new \DateTimeImmutable())];
        $rascunho->moverParaLixeira(null, new \DateTimeImmutable());
        $this->selecionar([1], [$rascunho]);
        $removidos = [];
        $this->documentos->method('remover')->willReturnCallback(static function (DocumentoParaAssinar $d) use (&$removidos, $disco, $original): void {
            $removidos[] = $d->getId();
            self::assertTrue($disco->existe($original), 'o arquivo da versão só sai depois do banco');
        });

        $this->excluir($disco)->executar($this->tenant, [1]);

        self::assertSame([1], $removidos);
        self::assertFalse($disco->existe($pdf));
        self::assertFalse($disco->existe($original));
        self::assertTrue($disco->existe($alheia), 'arquivo que não é versão do documento fica');
    }

    #[TestDox('cor: pinta todos os marcados, mesmo na Lixeira, sem evento na trilha')]
    public function testPintar(): void
    {
        $a = $this->documento(1);
        $b = $this->documento(2);
        $b->moverParaLixeira(null, new \DateTimeImmutable());
        $this->selecionar([1, 2], [$a, $b]);

        (new PintarDocumentosUseCase(new DocumentosDaSelecao($this->documentos), $this->documentos))
            ->executar($this->tenant, [1, 2], CorDaLinha::Roxo);

        self::assertSame(CorDaLinha::Roxo, $a->getCor());
        self::assertSame(CorDaLinha::Roxo, $b->getCor());
        self::assertSame([], $this->eventos);
    }

    private function moverParaLixeira(): MoverParaLixeiraUseCase
    {
        return new MoverParaLixeiraUseCase(new DocumentosDaSelecao($this->documentos), $this->documentos, $this->trilha(), new MockClock('2026-11-09 10:00:00'));
    }

    private function excluir(ArmazenamentoEmMemoria $disco): ExcluirRascunhosUseCase
    {
        $versoes = $this->createStub(VersaoDoPdfRepository::class);
        $versoes->method('doDocumento')->willReturnCallback(fn (DocumentoParaAssinar $d): array => $this->versoesPorDocumento[(int) $d->getId()] ?? []);

        return new ExcluirRascunhosUseCase(new DocumentosDaSelecao($this->documentos), $this->documentos, new RemocaoAposTransacao($disco, new NullLogger()), $versoes);
    }

    private function trilha(): TrilhaDoDocumento
    {
        $repositorio = $this->createMock(EventoDaTrilhaRepository::class);
        $repositorio->method('ultimoHashTravando')->willReturn(null);
        $repositorio->method('salvar')->willReturnCallback(function (EventoDaTrilha $e): void {
            $this->eventos[] = $e;
        });

        return new TrilhaDoDocumento($repositorio, new MockClock('2026-11-09 10:00:00'));
    }

    /**
     * Simula a trava com releitura: por id, o que o banco mostra ao travar (callable que muda o documento) ou `false`
     * quando o documento sumiu. O que não está no mapa volta como estava.
     *
     * @param array<int, callable(DocumentoParaAssinar): void|false> $porId
     */
    private function relidos(array $porId): void
    {
        $this->relidosPorId = $porId;
        if ($this->travaConfigurada) {
            return;
        }

        $this->travaConfigurada = true;
        $this->documentos->method('travarERecarregar')->willReturnCallback(function (DocumentoParaAssinar $d, Tenant $t): bool {
            self::assertSame($this->tenant, $t);
            $relido = $this->relidosPorId[(int) $d->getId()] ?? null;
            if ($relido === false) {
                return false;
            }
            if ($relido !== null) {
                $relido($d);
            }

            return true;
        });
    }

    /**
     * @param list<int>                  $ids
     * @param list<DocumentoParaAssinar> $encontrados
     */
    private function selecionar(array $ids, array $encontrados): void
    {
        $this->documentos->method('porIdsMesmoNaLixeira')->with($ids, $this->tenant)->willReturn($encontrados);
    }

    private function documento(int $id, SituacaoDoDocumento $situacao = SituacaoDoDocumento::Rascunho): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar($this->tenant, null, new \DateTimeImmutable('2026-11-01'));
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, $id);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'situacao'))->setValue($documento, $situacao);

        return $documento;
    }

    /** @return list<array{?int, TipoDeEvento}> */
    private function eventosGravados(): array
    {
        return array_map(static fn (EventoDaTrilha $e): array => [$e->getDocumento()->getId(), $e->getTipo()], $this->eventos);
    }
}
