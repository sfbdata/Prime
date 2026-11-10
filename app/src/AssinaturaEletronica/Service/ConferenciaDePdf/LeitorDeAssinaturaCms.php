<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Porta da parte CMS do `umaAss` e do `confere` do protótipo: abre o SignedData destacado, lê os certificados, pega o
 * primeiro SignerInfo, confere o messageDigest dos atributos assinados contra o hash da faixa de bytes e a assinatura
 * com a chave do certificado. Estrutura fora do esperado lança `FormatoNaoReconhecido` (ou `LimiteExcedido`).
 */
final class LeitorDeAssinaturaCms
{
    private const OID_DADOS_ASSINADOS = '2a864886f70d010702';
    private const OID_RESUMO_DA_MENSAGEM = '2a864886f70d010904';
    private const OID_HORA_DA_ASSINATURA = '2a864886f70d010905';
    /** signingCertificateV2 (1.2.840.113549.1.9.16.2.47) e v1 (1.2.840.113549.1.9.16.2.12): o hash do certificado. */
    private const OID_VINCULO_V2 = '2a864886f70d010910022f';
    private const OID_VINCULO_V1 = '2a864886f70d010910020c';
    /** adbe-revocationInfoArchival (1.2.840.113583.1.1.8): LCRs e OCSPs guardados pelo Acrobat, que o motor não lê. */
    private const OID_ARQUIVO_DE_REVOGACAO = '2a864886f72f010108';
    /** OIDs de resumo do protótipo (`DIG`). */
    private const RESUMOS = [
        '608648016503040201' => 'SHA-256',
        '608648016503040202' => 'SHA-384',
        '608648016503040203' => 'SHA-512',
        '2b0e03021a' => 'SHA-1',
    ];
    private const HASHES = [
        'SHA-256' => 'sha256',
        'SHA-384' => 'sha384',
        'SHA-512' => 'sha512',
        'SHA-1' => 'sha1',
    ];

    public const MOTIVO_RESUMO = 'Algoritmo de resumo não suportado';
    public const MOTIVO_SEM_CERTIFICADO = 'O certificado do signatário não está no arquivo';
    public const MOTIVO_SEM_ATRIBUTOS = 'Assinatura sem atributos assinados: o resumo não foi conferido';

    public function __construct(
        private readonly LeitorDeCertificado $leitorDeCertificado = new LeitorDeCertificado(),
        private readonly VerificadorDeChave $verificadorDeChave = new VerificadorDeChave(),
    ) {
    }

    /**
     * @param string $der o CMS (bytes do hexadecimal do /Contents)
     * @param string $pdf o arquivo inteiro; a faixa assinada é `[0, $tamanho1)` e `[$inicio2, $inicio2 + $tamanho2)`
     *
     * @throws FormatoNaoReconhecido
     */
    public function conferir(string $der, string $pdf, int $tamanho1, int $inicio2, int $tamanho2): ConferenciaDoCms
    {
        $informacao = LeitorDer::filhos($der, LeitorDer::abrir($der, self::opacos($der)));
        if (LeitorDer::hex($der, LeitorDer::item($informacao, 0)) !== self::OID_DADOS_ASSINADOS) {
            throw new FormatoNaoReconhecido('O CMS não é SignedData.');
        }

        $dados = LeitorDer::filhos($der, LeitorDer::primeiroFilho($der, LeitorDer::item($informacao, 1)));
        $i = 3;
        $certificados = [];
        if (LeitorDer::item($dados, $i)->etiqueta === 0xA0) {
            foreach (LeitorDer::filhos($der, LeitorDer::item($dados, $i)) as $no) {
                if ($no->etiqueta !== 0x30) {
                    continue;
                }
                if (\count($certificados) >= LimitesDaConferencia::CERTIFICADOS_MAXIMOS) {
                    throw new LimiteExcedido('Certificados demais no CMS.');
                }
                $certificados[] = $this->leitorDeCertificado->ler($der, $no);
            }
            ++$i;
        }
        if (LeitorDer::item($dados, $i)->etiqueta === 0xA1) {
            ++$i;
        }
        $signatario = LeitorDer::primeiroFilho($der, LeitorDer::item($dados, $i));

        return $this->conferirSignatario($der, $signatario, $certificados, $pdf, $tamanho1, $inicio2, $tamanho2);
    }

