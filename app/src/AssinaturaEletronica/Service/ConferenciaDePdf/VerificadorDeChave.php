<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * A parte de chave do `confere` do protótipo: confere a assinatura do SignerInfo com a chave pública do certificado.
 * RSA PKCS#1 v1.5 e ECDSA pelo `openssl_verify`; RSA-PSS, que o PHP 8.2 não tem, pela EMSA-PSS da RFC 8017 sobre a
 * assinatura aberta sem padding (M6). Os parâmetros são os do protótipo: PSS com o hash do resumo, MGF1 com o mesmo hash
 * e sal do tamanho do hash; ECDSA só nas curvas P-256, P-384 e P-521.
 *
 * `true` e `false` são o resultado da conta; `null` é "não deu para conferir" (chave ilegível, tipo errado, algoritmo
 * ou curva fora da lista), como o `cripto` nulo do protótipo.
 */
final class VerificadorDeChave
{
    /** OID da assinatura RSA PKCS#1 v1.5 e o hash; null em `rsaEncryption` (usa o hash do resumo). */
    private const RSA_PKCS1 = [
        '2a864886f70d010101' => null,
        '2a864886f70d01010b' => 'SHA-256',
        '2a864886f70d01010c' => 'SHA-384',
        '2a864886f70d01010d' => 'SHA-512',
        '2a864886f70d010105' => 'SHA-1',
    ];
    private const RSA_PSS = '2a864886f70d01010a';
    /** OID da assinatura ECDSA e o hash; null em `ecPublicKey` (usa o hash do resumo). */
    private const ECDSA = [
        '2a8648ce3d040302' => 'SHA-256',
        '2a8648ce3d040303' => 'SHA-384',
        '2a8648ce3d040304' => 'SHA-512',
        '2a8648ce3d0401' => 'SHA-1',
        '2a8648ce3d0201' => null,
    ];
    /** Curva (OID do parâmetro da chave) e o tamanho de `r` e `s` em bytes. */
    private const CURVAS = [
        '2a8648ce3d030107' => 32,
        '2b81040022' => 48,
        '2b81040023' => 66,
    ];
    private const HASHES = [
        'SHA-256' => 'sha256',
        'SHA-384' => 'sha384',
        'SHA-512' => 'sha512',
        'SHA-1' => 'sha1',
    ];

    public const MOTIVO_ALGORITMO = 'Algoritmo de assinatura não suportado';
    public const MOTIVO_CURVA = 'Curva elíptica não suportada';
    public const MOTIVO_CHAVE = 'Não foi possível conferir a chave do certificado';

    /**
     * @param string $oidDaAssinatura   OID do signatureAlgorithm do SignerInfo, em hexadecimal
     * @param string $algoritmoDoResumo `SHA-256`, `SHA-384`, `SHA-512` ou `SHA-1`
     * @param string $alvo              o que foi assinado (atributos assinados ou o próprio conteúdo)
     * @param string $chavePublica      SubjectPublicKeyInfo em DER
     */
    public function verificar(string $oidDaAssinatura, string $algoritmoDoResumo, string $alvo, string $assinatura, string $chavePublica): ResultadoDaChave
    {
        try {
            if (\array_key_exists($oidDaAssinatura, self::RSA_PKCS1)) {
                return $this->rsa($alvo, $assinatura, $chavePublica, self::RSA_PKCS1[$oidDaAssinatura] ?? $algoritmoDoResumo);
            }
            if ($oidDaAssinatura === self::RSA_PSS) {
                return $this->pss($alvo, $assinatura, $chavePublica, $algoritmoDoResumo);
            }
            if (\array_key_exists($oidDaAssinatura, self::ECDSA)) {
                return $this->ecdsa($alvo, $assinatura, $chavePublica, self::ECDSA[$oidDaAssinatura] ?? $algoritmoDoResumo);
            }
        } catch (FormatoNaoReconhecido) {
            return ResultadoDaChave::naoConferida(self::MOTIVO_CHAVE);
        }

        return ResultadoDaChave::naoConferida(self::MOTIVO_ALGORITMO);
    }

