<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\EstadoDaPagina;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As transições da etapa 2 (contrato, seção 4.1) e o estado que a página pública mostra (seção 5.3): envio, ordem,
 * prazo, conclusão, recusa, cancelamento e o código de 6 dígitos do signatário.
 */
#[CoversClass(DocumentoParaAssinar::class)]
#[CoversClass(Signatario::class)]
final class TransicoesDaAssinaturaTest extends TestCase
{
    private const ENVIO = '2026-11-09 10:00:00';

    #[TestDox('enviar: rascunho vira aguardando, com hash, hora do envio e prazo contado dela')]
    public function testEnviar(): void
    {
        $documento = self::documento(emOrdem: false);
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 10:00:00.750'));

        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
        self::assertSame(str_repeat('f', 64), $documento->getHashEnviado());
        self::assertSame('2026-11-09 10:00:00.000000', $documento->getEnviadoEm()?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-11-16 10:00:00', $documento->getVenceEm()?->format('Y-m-d H:i:s'));

        self::assertRecusa('assinatura_eletronica.erro.envio_ja_feito', static fn () => $documento->enviar('x', new \DateTimeImmutable()));
    }

    #[TestDox('enviar recusa sem signatários e na Lixeira')]
    public function testEnviarRecusado(): void
    {
        $vazio = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable(self::ENVIO));
        self::assertRecusa('assinatura_eletronica.erro.envio_sem_signatarios', static fn () => $vazio->enviar('x', new \DateTimeImmutable()));

        $naLixeira = self::documento(emOrdem: false);
        $naLixeira->moverParaLixeira(null, new \DateTimeImmutable(self::ENVIO));
        self::assertRecusa('assinatura_eletronica.erro.envio_na_lixeira', static fn () => $naLixeira->enviar('x', new \DateTimeImmutable()));
    }

    #[TestDox('em ordem: o segundo aguarda o primeiro; o último a assinar conclui o documento')]
    public function testOrdemEConclusao(): void
    {
        $documento = self::enviado(emOrdem: true);
        [$ana, $bia] = $documento->getSignatarios();
        $agora = new \DateTimeImmutable('2026-11-10 08:00:00');

        self::assertSame(EstadoDaPagina::Pronto, $documento->estadoPara($ana, $agora));
        self::assertSame(EstadoDaPagina::AguardandoAnterior, $documento->estadoPara($bia, $agora));
        self::assertSame($ana, $documento->anteriorPendente($bia));
        self::assertRecusa('assinatura_eletronica.publica.estado.aguardando_anterior', static fn () => self::assinar($documento, $bia, $agora));

        self::assertFalse(self::assinar($documento, $ana, $agora));
        self::assertSame(EstadoDaPagina::JaAssinado, $documento->estadoPara($ana, $agora));
        self::assertSame(EstadoDaPagina::Pronto, $documento->estadoPara($bia, $agora));
        self::assertSame('Ana Maria Lima', $ana->getNomeDigitado());
        self::assertSame('rubrica.png', $ana->getRubricaChave());
        self::assertSame('simples', $ana->getMetodo());

        self::assertTrue(self::assinar($documento, $bia, $agora));
        self::assertSame(SituacaoDoDocumento::Concluido, $documento->getSituacao());
        self::assertSame('2026-11-10 08:00:00', $documento->getConcluidoEm()?->format('Y-m-d H:i:s'));
        self::assertSame(EstadoDaPagina::Concluido, $documento->estadoPara($ana, $agora));
        self::assertSame(EstadoDaPagina::Concluido, $documento->estadoPara($bia, $agora));
    }

    #[TestDox('sem ordem, todos podem assinar desde o envio')]
    public function testSemOrdem(): void
    {
        $documento = self::enviado(emOrdem: false);
        [, $bia] = $documento->getSignatarios();

        self::assertNull($documento->anteriorPendente($bia));
        self::assertSame(EstadoDaPagina::Pronto, $documento->estadoPara($bia, new \DateTimeImmutable('2026-11-10 08:00:00')));
    }

    #[TestDox('prazo vencido: a situação não muda, a página mostra prazo encerrado e ninguém assina')]
    public function testPrazo(): void
    {
        $documento = self::enviado(emOrdem: false);
        [$ana] = $documento->getSignatarios();
        $depois = new \DateTimeImmutable('2026-11-16 10:00:01');

        self::assertTrue($documento->estaVencido($depois));
        self::assertFalse($documento->estaVencido(new \DateTimeImmutable('2026-11-16 10:00:00')));
        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
        self::assertSame(EstadoDaPagina::PrazoEncerrado, $documento->estadoPara($ana, $depois));
        self::assertRecusa('assinatura_eletronica.publica.estado.prazo_encerrado', static fn () => self::assinar($documento, $ana, $depois));
    }