    /**
     * @param list<CertificadoDoSignatario> $certificados
     *
     * @throws FormatoNaoReconhecido
     */
    private function conferirSignatario(string $der, NoDer $signatario, array $certificados, string $pdf, int $tamanho1, int $inicio2, int $tamanho2): ConferenciaDoCms
    {
        [$identificacao, $oidDoResumo, $atributos, $oidDaAssinatura, $assinatura] = self::camposDoSignatario($der, $signatario);

        $algoritmo = self::RESUMOS[$oidDoResumo] ?? null;
        if ($algoritmo === null) {
            return new ConferenciaDoCms(null, null, null, null, null, self::MOTIVO_RESUMO, VinculoDoCertificado::NaoConferido);
        }

        $certificado = $this->certificadoDoSignatario($der, $identificacao, $certificados);
        if ($certificado === null) {
            return new ConferenciaDoCms($algoritmo, null, null, null, null, self::MOTIVO_SEM_CERTIFICADO, VinculoDoCertificado::NaoConferido);
        }

        $resumoDaFaixa = self::resumoDaFaixa(self::HASHES[$algoritmo], $pdf, $tamanho1, $inicio2, $tamanho2);

        $resumoConfere = null;
        $hora = null;
        $vinculo = VinculoDoCertificado::NaoVinculado;
        if ($atributos !== null) {
            [$resumoDeclarado, $hora, $vinculos] = self::atributosAssinados($der, $atributos);
            $vinculo = self::vinculo($der, $vinculos, $certificado);
            $resumoConfere = $resumoDeclarado !== null && hash_equals($resumoDaFaixa, $resumoDeclarado);
            // A assinatura cobre os atributos com a etiqueta de SET OF (0x31), não a [0] IMPLICIT do SignerInfo.
            $alvo = "\x31" . substr(LeitorDer::bruto($der, $atributos), 1);
        } else {
            $alvo = substr($pdf, 0, $tamanho1) . substr($pdf, $inicio2, $tamanho2);
        }

        $chave = $this->verificadorDeChave->verificar($oidDaAssinatura, $algoritmo, $alvo, $assinatura, $certificado->chavePublica);
        $motivo = self::motivo($chave, $resumoConfere);
        // O atributo só prova o vínculo (ou a troca) quando a assinatura sobre os atributos confere.
        if ($chave->confere !== true && $vinculo !== VinculoDoCertificado::NaoVinculado) {
            $vinculo = VinculoDoCertificado::NaoConferido;
        }

        return new ConferenciaDoCms($algoritmo, $resumoConfere, $chave->confere, $certificado, $hora, $motivo, $vinculo);
    }

    /**
     * A identificação do signatário, o OID do resumo, os atributos assinados (se houver), o OID e o valor da assinatura.
     *
     * @return array{NoDer, string, ?NoDer, string, string}
     *
     * @throws FormatoNaoReconhecido
     */
    private static function camposDoSignatario(string $der, NoDer $signatario): array
    {
        $campos = LeitorDer::filhos($der, $signatario);
        $i = 1;
        $identificacao = LeitorDer::item($campos, $i++);
        $oidDoResumo = LeitorDer::hex($der, LeitorDer::primeiroFilho($der, LeitorDer::item($campos, $i++)));
        $atributos = null;
        if (LeitorDer::item($campos, $i)->etiqueta === 0xA0) {
            $atributos = LeitorDer::item($campos, $i++);
        }
        $oidDaAssinatura = LeitorDer::hex($der, LeitorDer::primeiroFilho($der, LeitorDer::item($campos, $i++)));
        $assinatura = LeitorDer::valor($der, LeitorDer::item($campos, $i));

        return [$identificacao, $oidDoResumo, $atributos, $oidDaAssinatura, $assinatura];
    }

