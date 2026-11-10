<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Exception\MuitasTentativas;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\UseCase\SolicitarCodigoUseCase;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\Shared\Doubles\MailerQueGuarda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Enviar código" da tela pública (contrato da etapa 2, seção 3) com os repositórios do domínio dublados: só quem tem a
 * vez, só o HMAC no banco, o código só no e-mail (Reply-To de quem enviou o documento), `codigo_enviado` só depois de o
 * e-mail sair e o teto diário contado pela trilha.
 */
#[CoversClass(SolicitarCodigoUseCase::class)]
#[CoversClass(CorreioDaAssinatura::class)]
#[CoversClass(CartaDaAssinatura::class)]
final class SolicitarCodigoUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->quemEnviou = self::usuario('quem.enviou@escritorio.test', 'Dra. Remetente');
    }

    #[TestDox('código novo: o HMAC no banco, o código no e-mail com o Reply-To de quem enviou e "codigo_enviado" com a evidência depois do envio')]
    public function testEnviaOCodigo(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];

        $this->solicitar()->executar($this->chave->token($ana), self::evidencia());

        $email = $this->unicoEmail();
        self::assertSame('ana@exemplo.com', $email->getTo()[0]->getAddress());
        self::assertSame('quem.enviou@escritorio.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame(1, preg_match('/codigo=(\d{6})/', (string) $email->getHtmlBody(), $m));
        self::assertTrue($this->chave->codigoConfere($ana, $m[1]), 'só o HMAC do código do e-mail está guardado');
        self::assertSame('2026-11-09 10:00:00', $ana->getCodigoEnviadoEm()?->format('Y-m-d H:i:s'));
        self::assertSame(['codigo_enviado'], $this->tiposGravados());
        $evento = $this->gravados[0];
        self::assertSame($ana, $evento->getSignatario());
        self::assertNull($evento->getUsuario());
        self::assertSame('203.0.113.7', $evento->getIp());
        self::assertSame(['para' => 'a***@exemplo.com', 'chave' => 1, 'porta' => 51234], $evento->getDados());
        self::assertStringNotContainsString($m[1], (string) json_encode($evento->getDados()));
    }

    #[TestDox('sem quem enviou (usuário apagado): o código sai sem Reply-To')]
    public function testSemQuemEnviou(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->quemEnviou = null;

        $this->solicitar()->executar($this->chave->token($ana), self::evidencia());

        self::assertSame([], $this->unicoEmail()->getReplyTo());
    }

    #[TestDox('e-mail fora do ar: 422, só "email_nao_enviado" na trilha (o código que não saiu não conta no teto) e o log sem o código')]
    public function testEmailForaDoAr(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->mailer = new MailerQueGuarda(falhar: true);
        $token = $this->chave->token($ana);

        try {
            $this->solicitar()->executar($token, self::evidencia());
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.codigo_nao_enviado', $e->getMessage());
        }

        self::assertSame(['email_nao_enviado'], $this->tiposGravados());
        self::assertSame($ana, $this->ultimoGravado(TipoDeEvento::EmailNaoEnviado)->getSignatario());
        $this->assertLogSemToken($token);
    }

    #[TestDox('teto diário: o 11º código em 24 h bloqueia até 24 h depois do mais antigo e não manda nada')]
    public function testTetoDiario(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->contagens[TipoDeEvento::CodigoEnviado->value] = [10, new \DateTimeImmutable('2026-11-09 02:00:00')];

        try {
            $this->solicitar()->executar($this->chave->token($ana), self::evidencia());
            self::fail('deveria recusar');
        } catch (MuitasTentativas) {
            self::assertSame('2026-11-10 02:00:00', $ana->getBloqueadoAte()?->format('Y-m-d H:i:s'));
            self::assertFalse($ana->temCodigo());
            self::assertSame([], $this->mailer->enviados);
        }
    }

    #[TestDox('bloqueado: recusa sem gerar código')]
    public function testBloqueado(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $ana->bloquearAte(new \DateTimeImmutable('2026-11-09 12:00:00'));

        $this->expectException(MuitasTentativas::class);
        $this->solicitar()->executar($this->chave->token($ana), self::evidencia());
    }

    #[TestDox('em ordem, quem ainda não tem a vez: recusa com o estado e nada é gravado')]
    public function testForaDaVez(): void
    {
        $bia = $this->documentoEnviado(emOrdem: true)->getSignatarios()[1];

        try {
            $this->solicitar()->executar($this->chave->token($bia), self::evidencia());
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.publica.estado.aguardando_anterior', $e->getMessage());
        }
        self::assertFalse($bia->temCodigo());
        self::assertSame([], $this->mailer->enviados);
        self::assertSame([], $this->gravados);
    }

    #[TestDox('quem assina gov.br ou ICP não usa código: link inválido, sem código, e-mail nem trilha')]
    public function testCertificadoNaoPedeCodigo(): void
    {
        $bia = $this->documentoEnviado()->getSignatarios()[1];
        foreach ([NivelDeAssinatura::Govbr, NivelDeAssinatura::Icp] as $nivel) {
            (new \ReflectionProperty(Signatario::class, 'nivel'))->setValue($bia, $nivel);

            try {
                $this->solicitar()->executar($this->chave->token($bia), self::evidencia());
                self::fail('deveria recusar ' . $nivel->value);
            } catch (LinkInvalido) {
            }
        }
        self::assertFalse($bia->temCodigo());
        self::assertSame([], $this->mailer->enviados);
        self::assertSame([], $this->gravados);
    }

    #[TestDox('token desconhecido: link inválido')]
    public function testTokenDesconhecido(): void
    {
        $this->documentoEnviado();

        $this->expectException(LinkInvalido::class);
        $this->solicitar()->executar(str_repeat('a', 64), self::evidencia());
    }

    private function solicitar(): SolicitarCodigoUseCase
    {
        return new SolicitarCodigoUseCase($this->link(), $this->documentos, $this->eventosDaTrilha, $this->chave, $this->correio(), $this->relogio);
    }
}