    #[TestDox('recusa: o documento vira recusado; quem recusou vê "recusado", os demais veem "cancelado"')]
    public function testRecusa(): void
    {
        $documento = self::enviado(emOrdem: false);
        [$ana, $bia] = $documento->getSignatarios();
        $agora = new \DateTimeImmutable('2026-11-10 08:00:00');

        $documento->recusar($ana, 'Valor errado', '10.0.0.1', 'Navegador', $agora);

        self::assertSame(SituacaoDoDocumento::Recusado, $documento->getSituacao());
        self::assertSame(SituacaoDoSignatario::Recusado, $ana->getSituacao());
        self::assertSame('Valor errado', $ana->getMotivoRecusa());
        self::assertSame(EstadoDaPagina::Recusado, $documento->estadoPara($ana, $agora));
        self::assertSame(EstadoDaPagina::Cancelado, $documento->estadoPara($bia, $agora));
        self::assertRecusa('assinatura_eletronica.publica.estado.cancelado', static fn () => self::assinar($documento, $bia, $agora));
    }

    #[TestDox('cancelar envio: só aguardando; os links mostram "cancelado", como na Lixeira')]
    public function testCancelarEnvio(): void
    {
        $documento = self::enviado(emOrdem: false);
        [$ana] = $documento->getSignatarios();
        $agora = new \DateTimeImmutable('2026-11-10 08:00:00');

        $documento->cancelarEnvio();
        self::assertSame(SituacaoDoDocumento::Cancelado, $documento->getSituacao());
        self::assertSame(EstadoDaPagina::Cancelado, $documento->estadoPara($ana, $agora));
        self::assertRecusa('assinatura_eletronica.erro.cancelar_fora_do_envio', static fn () => $documento->cancelarEnvio());

        $naLixeira = self::enviado(emOrdem: false);
        $naLixeira->moverParaLixeira(null, $agora);
        self::assertSame(EstadoDaPagina::Cancelado, $naLixeira->estadoPara($naLixeira->getSignatarios()[0], $agora));
        self::assertSame(EstadoDaPagina::Invalido, self::documento(emOrdem: false)->estadoPara(self::documento(emOrdem: false)->getSignatarios()[0], $agora));
    }

    #[TestDox('código: vale 15 minutos, o novo zera os erros e o quinto erro queima')]
    public function testCodigo(): void
    {
        $ana = self::enviado(emOrdem: false)->getSignatarios()[0];
        $envio = new \DateTimeImmutable('2026-11-10 08:00:00');

        self::assertFalse($ana->temCodigo());
        $ana->registrarCodigo('hash', $envio);
        self::assertSame('2026-11-10 08:15:00', $ana->codigoValidoAte()?->format('Y-m-d H:i:s'));
        self::assertFalse($ana->codigoVencido(new \DateTimeImmutable('2026-11-10 08:15:00')));
        self::assertTrue($ana->codigoVencido(new \DateTimeImmutable('2026-11-10 08:15:01')));

        foreach ([1, 2, 3, 4] as $erro) {
            self::assertFalse($ana->errarCodigo(), 'erro ' . $erro);
        }
        $ana->registrarCodigo('outro', $envio);
        self::assertSame(0, $ana->getTentativas());
        foreach ([1, 2, 3, 4] as $erro) {
            $ana->errarCodigo();
        }
        self::assertTrue($ana->errarCodigo());
        self::assertFalse($ana->temCodigo());

        $ana->bloquearAte(new \DateTimeImmutable('2026-11-11 08:00:00'));
        self::assertTrue($ana->estaBloqueado($envio));
        self::assertFalse($ana->estaBloqueado(new \DateTimeImmutable('2026-11-11 08:00:00')));
    }

    private static function assinar(DocumentoParaAssinar $documento, Signatario $signatario, \DateTimeImmutable $agora): bool
    {
        return $documento->assinar($signatario, 'Ana Maria Lima', '52998224725', 'rubrica.png', 'simples', '10.0.0.1', 'Navegador', $agora);
    }

    private static function enviado(bool $emOrdem): DocumentoParaAssinar
    {
        $documento = self::documento($emOrdem);
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable(self::ENVIO));

        return $documento;
    }

    private static function documento(bool $emOrdem): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'emOrdem' => $emOrdem,
            'signatarios' => [['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'], ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com']],
        ]), [], new \DateTimeImmutable('2026-11-09 09:00:00'));

        return $documento;
    }

    private static function assertRecusa(string $chave, \Closure $acao): void
    {
        try {
            $acao();
            self::fail('deveria recusar com ' . $chave);
        } catch (AcaoRecusada $e) {
            self::assertSame($chave, $e->getMessage());
        }
    }
}