    private static function motivo(ResultadoDaChave $chave, ?bool $resumoConfere): ?string
    {
        return match (true) {
            $chave->confere === null => $chave->motivo ?? VerificadorDeChave::MOTIVO_CHAVE,
            $resumoConfere === null => self::MOTIVO_SEM_ATRIBUTOS,
            default => null,
        };
    }

    private static function resumoDaFaixa(string $hash, string $pdf, int $tamanho1, int $inicio2, int $tamanho2): string
    {
        $contexto = hash_init($hash);
        hash_update($contexto, substr($pdf, 0, $tamanho1));
        hash_update($contexto, substr($pdf, $inicio2, $tamanho2));

        return hash_final($contexto, true);
    }

    /**
     * O messageDigest, o signingTime e os atributos de vínculo (se é v2 e o SigningCertificate de cada um).
     *
     * @return array{?string, ?\DateTimeImmutable, list<array{bool, NoDer}>}
     *
     * @throws FormatoNaoReconhecido
     */
    private static function atributosAssinados(string $der, NoDer $atributos): array
    {
        $resumoDeclarado = null;
        $hora = null;
        $vinculos = [];
        foreach (LeitorDer::filhos($der, $atributos) as $atributo) {
            $partes = LeitorDer::filhos($der, $atributo);
            $oid = LeitorDer::hex($der, LeitorDer::item($partes, 0));
            if ($oid === self::OID_RESUMO_DA_MENSAGEM) {
                $resumoDeclarado = LeitorDer::valor($der, LeitorDer::primeiroFilho($der, LeitorDer::item($partes, 1)));
            } elseif ($oid === self::OID_HORA_DA_ASSINATURA) {
                $hora = LeitorDer::data($der, LeitorDer::primeiroFilho($der, LeitorDer::item($partes, 1)));
            } elseif ($oid === self::OID_VINCULO_V2 || $oid === self::OID_VINCULO_V1) {
                $vinculos[] = [$oid === self::OID_VINCULO_V2, LeitorDer::primeiroFilho($der, LeitorDer::item($partes, 1))];
            }
        }

        return [$resumoDeclarado, $hora, $vinculos];
    }

    /**
     * O signingCertificateV2 e o signingCertificate v1 contra o hash do certificado usado (M24). Vale o primeiro
     * ESSCertID de cada atributo, que pela RFC 5035 é o do signatário. Hash diferente em qualquer um: `Diferente`.
     *
     * @param list<array{bool, NoDer}> $vinculos se é v2 e o SigningCertificate(V2) de cada atributo
     *
     * @throws FormatoNaoReconhecido
     */
    private static function vinculo(string $der, array $vinculos, CertificadoDoSignatario $certificado): VinculoDoCertificado
    {
        if ($vinculos === []) {
            return VinculoDoCertificado::NaoVinculado;
        }

        $resultado = VinculoDoCertificado::Vinculado;
        foreach ($vinculos as [$v2, $assinado]) {
            $identificacao = LeitorDer::filhos($der, LeitorDer::primeiroFilho($der, LeitorDer::primeiroFilho($der, $assinado)));
            $hash = 'sha1';
            $i = 0;
            if ($v2) {
                // ESSCertIDv2: o AlgorithmIdentifier é opcional, e o padrão é SHA-256.
                $hash = 'sha256';
                if (LeitorDer::item($identificacao, 0)->etiqueta === 0x30) {
                    $oid = LeitorDer::hex($der, LeitorDer::primeiroFilho($der, LeitorDer::item($identificacao, 0)));
                    $hash = self::HASHES[self::RESUMOS[$oid] ?? ''] ?? null;
                    $i = 1;
                }
            }
            $declarado = LeitorDer::item($identificacao, $i);
            if ($declarado->etiqueta !== 0x04) {
                throw new FormatoNaoReconhecido('Vínculo do certificado sem o hash.');
            }
            if ($hash === null) {
                $resultado = VinculoDoCertificado::NaoConferido;
                continue;
            }
            if (!hash_equals(hash($hash, $certificado->der, true), LeitorDer::valor($der, $declarado))) {
                return VinculoDoCertificado::Diferente;
            }
        }

        return $resultado;
    }

