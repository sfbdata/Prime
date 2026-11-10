<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\AssinaturaEletronica\UseCase\MarcarAssinadoEmPapelUseCase;
use App\AssinaturaEletronica\UseCase\MontarFolhaParaImprimirUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MarcarAssinadoEmPapelUseCase::class)]
#[CoversClass(MontarFolhaParaImprimirUseCase::class)]
#[CoversClass(DocumentoParaAssinar::class)]
final class MarcarAssinadoEmPapelTest extends TestCase
{
    private DocumentoParaAssinarRepository&MockObject $documentos;

    /** @var list<EventoDaTrilha> */
    private array $eventos = [];

    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 9);
        $this->documentos = $this->createMock(DocumentoParaAssinarRepository::class);
        $this->documentos->method('emTransacao')->willReturnCallback(static fn (callable $trabalho): mixed => $trabalho());
        $this->documentos->method('travarERecarregar')->willReturn(true);
    }

    #[TestDox('conclui o rascunho e registra na trilha quem confirmou e quando, no horário de Brasília')]
    public function testMarcaRascunho(): void
    {
        $documento = $this->documento();
        $this->documentos->method('doEscritorioMesmoNaLixeira')->with(1, $this->tenant)->willReturn($documento);
        $ator = (new User())->setFullName('Ana Souza');

        $this->usecase()->executar($ator, $this->tenant, 1);

        self::assertSame(SituacaoDoDocumento::Concluido, $documento->getSituacao());
        self::assertSame('2026-11-10 13:30', $documento->getConcluidoEm()?->format('Y-m-d H:i'));
        self::assertCount(1, $this->eventos);
        self::assertSame(TipoDeEvento::AssinadoEmPapel, $this->eventos[0]->getTipo());
        self::assertSame(['nome' => 'Ana Souza', 'data' => '10/11/2026 10:30'], $this->eventos[0]->getDados());
        self::assertSame($ator, $this->eventos[0]->getUsuario());
    }

    #[TestDox('aguardando também pode; concluído, recusado, cancelado e Lixeira são recusados, sem evento')]
    public function testSituacoes(): void
    {
        $this->documentos->method('doEscritorioMesmoNaLixeira')->willReturnOnConsecutiveCalls(
            $this->documento(SituacaoDoDocumento::Aguardando),
            $this->documento(SituacaoDoDocumento::Concluido),
            $this->documento(SituacaoDoDocumento::Recusado),
            $this->documento(SituacaoDoDocumento::Cancelado),
            $this->naLixeira($this->documento()),
        );

        $this->usecase()->executar(new User(), $this->tenant, 1);
        self::assertCount(1, $this->eventos);

        for ($i = 0; $i < 4; ++$i) {
            try {
                $this->usecase()->executar(new User(), $this->tenant, 1);
                self::fail('devia recusar');
            } catch (AcaoRecusada $e) {
                self::assertSame('assinatura_eletronica.erro.papel_situacao', $e->getMessage());
            }
        }
        self::assertCount(1, $this->eventos, 'as recusas não gravam evento');
    }

    #[TestDox('documento que não é do escritório: não encontrado')]
    public function testOutroEscritorio(): void
    {
        $this->documentos->method('doEscritorioMesmoNaLixeira')->willReturn(null);

        $this->expectException(DocumentoNaoEncontrado::class);
        $this->usecase()->executar(new User(), $this->tenant, 1);
    }

    #[TestDox('a folha para imprimir traz o texto inteiro escapado, o lugar de cada signatário e os que sobraram')]
    public function testFolhaParaImprimir(): void
    {
        $documento = $this->documento();
        $documento->aplicarRascunho(
            \App\AssinaturaEletronica\DTO\DadosDoRascunho::deJson([
                'nome' => 'Contrato',
                'signatarios' => [
                    ['nome' => 'Ana', 'papel' => 'Contratante', 'nivel' => 'simples'],
                    ['nome' => 'Beto', 'papel' => 'Contratado', 'nivel' => 'simples'],
                ],
            ]),
            [['k' => 't', 'x' => 'Contrato <script>'], ['k' => 'p', 'x' => "Linha {b}forte{/b}\u{1}x\u{2}"], ['k' => 'a', 'i' => 0, 'r' => 'Contratante'], ['k' => 'pg', 'n' => 2]],
            new \DateTimeImmutable('2026-11-02'),
        );
        $this->documentos->method('doEscritorioMesmoNaLixeira')->willReturn($documento);

        $folha = (new MontarFolhaParaImprimirUseCase($this->documentos))->executar($this->tenant, 1);

        self::assertSame('Contrato &lt;script&gt;', $folha->itens[0]['html']);
        self::assertSame('Linha <b>forte</b>x', $folha->itens[1]['html']);
        self::assertEquals(['tipo' => 'a', 'html' => '', 'nome' => 'Ana', 'papel' => 'Contratante', 'pagina' => 0], $folha->itens[2]);
        self::assertSame(2, $folha->itens[3]['pagina']);
        self::assertSame([['nome' => 'Beto', 'papel' => 'Contratado']], $folha->sobraram);
        self::assertFalse($folha->semNinguem);
    }

    private function usecase(): MarcarAssinadoEmPapelUseCase
    {
        $repositorio = $this->createMock(EventoDaTrilhaRepository::class);
        $repositorio->method('ultimoHashTravando')->willReturn(null);
        $repositorio->method('salvar')->willReturnCallback(function (EventoDaTrilha $e): void {
            $this->eventos[] = $e;
        });
        $relogio = new MockClock('2026-11-10 13:30:00');

        return new MarcarAssinadoEmPapelUseCase($this->documentos, new TrilhaDoDocumento($repositorio, $relogio), $relogio);
    }

    private function documento(SituacaoDoDocumento $situacao = SituacaoDoDocumento::Rascunho): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar($this->tenant, null, new \DateTimeImmutable('2026-11-01'));
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, 1);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'situacao'))->setValue($documento, $situacao);

        return $documento;
    }

    private function naLixeira(DocumentoParaAssinar $documento): DocumentoParaAssinar
    {
        $documento->moverParaLixeira(null, new \DateTimeImmutable('2026-11-05'));

        return $documento;
    }
}
