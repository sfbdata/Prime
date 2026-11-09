<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\SelfieIlegivel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A cifra da selfie da assinatura Reforçada (contrato da etapa 7, seção 3), com a chave própria
 * `ASSINATURA_SELFIE_CHAVE` (64 hexadecimais = 32 bytes; nunca o `APP_SECRET` nem o `ASSINATURA_SEGREDO`).
 *
 * Arquivo: `BJSELFIE` (8 bytes) + versão da chave (1 byte) + nonce aleatório (24 bytes) + XChaCha20-Poly1305-IETF do
 * JPEG (com a etiqueta de 16 bytes). Os dados associados são o cabeçalho de 9 bytes e
 * `|escritorio:{id}|documento:{id}|signatario:{id}`: abrir o arquivo como se fosse de outro dono, trocar um byte ou
 * cifrar com outra chave faz a decifração falhar. Troca futura da chave: entra a versão nova (mapa versão para chave)
 * sem apagar a antiga; até lá, arquivo com outra versão não abre.
 *
 * A chave nunca é logada; a cópia binária é zerada (`sodium_memzero`) depois de cada uso.
 */
final readonly class CofreDaSelfie
{
    public const VERSAO = 1;
    public const MARCA = 'BJSELFIE';
    private const CABECALHO = 9;

    public function __construct(
        #[Autowire(param: 'assinatura.selfie_chave')]
        #[\SensitiveParameter]
        private string $chaveEmHex,
    ) {
    }

    /** 64 caracteres hexadecimais; vazia, com outro tamanho ou com outro caractere: não configurada. */
    public function configurado(): bool
    {
        return preg_match('/^[0-9a-fA-F]{64}$/', $this->chaveEmHex) === 1;
    }

    /** @throws AcaoRecusada "Assinatura reforçada sem chave configurada. Fale com o suporte." */
    public function exigirConfigurado(): void
    {
        if (!$this->configurado()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.reforcada_sem_chave');
        }
    }

    /**
     * O arquivo cifrado do JPEG da selfie do signatário (gravado, com id, num documento de escritório gravado).
     *
     * @throws AcaoRecusada sem a chave configurada
     */
    public function cifrar(#[\SensitiveParameter] string $jpeg, Signatario $signatario): string
    {
        $this->exigirConfigurado();
        $cabecalho = self::MARCA . \chr(self::VERSAO);
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $chave = (string) hex2bin($this->chaveEmHex);
        try {
            $cifrado = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($jpeg, self::dadosAssociados($cabecalho, $signatario), $nonce, $chave);
        } finally {
            sodium_memzero($chave);
        }

        return $cabecalho . $nonce . $cifrado;
    }

    /**
     * O JPEG do arquivo cifrado, conferido contra o dono (escritório, documento e signatário).
     *
     * @throws AcaoRecusada  sem a chave configurada
     * @throws SelfieIlegivel cabeçalho, versão, dono, byte ou chave que não conferem
     */
    public function decifrar(string $arquivo, Signatario $signatario): string
    {
        $this->exigirConfigurado();
        $minimo = self::CABECALHO + \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        $cabecalho = substr($arquivo, 0, self::CABECALHO);
        if (\strlen($arquivo) < $minimo || $cabecalho !== self::MARCA . \chr(self::VERSAO)) {
            throw new SelfieIlegivel();
        }

        $nonce = substr($arquivo, self::CABECALHO, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $chave = (string) hex2bin($this->chaveEmHex);
        try {
            $jpeg = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($arquivo, self::CABECALHO + \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
                self::dadosAssociados($cabecalho, $signatario),
                $nonce,
                $chave,
            );
        } finally {
            sodium_memzero($chave);
        }

        return \is_string($jpeg) ? $jpeg : throw new SelfieIlegivel();
    }

    private static function dadosAssociados(string $cabecalho, Signatario $signatario): string
    {
        $documento = $signatario->getDocumento();

        return sprintf(
            '%s|escritorio:%d|documento:%d|signatario:%d',
            $cabecalho,
            $documento->getTenant()->getId() ?? throw new \LogicException('Selfie de escritório não gravado.'),
            $documento->getId() ?? throw new \LogicException('Selfie de documento não gravado.'),
            $signatario->getId() ?? throw new \LogicException('Selfie de signatário não gravado.'),
        );
    }
}
