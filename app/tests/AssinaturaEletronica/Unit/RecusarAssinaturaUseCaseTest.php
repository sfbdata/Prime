<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\UseCase\RecusarAssinaturaUseCase;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Recusar assinatura" da tela pública (contrato da etapa 2, seções 4.1 e 5.2) com os repositórios do domínio
 * dublados: o motivo de 1 a 500 caracteres, só quem tem a vez (decidido com a linha travada e relida), o documento
 * recusado e o aviso ao remetente depois do COMMIT.
 */
#[CoversClass(RecusarAssinaturaUseCase::class)]
final class RecusarAssinaturaUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    protected function setUp(): void
    {
        $this->montarOficina();
    }

    #[TestDox('recusa: o documento vira Recusado, o motivo vai para a trilha e o remetente recebe o aviso')]
    public function testRecusa(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];

        $publico = $this->recusar()->executar($this->chave->token($ana), '  Valor errado  ', self::evidencia());

        self::assertSame('recusado', $publico['estado']);
        self::assertSame(SituacaoDoDocumento::Recusado, $ana->getDocumento()->getSituacao());
        self::assertSame(SituacaoDoSignatario::Recusado, $ana->getSituacao());
        self::assertSame('Valor errado', $ana->getMotivoRecusa());
        self::assertSame(['recusado'], $this->tiposGravados());
        self::assertSame(['motivo' => 'Valor errado', 'chave' => 1, 'porta' => 51234], $this->gravados[0]->getDados());
        self::assertSame('203.0.113.7', $this->gravados[0]->getIp());
        $aviso = $this->unicoEmail();
        self::assertSame('criadora@escritorio.test', $aviso->getTo()[0]->getAddress());
        self::assertStringContainsString('motivo=Valor errado', (string) $aviso->getHtmlBody());
    }

    /** @return iterable<string, array{string, string}> */
    public static function motivosRecusados(): iterable
    {
        yield 'vazio' => ['   ', 'assinatura_eletronica.erro.recusa_sem_motivo'];
        yield 'com 501 caracteres' => [str_repeat('m', 501), 'assinatura_eletronica.erro.recusa_motivo_longo'];
    }

    #[DataProvider('motivosRecusados')]
    #[TestDox('motivo $_dataName: recusa antes da transação')]
    public function testMotivoRecusado(string $motivo, string $erro): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->documentos->expects(self::never())->method('emTransacao');

        $this->expectExceptionObject(new AcaoRecusada($erro));
        $this->recusar()->executar($this->chave->token($ana), $motivo, self::evidencia());
    }

    #[TestDox('decide com a linha relida: o envio cancelado depois da leitura recusa e nada é gravado')]
    public function testCanceladoDepoisDaLeitura(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->aoTravar = static function (DocumentoParaAssinar $d): void {
            $d->cancelarEnvio();
        };

        try {
            $this->recusar()->executar($this->chave->token($ana), 'Valor errado', self::evidencia());
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.publica.estado.cancelado', $e->getMessage());
        }
        self::assertSame(SituacaoDoSignatario::Pendente, $ana->getSituacao());
        self::assertSame([], $this->gravados);
        self::assertSame([], $this->mailer->enviados);
    }

    #[TestDox('token desconhecido: link inválido')]
    public function testTokenDesconhecido(): void
    {
        $this->documentoEnviado();

        $this->expectException(LinkInvalido::class);
        $this->recusar()->executar(str_repeat('b', 64), 'Valor errado', self::evidencia());
    }

    private function recusar(): RecusarAssinaturaUseCase
    {
        return new RecusarAssinaturaUseCase($this->link(), $this->documentos, $this->trilha(), $this->correio(), $this->pagina(), $this->relogio);
    }
}
