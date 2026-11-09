<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf;

/**
 * Monta, no próprio teste, PDFs assinados como os assinadores fazem: dicionário /Sig com `/ByteRange` calculado e
 * `/Contents` reservado, CMS destacado em DER e certificado autoassinado (com o CPF da ICP-Brasil no otherName, por um
 * arquivo de configuração temporário do OpenSSL). Chaves geradas uma vez por processo.
 */
final class OficinaDePdfAssinado
{
    /** CPF de teste com dígitos certos. */
    public const CPF = '52998224725';
    public const CPF_2 = '11144477735';
    public const CNPJ = '11222333000181';
    public const TITULAR = 'FULANA DE TAL';
    /** Bytes reservados para o CMS no /Contents (o dobro em hexadecimal). */
    public const RESERVA = 8192;
    public const DOCMDP = '/Reference[<</Type/SigRef/TransformMethod/DocMDP/TransformParams<</Type/TransformParams/P %d/V/1.2>>>>]';

    private const MARCADOR_DA_FAIXA = '/ByteRange [0 ********** ********** **********]';

    /** @var array<string, \OpenSSLAsymmetricKey> */
    private static array $chaves = [];

    private function __construct()
    {
    }

    /** `rsa`, `rsa2` (outra chave RSA), `ec` (P-256), `ec384` ou `k1` (secp256k1, fora da lista do protótipo). */
    public static function chave(string $nome = 'rsa'): \OpenSSLAsymmetricKey
    {
        if (!isset(self::$chaves[$nome])) {
            $opcoes = match ($nome) {
                'rsa', 'rsa2' => ['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048],
                'ec' => ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
                'ec384' => ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1'],
                'k1' => ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1'],
            };
            $chave = openssl_pkey_new($opcoes);
            if ($chave === false) {
                throw new \RuntimeException('Não gerou a chave ' . $nome);
            }
            self::$chaves[$nome] = $chave;
        }

        return self::$chaves[$nome];
    }

    /**
     * Certificado autoassinado. `$outroNome` é a linha `otherName` do subjectAltName (ex.: o CPF da ICP-Brasil).
     */
    public static function certificado(
        \OpenSSLAsymmetricKey $chave,
        string $nomeComum = self::TITULAR . ':' . self::CPF,
        ?string $outroNome = null,
        string $organizacao = 'ICP-Brasil',
    ): \OpenSSLCertificate {
        $configuracao = "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ ext ]\nbasicConstraints = CA:FALSE\n"
            . "subjectKeyIdentifier = hash\n" . ($outroNome !== null ? 'subjectAltName = ' . $outroNome . "\n" : '');
        $arquivo = self::temporario($configuracao);
        try {
            $opcoes = ['config' => $arquivo, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext'];
            $pedido = openssl_csr_new(['commonName' => $nomeComum, 'organizationName' => $organizacao], $chave, $opcoes);
            if (!$pedido instanceof \OpenSSLCertificateSigningRequest) {
                throw new \RuntimeException('Não gerou o pedido: ' . openssl_error_string());
            }
            $certificado = openssl_csr_sign($pedido, null, $chave, 365, $opcoes, random_int(1, \PHP_INT_MAX));
            if ($certificado === false) {
                throw new \RuntimeException('Não assinou o certificado: ' . openssl_error_string());
            }

            return $certificado;
        } finally {
            unlink($arquivo);
            while (openssl_error_string() !== false) {
            }
        }
    }

    /** otherName 2.16.76.1.3.1 da ICP-Brasil: nascimento, CPF, NIS, RG e órgão emissor. */
    public static function outroNomeComCpf(string $cpf = self::CPF): string
    {
        return 'otherName:2.16.76.1.3.1;OCT:01011980' . $cpf . '00000000000' . '000000000000000' . 'SSPDF0';
    }

    public static function outroNomeComCnpj(string $cnpj = self::CNPJ): string
    {
        return 'otherName:2.16.76.1.3.3;OCT:' . $cnpj;
    }

    public static function der(\OpenSSLCertificate $certificado): string
    {
        openssl_x509_export($certificado, $pem);

        return (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) $pem) ?? '', true);
    }

