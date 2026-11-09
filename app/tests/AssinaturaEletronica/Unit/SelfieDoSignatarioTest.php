<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\LocalizacaoDoSignatario;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Exception\SelfieIlegivel;
use App\AssinaturaEletronica\Service\CofreDaSelfie;
use App\AssinaturaEletronica\Service\SelfieDoSignatario;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O serviço que abre a selfie guardada (contrato da etapa 7, seção 5.3), para a etapa 3: só do escritório do documento,
 * decifrada pelo cofre e conferida pelo sha256 gravado na assinatura.
 */
#[CoversClass(SelfieDoSignatario::class)]
#[CoversClass(ChavesDeAssinaturaEletronica::class)]
final class SelfieDoSignatarioTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const JPEG = "\xFF\xD8\xFF\xE0 selfie \xFF\xD9";

    private CofreDaSelfie $cofre;

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->cofre = new CofreDaSelfie(str_repeat('0f', 32));
    }

    #[TestDox('abre a selfie do escritório do documento; sem selfie, nulo')]
    public function testAbre(): void
    {
        [$ana, $bia] = $this->documentoEnviado()->getSignatarios();
        $this->guardar($ana, self::JPEG, hash('sha256', self::JPEG));

        self::assertSame(self::JPEG, $this->selfies()->abrir($ana, $this->tenant));
        self::assertNull($this->selfies()->abrir($bia, $this->tenant));
    }

    #[TestDox('outro escritório: o mesmo 404 do documento que não existe')]
    public function testOutroEscritorio(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->guardar($ana, self::JPEG, hash('sha256', self::JPEG));
        $outro = (new Tenant())->setName('Outro');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 10);

        $this->expectException(DocumentoNaoEncontrado::class);
        $this->selfies()->abrir($ana, $outro);
    }

    #[TestDox('sha256 que não confere com o gravado na assinatura: ilegível')]
    public function testSha256QueNaoConfere(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->guardar($ana, self::JPEG, str_repeat('a', 64));

        $this->expectException(SelfieIlegivel::class);
        $this->selfies()->abrir($ana, $this->tenant);
    }

    #[TestDox('arquivo que sumiu do disco: ilegível')]
    public function testArquivoSumiu(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->registrar($ana, 'naoexiste0000000000000000000000.cifrado', hash('sha256', self::JPEG));

        $this->expectException(SelfieIlegivel::class);
        $this->selfies()->abrir($ana, $this->tenant);
    }

    private function guardar(Signatario $signatario, string $jpeg, string $sha256): void
    {
        $chave = $this->disco->gravar(ChavesDeAssinaturaEletronica::novaSelfie($signatario, 'cifrado'), FonteDeConteudo::deTexto($this->cofre->cifrar($jpeg, $signatario)))->chave;
        $this->registrar($signatario, $chave->nome, $sha256);
    }

    private function registrar(Signatario $signatario, string $nome, string $sha256): void
    {
        (new \ReflectionProperty(Signatario::class, 'nivel'))->setValue($signatario, NivelDeAssinatura::Reforcada);
        $signatario->registrarReforco($nome, $sha256, CofreDaSelfie::VERSAO, LocalizacaoDoSignatario::deJson(['latitude' => 1, 'longitude' => 2, 'precisao' => 3]));
    }

    private function selfies(): SelfieDoSignatario
    {
        return new SelfieDoSignatario($this->disco, $this->cofre);
    }
}
