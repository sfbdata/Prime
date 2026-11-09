<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O token e o código da Assinatura (contrato da etapa 2, seção 3) com valores fixos: a fórmula não pode mudar sem
 * derrubar os links abertos. Os valores esperados saíram de um HMAC-SHA256 calculado fora do PHP.
 */
#[CoversClass(ChaveDaAssinatura::class)]
final class ChaveDaAssinaturaTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste-com-32-bytes-ou-mais-0123';
    private const TOKEN = '33c46ce276c87f851ab315cbf613c768e157aa18c495b94eed10e7e527d8cdc1';
    private const HASH_DO_TOKEN = 'cd1d6f32101a71975975afcb6c947f76f29dca7b7463923fa623742220b7aecd';
    private const HASH_DO_CODIGO = '0cd5b1e2064490040dc4689161bd3856f04641e9b066edb96ae57cb9c1908225';

    #[TestDox('token = hex(HMAC(segredo, assinar|v1|token|{id}|{enviado_em em UTC})), com o fuso do servidor fora da conta')]
    public function testTokenComValorFixo(): void
    {
        $chave = new ChaveDaAssinatura(self::SEGREDO);

        self::assertSame(self::TOKEN, $chave->token(self::signatario('2026-11-09 09:00:00', 'America/Sao_Paulo')));
        self::assertSame(self::TOKEN, $chave->token(self::signatario('2026-11-09 12:00:00', 'UTC')));
        self::assertSame(self::HASH_DO_TOKEN, ChaveDaAssinatura::hashDoToken(self::TOKEN));
    }

    #[TestDox('código: só o HMAC fica no banco e a conferência aceita só o código certo')]
    public function testCodigoComValorFixo(): void
    {
        $chave = new ChaveDaAssinatura(self::SEGREDO);
        $signatario = self::signatario('2026-11-09 12:00:00', 'UTC');

        self::assertSame(self::HASH_DO_CODIGO, $chave->hashDoCodigo($signatario, '123456'));
        self::assertFalse($chave->codigoConfere($signatario, '123456'), 'sem código pedido, nada confere');

        $signatario->registrarCodigo($chave->hashDoCodigo($signatario, '123456'), new \DateTimeImmutable('2026-11-09 12:00:00'));
        self::assertTrue($chave->codigoConfere($signatario, '123456'));
        self::assertFalse($chave->codigoConfere($signatario, '123457'));
        self::assertMatchesRegularExpression('/^[1-9]\d{5}$/', ChaveDaAssinatura::novoCodigo());
    }

    #[TestDox('segredo vazio ou com menos de 32 bytes: sem chave configurada, token e código recusam')]
    public function testSemSegredo(): void
    {
        $curto = new ChaveDaAssinatura(str_repeat('x', 31));
        self::assertFalse($curto->configurada());
        self::assertTrue((new ChaveDaAssinatura(str_repeat('x', 32)))->configurada());

        foreach ([static fn () => $curto->token(self::signatario('2026-11-09 12:00:00', 'UTC')), static fn () => $curto->hashDoCodigo(self::signatario('2026-11-09 12:00:00', 'UTC'), '123456')] as $acao) {
            try {
                $acao();
                self::fail('deveria recusar');
            } catch (AcaoRecusada $e) {
                self::assertSame('assinatura_eletronica.erro.sem_chave', $e->getMessage());
            }
        }
    }

    #[TestDox('token conferido: só quando o sha256 do token de agora bate com o token_hash do envio (segredo trocado, sem guardado ou sem segredo: nulo)')]
    public function testTokenConferido(): void
    {
        $chave = new ChaveDaAssinatura(self::SEGREDO);
        $signatario = self::signatario('2026-11-09 12:00:00', 'UTC');
        self::assertNull($chave->tokenConferido($signatario), 'sem token_hash guardado');

        $signatario->definirTokenHash(self::HASH_DO_TOKEN);
        self::assertSame(self::TOKEN, $chave->tokenConferido($signatario));
        self::assertNull((new ChaveDaAssinatura('outro-segredo-de-teste-com-32-bytes-0123456'))->tokenConferido($signatario), 'segredo trocado depois do envio');
        self::assertNull((new ChaveDaAssinatura(''))->tokenConferido($signatario), 'sem segredo não recusa: devolve nulo');
    }

    /** O signatário de id 7 de um documento enviado na hora dada, no fuso dado. */
    private static function signatario(string $enviadoEm, string $fuso): Signatario
    {
        $documento = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable('2026-11-01 09:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson(['nome' => 'Contrato', 'signatarios' => [['nome' => 'Ana Lima']]]), [], new \DateTimeImmutable('2026-11-01 09:00:00'));
        $documento->enviar(str_repeat('a', 64), new \DateTimeImmutable($enviadoEm, new \DateTimeZone($fuso)));
        $signatario = $documento->getSignatarios()[0];
        (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 7);

        return $signatario;
    }
}