    /**
     * Posições dos containers que o motor não lê (M23): as LCRs do SignedData (crls, [1]), os SignerInfos depois do
     * primeiro, o valor do atributo adbe-revocationInfoArchival e os atributos não assinados ([1] do SignerInfo). Num
     * PDF com validação de longo prazo eles passam fácil de 50.000 elementos; a validação só confere que cabem no pai.
     * Estrutura fora do esperado devolve o que achou até ali: a validação completa e a leitura dão o motivo depois.
     *
     * @return array<int, true>
     */
    private static function opacos(string $der): array
    {
        $opacos = [];
        try {
            $informacao = LeitorDer::filhos($der, LeitorDer::ler($der, 0, \strlen($der)));
            $dados = LeitorDer::filhos($der, LeitorDer::primeiroFilho($der, LeitorDer::item($informacao, 1)));
            foreach ($dados as $no) {
                if ($no->etiqueta === 0xA1) {
                    $opacos[$no->inicio] = true;
                }
            }
            $signatarios = LeitorDer::filhos($der, LeitorDer::item($dados, \count($dados) - 1));
            foreach (\array_slice($signatarios, 1) as $outro) {
                $opacos[$outro->inicio] = true;
            }
            foreach (LeitorDer::filhos($der, LeitorDer::item($signatarios, 0)) as $campo) {
                if ($campo->etiqueta === 0xA1) {
                    $opacos[$campo->inicio] = true;
                    continue;
                }
                if ($campo->etiqueta !== 0xA0) {
                    continue;
                }
                foreach (LeitorDer::filhos($der, $campo) as $atributo) {
                    $partes = LeitorDer::filhos($der, $atributo);
                    if (LeitorDer::hex($der, LeitorDer::item($partes, 0)) === self::OID_ARQUIVO_DE_REVOGACAO) {
                        $opacos[LeitorDer::item($partes, 1)->inicio] = true;
                    }
                }
            }
        } catch (FormatoNaoReconhecido) {
            // Fica com o que achou; a validação completa diz o que está errado.
        }

        return $opacos;
    }

    /**
     * O certificado pelo número de série (IssuerAndSerialNumber, como o protótipo) ou pelo identificador da chave
     * (SubjectKeyIdentifier, M9). Sem par, o primeiro do CMS, como o protótipo.
     *
     * @param list<CertificadoDoSignatario> $certificados
     *
     * @throws FormatoNaoReconhecido
     */
    private function certificadoDoSignatario(string $der, NoDer $identificacao, array $certificados): ?CertificadoDoSignatario
    {
        $serie = null;
        $identificador = null;
        if ($identificacao->etiqueta === 0x30) {
            $serie = LeitorDer::hex($der, LeitorDer::item(LeitorDer::filhos($der, $identificacao), 1));
        } elseif ($identificacao->etiqueta === 0x80) {
            $identificador = LeitorDer::hex($der, $identificacao);
        }

        foreach ($certificados as $certificado) {
            if (($serie !== null && $certificado->numeroDeSerie === $serie)
                || ($identificador !== null && $certificado->identificadorDaChave === $identificador)) {
                return $certificado;
            }
        }

        return $certificados[0] ?? null;
    }
}