    /**
     * Assinador com `openssl_cms_sign` (CMS destacado, DER, atributos assinados com signingTime e messageDigest).
     *
     * @return callable(string): string
     */
    public static function cmsDoOpenssl(\OpenSSLAsymmetricKey $chave, ?\OpenSSLCertificate $certificado = null): callable
    {
        $certificado ??= self::certificado($chave);

        return static function (string $conteudo) use ($chave, $certificado): string {
            $entrada = self::temporario($conteudo);
            $saida = self::temporario('');
            try {
                $ok = openssl_cms_sign($entrada, $saida, $certificado, $chave, null, \OPENSSL_CMS_DETACHED | \OPENSSL_CMS_BINARY, \OPENSSL_ENCODING_DER);
                if (!$ok) {
                    throw new \RuntimeException('openssl_cms_sign falhou: ' . openssl_error_string());
                }

                return (string) file_get_contents($saida);
            } finally {
                unlink($entrada);
                unlink($saida);
            }
        };
    }

    /**
     * Assinador com CMS montado à mão (RSA): PSS ou PKCS#1, com ou sem atributos assinados, resumo à escolha,
     * identificação por número de série ou pela chave e certificados extras antes do signatário.
     *
     * @param list<\OpenSSLCertificate> $certificadosAntes
     *
     * @return callable(string): string
     */
    public static function cmsManual(
        \OpenSSLAsymmetricKey $chave,
        \OpenSSLCertificate $certificado,
        bool $pss = false,
        bool $atributos = true,
        string $oidDoResumo = MontadorDeDer::OID_SHA256,
        bool $pelaChave = false,
        array $certificadosAntes = [],
    ): callable {
        return static function (string $conteudo) use ($chave, $certificado, $pss, $atributos, $oidDoResumo, $pelaChave, $certificadosAntes): string {
            $d = MontadorDeDer::class;
            $doCertificado = self::der($certificado);
            $campos = $d::partes($d::partes($doCertificado)[0]);
            $o = \ord($campos[0][0]) === 0xA0 ? 1 : 0;

            $listaDeAtributos = $d::sequencia($d::oid($d::OID_TIPO_DE_CONTEUDO), $d::conjunto($d::oid($d::OID_DADOS)))
                . $d::sequencia($d::oid($d::OID_HORA_DA_ASSINATURA), $d::conjunto($d::tlv(0x17, gmdate('ymdHis') . 'Z')))
                . $d::sequencia($d::oid($d::OID_RESUMO_DA_MENSAGEM), $d::conjunto($d::octetos(hash('sha256', $conteudo, true))));
            $alvo = $atributos ? $d::tlv(0x31, $listaDeAtributos) : $conteudo;

            if ($pss) {
                $assinatura = self::assinarPss($alvo, $chave);
                $algoritmo = $d::sequencia(
                    $d::oid($d::OID_RSA_PSS),
                    $d::sequencia(
                        $d::contexto(0, $d::algoritmo($d::OID_SHA256)),
                        $d::contexto(1, $d::sequencia($d::oid($d::OID_MGF1), $d::algoritmo($d::OID_SHA256))),
                        $d::contexto(2, $d::inteiro(32)),
                    ),
                );
            } else {
                openssl_sign($alvo, $assinatura, $chave, \OPENSSL_ALGO_SHA256);
                $algoritmo = $d::algoritmo($d::OID_RSA);
            }

            $identificacao = $pelaChave
                ? $d::tlv(0x80, (string) hex2bin(str_replace(':', '', (string) (openssl_x509_parse($certificado)['extensions']['subjectKeyIdentifier'] ?? ''))))
                : $d::sequencia($campos[$o + 2], $campos[$o]);
            $signatario = $d::sequencia(
                $d::inteiro($pelaChave ? 3 : 1),
                $identificacao,
                $d::algoritmo($oidDoResumo),
                $atributos ? $d::contexto(0, $listaDeAtributos) : '',
                $algoritmo,
                $d::octetos((string) $assinatura),
            );
            $certificados = implode('', array_map(self::der(...), $certificadosAntes)) . $doCertificado;
            $dadosAssinados = $d::sequencia(
                $d::inteiro(1),
                $d::conjunto($d::algoritmo($oidDoResumo)),
                $d::sequencia($d::oid($d::OID_DADOS)),
                $d::contexto(0, $certificados),
                $d::conjunto($signatario),
            );

            return $d::sequencia($d::oid($d::OID_DADOS_ASSINADOS), $d::contexto(0, $dadosAssinados));
        };
    }