    /**
     * EMSA-PSS-VERIFY (RFC 8017, 9.1.2) com MGF1 e sal do tamanho do hash. Pública para o teste com vetor do OpenSSL.
     */
    public function pss(string $mensagem, string $assinatura, string $chavePublica, string $algoritmoDoResumo): ResultadoDaChave
    {
        $hash = self::HASHES[$algoritmoDoResumo] ?? null;
        $chave = self::chave($chavePublica, \OPENSSL_KEYTYPE_RSA);
        if ($hash === null || $chave === null) {
            return ResultadoDaChave::naoConferida(self::MOTIVO_CHAVE);
        }

        $bits = (int) (SemAvisos::executar(static fn (): array|false => openssl_pkey_get_details($chave))['bits'] ?? 0);
        $tamanho = intdiv($bits + 7, 8);
        if ($bits < 512 || \strlen($assinatura) !== $tamanho) {
            return new ResultadoDaChave(false);
        }

        $em = '';
        $aberta = SemAvisos::executar(static function () use ($assinatura, &$em, $chave): bool {
            return openssl_public_decrypt($assinatura, $em, $chave, \OPENSSL_NO_PADDING);
        });
        if (!$aberta || !\is_string($em) || \strlen($em) > $tamanho) {
            return new ResultadoDaChave(false);
        }

        return new ResultadoDaChave(self::emsaPss($mensagem, str_pad($em, $tamanho, "\0", \STR_PAD_LEFT), $bits - 1, $hash));
    }

    private function rsa(string $alvo, string $assinatura, string $chavePublica, string $algoritmo): ResultadoDaChave
    {
        $hash = self::HASHES[$algoritmo] ?? null;
        $chave = self::chave($chavePublica, \OPENSSL_KEYTYPE_RSA);
        if ($hash === null || $chave === null) {
            return ResultadoDaChave::naoConferida(self::MOTIVO_CHAVE);
        }

        return self::resultadoDoOpenssl(SemAvisos::executar(static fn (): int|false => openssl_verify($alvo, $assinatura, $chave, $hash)));
    }

    /** @throws FormatoNaoReconhecido */
    private function ecdsa(string $alvo, string $assinatura, string $chavePublica, string $algoritmo): ResultadoDaChave
    {
        $spki = LeitorDer::abrir($chavePublica);
        $identificador = LeitorDer::filhos($chavePublica, LeitorDer::primeiroFilho($chavePublica, $spki));
        $tamanho = self::CURVAS[LeitorDer::hex($chavePublica, LeitorDer::item($identificador, 1))] ?? null;
        if ($tamanho === null) {
            return ResultadoDaChave::naoConferida(self::MOTIVO_CURVA);
        }

        $hash = self::HASHES[$algoritmo] ?? null;
        $chave = self::chave($chavePublica, \OPENSSL_KEYTYPE_EC);
        if ($hash === null || $chave === null) {
            return ResultadoDaChave::naoConferida(self::MOTIVO_CHAVE);
        }

        $sequencia = LeitorDer::abrir($assinatura);
        $partes = LeitorDer::filhos($assinatura, $sequencia);
        $r = self::inteiroCanonico(LeitorDer::valor($assinatura, LeitorDer::item($partes, 0)), $tamanho);
        $s = self::inteiroCanonico(LeitorDer::valor($assinatura, LeitorDer::item($partes, 1)), $tamanho);
        if ($r === null || $s === null) {
            return ResultadoDaChave::naoConferida(self::MOTIVO_CHAVE);
        }
        $canonica = self::tlv(0x30, self::tlv(0x02, $r) . self::tlv(0x02, $s));

        return self::resultadoDoOpenssl(SemAvisos::executar(static fn (): int|false => openssl_verify($alvo, $canonica, $chave, $hash)));
    }

