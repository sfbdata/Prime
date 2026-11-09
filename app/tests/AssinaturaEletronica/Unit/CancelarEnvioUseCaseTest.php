<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\UseCase\CancelarEnvioUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Cancelar envio" do detalhe (contrato da etapa 2, seção 4.1) com os repositórios do domínio dublados: só o que
 * aguarda assinatura, decidido com a linha travada e relida; os links somem do `Detalhe`; documento de outro
 * escritório é 404.
 */
#[CoversClass(CancelarEnvioUseCase::class)]
final class CancelarEnvioUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private User $advogada;

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->advogada = self::usuario('advogada@escritorio.test', 'Dra. Advogada');
    }

    #[TestDox('cancela: Cancelado, "envio_cancelado" com quem cancelou e o Detalhe sem os links')]
    public function testCancela(): void
    {
        $documento = $this->documentoEnviado();

        $detalhe = $this->cancelar()->executar($this->advogada, $this->tenant, 1);

        self::assertSame(SituacaoDoDocumento::Cancelado, $documento->getSituacao());
        self::assertSame(['envio_cancelado'], $this->tiposGravados());
        self::assertSame($this->advogada, $this->gravados[0]->getUsuario());
        self::assertSame('cancelado', $detalhe['situacao']);
        self::assertSame([null, null], array_column($detalhe['signatarios'], 'link'));
    }

    #[TestDox('rascunho: recusa e nada é gravado')]
    public function testRascunho(): void
    {
        $this->rascunho();

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.cancelar_fora_do_envio'));
        $this->cancelar()->executar($this->advogada, $this->tenant, 1);
    }

    #[TestDox('decide com a linha relida: o que foi recusado depois da leitura não é cancelado')]
    public function testRecusadoDepoisDaLeitura(): void
    {
        $documento = $this->documentoEnviado();
        $this->aoTravar = static function (DocumentoParaAssinar $d): void {
            (new \ReflectionProperty($d, 'situacao'))->setValue($d, SituacaoDoDocumento::Recusado);
        };

        try {
            $this->cancelar()->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.cancelar_fora_do_envio', $e->getMessage());
        }
        self::assertSame(SituacaoDoDocumento::Recusado, $documento->getSituacao());
        self::assertSame([], $this->gravados);
    }

    #[TestDox('documento de outro escritório: 404, sem transação')]
    public function testOutroEscritorio(): void
    {
        $documento = $this->documentoEnviado();
        $this->documentos->expects(self::never())->method('emTransacao');

        try {
            $this->cancelar()->executar($this->advogada, new Tenant(), 1);
            self::fail('deveria ser 404');
        } catch (DocumentoNaoEncontrado) {
            self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
        }
    }

    #[TestDox('documento que sumiu entre a leitura e a trava: 404')]
    public function testSumiuNaTrava(): void
    {
        $this->documentoEnviado();
        $this->documentoSumiu = true;

        $this->expectException(DocumentoNaoEncontrado::class);
        $this->cancelar()->executar($this->advogada, $this->tenant, 1);
    }

    private function cancelar(): CancelarEnvioUseCase
    {
        return new CancelarEnvioUseCase($this->documentos, $this->eventosDaTrilha, $this->trilha(), $this->apresentacao());
    }
}