    /** EMSA-PSS-ENCODE (RFC 8017) com SHA-256, MGF1 e sal de 32 bytes, assinado com RSA sem padding. */
    public static function assinarPss(string $mensagem, \OpenSSLAsymmetricKey $chave): string
    {
        $bits = (int) (openssl_pkey_get_details($chave) ?: [])['bits'];
        $bitsDoEm = $bits - 1;
        $tamanhoDoEm = intdiv($bitsDoEm + 7, 8);
        $sal = random_bytes(32);
        $h = hash('sha256', str_repeat("\0", 8) . hash('sha256', $mensagem, true) . $sal, true);
        $db = str_repeat("\0", $tamanhoDoEm - 32 - 32 - 2) . "\x01" . $sal;
        $mascara = '';
        for ($contador = 0; \strlen($mascara) < \strlen($db); ++$contador) {
            $mascara .= hash('sha256', $h . pack('N', $contador), true);
        }
        $mascarado = $db ^ substr($mascara, 0, \strlen($db));
        $mascarado[0] = \chr(\ord($mascarado[0]) & (0xFF >> (8 * $tamanhoDoEm - $bitsDoEm)));
        $em = str_pad($mascarado . $h . "\xBC", intdiv($bits + 7, 8), "\0", \STR_PAD_LEFT);
        openssl_private_encrypt($em, $assinatura, $chave, \OPENSSL_NO_PADDING);

        return (string) $assinatura;
    }

    public static function pdfSemAssinatura(string $trailerExtra = ''): string
    {
        return self::montar([
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>',
        ], $trailerExtra);
    }