    private static function emsaPss(string $mensagem, string $em, int $bitsDoEm, string $hash): bool
    {
        $tamanhoDoEm = intdiv($bitsDoEm + 7, 8);
        if (\strlen($em) > $tamanhoDoEm) {
            if (ltrim(substr($em, 0, \strlen($em) - $tamanhoDoEm), "\0") !== '') {
                return false;
            }
            $em = substr($em, -$tamanhoDoEm);
        }

        $tamanhoDoHash = \strlen(hash($hash, '', true));
        $tamanhoDoSal = $tamanhoDoHash;
        if (\strlen($em) !== $tamanhoDoEm || $tamanhoDoEm < $tamanhoDoHash + $tamanhoDoSal + 2 || \ord($em[$tamanhoDoEm - 1]) !== 0xBC) {
            return false;
        }

        $tamanhoDoDb = $tamanhoDoEm - $tamanhoDoHash - 1;
        $mascarado = substr($em, 0, $tamanhoDoDb);
        $h = substr($em, $tamanhoDoDb, $tamanhoDoHash);
        $mascaraDoTopo = 0xFF >> (8 * $tamanhoDoEm - $bitsDoEm);
        if ((\ord($mascarado[0]) & ~$mascaraDoTopo & 0xFF) !== 0) {
            return false;
        }

        $db = $mascarado ^ self::mgf1($h, $tamanhoDoDb, $hash);
        $db[0] = \chr(\ord($db[0]) & $mascaraDoTopo);
        $zeros = $tamanhoDoEm - $tamanhoDoHash - $tamanhoDoSal - 2;
        if (substr($db, 0, $zeros) !== str_repeat("\0", $zeros) || \ord($db[$zeros]) !== 0x01) {
            return false;
        }

        $sal = substr($db, -$tamanhoDoSal);
        $esperado = hash($hash, str_repeat("\0", 8) . hash($hash, $mensagem, true) . $sal, true);

        return hash_equals($esperado, $h);
    }

    private static function mgf1(string $semente, int $tamanho, string $hash): string
    {
        $mascara = '';
        for ($contador = 0; \strlen($mascara) < $tamanho; ++$contador) {
            $mascara .= hash($hash, $semente . pack('N', $contador), true);
        }

        return substr($mascara, 0, $tamanho);
    }

    private static function chave(string $chavePublica, int $tipo): ?\OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($chavePublica), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $chave = SemAvisos::executar(static fn (): \OpenSSLAsymmetricKey|false => openssl_pkey_get_public($pem));
        if ($chave === false) {
            return null;
        }
        $detalhes = SemAvisos::executar(static fn (): array|false => openssl_pkey_get_details($chave));

        return \is_array($detalhes) && ($detalhes['type'] ?? null) === $tipo ? $chave : null;
    }

    private static function resultadoDoOpenssl(int|false $retorno): ResultadoDaChave
    {
        return match ($retorno) {
            1 => new ResultadoDaChave(true),
            0 => new ResultadoDaChave(false),
            default => ResultadoDaChave::naoConferida(self::MOTIVO_CHAVE),
        };
    }

    /**
     * Inteiro do ECDSA como o protótipo trata: sem os zeros à esquerda; maior que a curva é inválido. Devolve os bytes
     * do INTEGER em DER canônico (zero à esquerda só quando o bit alto está ligado).
     */
    private static function inteiroCanonico(string $bytes, int $tamanho): ?string
    {
        $bytes = ltrim($bytes, "\0");
        if (\strlen($bytes) > $tamanho) {
            return null;
        }
        if ($bytes === '' || (\ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0" . $bytes;
        }

        return $bytes;
    }

    private static function tlv(int $etiqueta, string $valor): string
    {
        $tamanho = \strlen($valor);
        if ($tamanho < 0x80) {
            return \chr($etiqueta) . \chr($tamanho) . $valor;
        }
        $bytes = ltrim(pack('N', $tamanho), "\0");

        return \chr($etiqueta) . \chr(0x80 | \strlen($bytes)) . $bytes . $valor;
    }
}
