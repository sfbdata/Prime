<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\SelfieIlegivel;
use App\AssinaturaEletronica\Service\CofreDaSelfie;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O cofre da selfie (contrato da etapa 7, seção 3): XChaCha20-Poly1305 com a chave própria, cabeçalho com a marca e a
 * versão da chave, nonce novo a cada arquivo e dados associados que amarram o arquivo ao escritório, ao documento e ao
 * signatário. Qualquer byte trocado, outro dono ou outra chave: o arquivo não abre.
 */
#[CoversClass(CofreDaSelfie::class)]
#[CoversClass(SelfieIlegivel::class)]
final class CofreDaSelfieTest extends TestCase
{
    private const CHAVE = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const OUTRA_CHAVE = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';
    private const JPEG = "\xFF\xD8\xFF\xE0 conteúdo da selfie \xFF\xD9";

    #[TestDox('cifra e decifra: cabeçalho BJSELFIE + versão 1 + nonce de 24 bytes, 16 bytes de etiqueta e nada do conteúdo à vista')]
    public function testCifraEDecifra(): void
    {
        $cofre = new CofreDaSelfie(self::CHAVE);
        $ana = self::signatario(9, 1, 11);

        $arquivo = $cofre->cifrar(self::JPEG, $ana);

        self::assertSame('BJSELFIE', substr($arquivo, 0, 8));
        self::assertSame(CofreDaSelfie::VERSAO, \ord($arquivo[8]));
        self::assertSame(1, CofreDaSelfie::VERSAO);
        self::assertSame(8 + 1 + 24 + \strlen(self::JPEG) + 16, \strlen($arquivo));
        self::assertStringNotContainsString('conteúdo da selfie', $arquivo);
        self::assertSame(self::JPEG, $cofre->decifrar($arquivo, $ana));
        self::assertNotSame(substr($arquivo, 9, 24), substr($cofre->cifrar(self::JPEG, $ana), 9, 24), 'nonce novo a cada arquivo');
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function outrosDonos(): iterable
    {
        yield 'outro signatário' => [9, 1, 12];
        yield 'outro documento' => [9, 2, 11];
        yield 'outro escritório' => [10, 1, 11];
    }

    #[DataProvider('outrosDonos')]
    #[TestDox('o arquivo aberto como se fosse de $_dataName não abre (dados associados)')]
    public function testOutroDono(int $tenant, int $documento, int $signatario): void
    {
        $cofre = new CofreDaSelfie(self::CHAVE);
        $arquivo = $cofre->cifrar(self::JPEG, self::signatario(9, 1, 11));

        $this->expectException(SelfieIlegivel::class);
        $cofre->decifrar($arquivo, self::signatario($tenant, $documento, $signatario));
    }

    /** @return iterable<string, array{\Closure(string): string}> */
    public static function adulterados(): iterable
    {
        yield 'marca trocada' => [static fn (string $a): string => 'BJSELFIX' . substr($a, 8)];
        yield 'versão desconhecida' => [static fn (string $a): string => substr($a, 0, 8) . \chr(2) . substr($a, 9)];
        yield 'nonce trocado' => [static fn (string $a): string => self::trocarByte($a, 20)];
        yield 'byte do conteúdo trocado' => [static fn (string $a): string => self::trocarByte($a, 40)];
        yield 'etiqueta trocada' => [static fn (string $a): string => self::trocarByte($a, \strlen($a) - 1)];
        yield 'cortado' => [static fn (string $a): string => substr($a, 0, -1)];
        yield 'só o cabeçalho' => [static fn (string $a): string => substr($a, 0, 33)];
        yield 'vazio' => [static fn (string $a): string => ''];
    }

    /** @param \Closure(string): string $adulterar */
    #[DataProvider('adulterados')]
    #[TestDox('arquivo adulterado ($_dataName) não abre')]
    public function testAdulterado(\Closure $adulterar): void
    {
        $cofre = new CofreDaSelfie(self::CHAVE);
        $ana = self::signatario(9, 1, 11);

        $this->expectException(SelfieIlegivel::class);
        $cofre->decifrar($adulterar($cofre->cifrar(self::JPEG, $ana)), $ana);
    }

    #[TestDox('arquivo cifrado com outra chave não abre')]
    public function testOutraChave(): void
    {
        $ana = self::signatario(9, 1, 11);
        $arquivo = (new CofreDaSelfie(self::OUTRA_CHAVE))->cifrar(self::JPEG, $ana);

        $this->expectException(SelfieIlegivel::class);
        (new CofreDaSelfie(self::CHAVE))->decifrar($arquivo, $ana);
    }

    /** @return iterable<string, array{string}> */
    public static function chavesRuins(): iterable
    {
        yield 'vazia' => [''];
        yield '63 caracteres' => [substr(self::CHAVE, 0, 63)];
        yield '66 caracteres' => [self::CHAVE . 'ab'];
        yield 'caractere que não é hexadecimal' => ['g' . substr(self::CHAVE, 1)];
        yield '32 bytes de texto' => [str_repeat('x', 32)];
        yield 'quebra de linha no fim' => [self::CHAVE . "\n"];
    }

    #[DataProvider('chavesRuins')]
    #[TestDox('chave $_dataName: não configurada; cifrar e decifrar recusam com o aviso de sem chave')]
    public function testChaveAusenteOuInvalida(string $chave): void
    {
        $cofre = new CofreDaSelfie($chave);
        $ana = self::signatario(9, 1, 11);
        self::assertFalse($cofre->configurado());

        foreach ([static fn () => $cofre->cifrar(self::JPEG, $ana), static fn () => $cofre->decifrar(str_repeat('x', 60), $ana)] as $chamada) {
            try {
                $chamada();
                self::fail('deveria recusar');
            } catch (AcaoRecusada $e) {
                self::assertSame('assinatura_eletronica.erro.reforcada_sem_chave', $e->getMessage());
            }
        }
    }

    #[TestDox('chave em maiúsculas vale (64 hexadecimais); exigirConfigurado passa')]
    public function testChaveEmMaiusculas(): void
    {
        $cofre = new CofreDaSelfie(strtoupper(self::CHAVE));
        $cofre->exigirConfigurado();
        $ana = self::signatario(9, 1, 11);

        self::assertTrue($cofre->configurado());
        self::assertSame(self::JPEG, (new CofreDaSelfie(self::CHAVE))->decifrar($cofre->cifrar(self::JPEG, $ana), $ana));
    }

    private static function trocarByte(string $arquivo, int $posicao): string
    {
        $arquivo[$posicao] = \chr(\ord($arquivo[$posicao]) ^ 0x01);

        return $arquivo;
    }

    private static function signatario(int $tenantId, int $documentoId, int $signatarioId): Signatario
    {
        $tenant = (new Tenant())->setName('Escritório');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $tenantId);
        $momento = new \DateTimeImmutable('2026-11-09 08:00:00');
        $documento = new DocumentoParaAssinar($tenant, (new User())->setEmail('a@b.test')->setFullName('Carla'), $momento);
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'signatarios' => [['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'nivel' => 'reforcada']],
        ]), [], $momento);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, $documentoId);
        $signatario = $documento->getSignatarios()[0];
        (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, $signatarioId);

        return $signatario;
    }
}
