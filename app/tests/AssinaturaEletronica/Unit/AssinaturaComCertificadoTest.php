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
 * A assinatura gov.br e ICP na entidade (contrato da etapa 5, seção 2, item 3): sem rubrica, com o nível como método,
 * na vez do signatário e com a conclusão igual à da Simples (o último a assinar conclui, misturando os níveis).
 */
#[CoversClass(DocumentoParaAssinar::class)]
#[CoversClass(Signatario::class)]
final class AssinaturaComCertificadoTest extends TestCase
{
    #[TestDox('gov.br assina sem rubrica, com o método "govbr" e a evidência; o código usado deixa de valer')]
    public function testGovbrAssina(): void
    {
        $documento = self::enviado(emOrdem: false);
        $govbr = $documento->getSignatarios()[1];
        $govbr->registrarCodigo('hash-do-codigo', new \DateTimeImmutable('2026-11-10 07:59:00'));

        $concluiu = $documento->assinarComCertificado($govbr, 'Bia Souza Lima', '52998224725', '10.0.0.2', 'Navegador', new \DateTimeImmutable('2026-11-10 08:00:00.400'));

        self::assertFalse($concluiu);
        self::assertSame(SituacaoDoSignatario::Assinado, $govbr->getSituacao());
        self::assertSame('2026-11-10 08:00:00.000000', $govbr->getAssinadoEm()?->format('Y-m-d H:i:s.u'));
        self::assertSame('Bia Souza Lima', $govbr->getNomeDigitado());
        self::assertSame('52998224725', $govbr->getCpfDigitado());
        self::assertNull($govbr->getRubricaChave());
        self::assertSame('govbr', $govbr->getMetodo());
        self::assertSame('10.0.0.2', $govbr->getIp());
        self::assertSame('Navegador', $govbr->getUserAgent());
        self::assertFalse($govbr->temCodigo());
        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
    }

    #[TestDox('conclusão como na Simples: o último a assinar (ICP) conclui o documento na hora dele')]
    public function testUltimoConclui(): void
    {
        $documento = self::enviado(emOrdem: true);
        [$simples, $govbr, $icp] = $documento->getSignatarios();
        $agora = new \DateTimeImmutable('2026-11-10 08:00:00');

        self::assertFalse($documento->assinar($simples, 'Ana Maria Lima', '52998224725', 'rubrica.png', 'simples', null, null, $agora));
        self::assertFalse($documento->assinarComCertificado($govbr, 'Bia Souza', '11144477735', null, null, $agora));
        self::assertTrue($documento->assinarComCertificado($icp, 'Caio Reis', '52998224725', null, null, new \DateTimeImmutable('2026-11-10 09:30:00')));

        self::assertSame('icp', $icp->getMetodo());
        self::assertSame(SituacaoDoDocumento::Concluido, $documento->getSituacao());
        self::assertSame('2026-11-10 09:30:00', $documento->getConcluidoEm()?->format('Y-m-d H:i:s'));
        self::assertSame(EstadoDaPagina::Concluido, $documento->estadoPara($govbr, $agora));
    }

    #[TestDox('fora da vez: com "em ordem", o gov.br aguarda o anterior e nada é registrado')]
    public function testForaDaVez(): void
    {
        $documento = self::enviado(emOrdem: true);
        $govbr = $documento->getSignatarios()[1];

        try {
            $documento->assinarComCertificado($govbr, 'Bia Souza', '11144477735', null, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
            self::fail('deveria recusar fora da vez');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.publica.estado.aguardando_anterior', $e->getMessage());
        }
        self::assertTrue($govbr->estaPendente());
    }

    #[TestDox('signatário Simples ou Reforçada não assina com certificado; quem já assinou, também não')]
    public function testSoGovbrEIcp(): void
    {
        $documento = self::enviado(emOrdem: false);
        [$simples, $govbr] = $documento->getSignatarios();

        self::assertFalse($simples->assinaComCertificado());
        self::assertTrue($govbr->assinaComCertificado());
        try {
            $documento->assinarComCertificado($simples, 'Ana Lima', '52998224725', null, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
            self::fail('Simples não assina com certificado');
        } catch (\LogicException $e) {
            self::assertSame('Só signatário gov.br ou ICP assina com certificado.', $e->getMessage());
        }
        self::assertTrue($simples->estaPendente());

        $govbr->registrarAssinaturaComCertificado('Bia Souza', '11144477735', null, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        $this->expectExceptionObject(new \LogicException('Só signatário pendente assina ou recusa.'));
        $govbr->registrarAssinaturaComCertificado('Bia Souza', '11144477735', null, null, new \DateTimeImmutable('2026-11-10 08:01:00'));
    }

    /** Signatários: Ana (Simples), Bia (gov.br) e Caio (ICP). */
    private static function enviado(bool $emOrdem): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'emOrdem' => $emOrdem,
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'],
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr'],
                ['nome' => 'Caio Reis', 'email' => 'caio@exemplo.com', 'nivel' => 'icp'],
            ],
        ]), [], new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 10:00:00'));

        return $documento;
    }
}