    /** PDF de uma página com o campo de assinatura e o /Sig com a faixa e o /Contents reservados. */
    public static function pdfParaAssinar(string $extraNoDicionario = '', int $reserva = self::RESERVA, string $subFiltro = 'ETSI.CAdES.detached'): string
    {
        return self::montar([
            1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R]/SigFlags 3>>>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[4 0 R]>>',
            4 => '<</Type/Annot/Subtype/Widget/FT/Sig/T(Assinatura1)/V 5 0 R/Rect[0 0 0 0]/F 132/P 3 0 R>>',
            5 => self::dicionarioDaAssinatura($extraNoDicionario, $reserva, $subFiltro),
        ]);
    }

    /** Acréscimo incremental (como o assinador faz na segunda assinatura) com o novo /Sig reservado. */
    public static function acrescimoParaAssinar(string $anterior, string $extraNoDicionario = '', string $subFiltro = 'ETSI.CAdES.detached'): string
    {
        preg_match_all('/startxref\s+(\d+)/', $anterior, $partes);
        $anteriorXref = (int) end($partes[1]);
        $objetos = [
            6 => '<</Type/Annot/Subtype/Widget/FT/Sig/T(Assinatura2)/V 7 0 R/Rect[0 0 0 0]/F 132/P 3 0 R>>',
            1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 6 0 R]/SigFlags 3>>>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[4 0 R 6 0 R]>>',
            7 => self::dicionarioDaAssinatura($extraNoDicionario, self::RESERVA, $subFiltro),
        ];

        $pdf = $anterior;
        $posicoes = [];
        foreach ($objetos as $numero => $corpo) {
            $posicoes[$numero] = \strlen($pdf);
            $pdf .= $numero . " 0 obj\n" . $corpo . "\nendobj\n";
        }
        ksort($posicoes);
        $xref = \strlen($pdf);
        $pdf .= "xref\n";
        foreach ($posicoes as $numero => $posicao) {
            $pdf .= $numero . " 1\n" . \sprintf('%010d 00000 n ', $posicao) . "\n";
        }

        return $pdf . "trailer\n<</Size 8/Root 1 0 R/Prev " . $anteriorXref . ">>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /**
     * Preenche o último /Sig reservado: calcula a faixa, assina o que ela cobre e grava o hexadecimal no /Contents.
     *
     * @param callable(string): string $assinador recebe os bytes cobertos e devolve o CMS em DER
     */
    public static function assinar(string $pdf, callable $assinador): string
    {
        [$pdf, $l, $c] = self::preencherFaixa($pdf);
        $der = $assinador(substr($pdf, 0, $l) . substr($pdf, $c));
        $reserva = $c - $l - 2;
        if (2 * \strlen($der) > $reserva) {
            throw new \RuntimeException('CMS maior que a reserva.');
        }

        return substr_replace($pdf, str_pad(bin2hex($der), $reserva, '0'), $l + 1, $reserva);
    }

    /**
     * Só calcula e grava a faixa (sem assinar). Devolve o PDF, `l` e `c`.
     *
     * @return array{string, int, int}
     */
    public static function preencherFaixa(string $pdf): array
    {
        $marcador = strrpos($pdf, self::MARCADOR_DA_FAIXA);
        if ($marcador === false) {
            throw new \RuntimeException('Sem /Sig reservado.');
        }
        $l = (int) strpos($pdf, '/Contents <', $marcador) + 10;
        $c = (int) strpos($pdf, '>', $l) + 1;
        $faixa = str_pad('0 ' . $l . ' ' . $c . ' ' . (\strlen($pdf) - $c), \strlen(self::MARCADOR_DA_FAIXA) - 13);

        return [substr_replace($pdf, '/ByteRange [' . $faixa . ']', $marcador, \strlen(self::MARCADOR_DA_FAIXA)), $l, $c];
    }

    /** Troca a faixa reservada pelo texto dado (para os casos de faixa inválida), sem assinar. */
    public static function comFaixa(string $pdf, string $numeros): string
    {
        $marcador = (int) strrpos($pdf, self::MARCADOR_DA_FAIXA);

        return substr_replace($pdf, '/ByteRange [' . str_pad($numeros, \strlen(self::MARCADOR_DA_FAIXA) - 13) . ']', $marcador, \strlen(self::MARCADOR_DA_FAIXA));
    }

    /** O PDF assinado de ponta a ponta. */
    public static function pdfAssinado(string $chave = 'rsa', string $extraNoDicionario = '', ?\OpenSSLCertificate $certificado = null): string
    {
        return self::assinar(self::pdfParaAssinar($extraNoDicionario), self::cmsDoOpenssl(self::chave($chave), $certificado));
    }

    /**
     * Mexe no CMS gravado no /Contents da última assinatura (sem mudar a faixa).
     *
     * @param callable(string): string $alteracao recebe e devolve o DER (mesmo tamanho)
     */
    public static function alterarCms(string $pdf, callable $alteracao): string
    {
        preg_match_all('/\/ByteRange \[0 (\d+) (\d+)/', $pdf, $partes);
        $l = (int) end($partes[1]);
        $c = (int) end($partes[2]);
        $hex = substr($pdf, $l + 1, $c - $l - 2);
        $der = (string) hex2bin($hex);
        $tamanho = MontadorDeDer::tamanhoTotal($der);
        $novo = $alteracao(substr($der, 0, $tamanho));

        return substr_replace($pdf, bin2hex($novo), $l + 1, 2 * \strlen($novo));
    }

    private static function dicionarioDaAssinatura(string $extra, int $reserva, string $subFiltro): string
    {
        return '<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/' . $subFiltro . "/M(D:20261109120000-03'00')/Name(Teste)" . $extra
            . self::MARCADOR_DA_FAIXA . '/Contents <' . str_repeat('0', 2 * $reserva) . '>>>';
    }

    /** @param array<int, string> $objetos */
    private static function montar(array $objetos, string $trailerExtra = ''): string
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $posicoes = [];
        foreach ($objetos as $numero => $corpo) {
            $posicoes[$numero] = \strlen($pdf);
            $pdf .= $numero . " 0 obj\n" . $corpo . "\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 " . (\count($objetos) + 1) . "\n0000000000 65535 f \n";
        foreach ($posicoes as $posicao) {
            $pdf .= \sprintf('%010d 00000 n ', $posicao) . "\n";
        }

        return $pdf . "trailer\n<</Size " . (\count($objetos) + 1) . '/Root 1 0 R' . $trailerExtra . ">>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private static function temporario(string $conteudo): string
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'conferencia-pdf-');
        if ($arquivo === false) {
            throw new \RuntimeException('Sem arquivo temporário.');
        }
        file_put_contents($arquivo, $conteudo);

        return $arquivo;
    }
}
