<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Porta do `cert` e do `nome` do protótipo: número de série, CN e O do emissor, CN do titular, validade e a chave
 * pública (SPKI). Acrescenta o identificador da chave (para achar o certificado pelo SubjectKeyIdentifier, M9) e o CPF
 * ou CNPJ da ICP-Brasil (M10): primeiro o subjectAltName, depois o sufixo do CN (`NOME:CPF`), como o protótipo lê.
 */
final class LeitorDeCertificado
{
    private const OID_NOME_COMUM = '550403';
    private const OID_ORGANIZACAO = '55040a';
    private const OID_IDENTIFICADOR_DA_CHAVE = '551d0e';
    private const OID_NOMES_ALTERNATIVOS = '551d11';
    /** 2.16.76.1.3.1: nascimento (8), CPF (11), NIS (11), RG (15) e órgão emissor do titular do e-CPF. */
    private const OID_DADOS_DO_TITULAR_ICP = '604c010301';
    /** 2.16.76.1.3.3: CNPJ do titular do e-CNPJ. */
    private const OID_CNPJ_ICP = '604c010303';

    /** @throws FormatoNaoReconhecido */
    public function ler(string $der, NoDer $certificado): CertificadoDoSignatario
    {
        $campos = LeitorDer::filhos($der, LeitorDer::primeiroFilho($der, $certificado));
        $o = LeitorDer::item($campos, 0)->etiqueta === 0xA0 ? 1 : 0;

        $emissor = LeitorDer::item($campos, $o + 2);
        $validade = LeitorDer::filhos($der, LeitorDer::item($campos, $o + 3));
        $nomeCompleto = $this->nome($der, LeitorDer::item($campos, $o + 4), self::OID_NOME_COMUM);
        [$identificador, $cpfNoOid, $cnpjNoOid] = $this->extensoes($der, \array_slice($campos, $o + 6));
        [$titular, $cpfNoNome, $cnpjNoNome] = self::partesDoNome($nomeCompleto);

        return new CertificadoDoSignatario(
            numeroDeSerie: LeitorDer::hex($der, LeitorDer::item($campos, $o)),
            emissor: $this->nome($der, $emissor, self::OID_NOME_COMUM),
            organizacaoDoEmissor: $this->nome($der, $emissor, self::OID_ORGANIZACAO),
            nomeCompleto: $nomeCompleto,
            titular: $titular,
            cpf: $cpfNoOid ?? $cpfNoNome,
            cnpj: $cnpjNoOid ?? $cnpjNoNome,
            validoDesde: LeitorDer::data($der, LeitorDer::item($validade, 0)),
            validoAte: LeitorDer::data($der, LeitorDer::item($validade, 1)),
            chavePublica: LeitorDer::bruto($der, LeitorDer::item($campos, $o + 5)),
            identificadorDaChave: $identificador,
            der: LeitorDer::bruto($der, $certificado),
        );
    }

    /**
     * Primeiro valor do atributo `$oid` num Name (RDNSequence). Null quando não há.
     *
     * @throws FormatoNaoReconhecido
     */
    private function nome(string $der, NoDer $nome, string $oid): ?string
    {
        foreach (LeitorDer::filhos($der, $nome) as $rdn) {
            foreach (LeitorDer::filhos($der, $rdn) as $atributo) {
                $partes = LeitorDer::filhos($der, $atributo);
                if (LeitorDer::hex($der, LeitorDer::item($partes, 0)) === $oid) {
                    $texto = LeitorDer::texto($der, LeitorDer::item($partes, 1));

                    return $texto === '' ? null : $texto;
                }
            }
        }

        return null;
    }

    /**
     * Identificador da chave, CPF e CNPJ das extensões. O protótipo não lia extensões: qualquer falha aqui só deixa
     * os três de fora, sem derrubar o certificado.
     *
     * @param list<NoDer> $resto os campos do TBSCertificate depois da chave pública
     *
     * @return array{?string, ?string, ?string}
     */
    private function extensoes(string $der, array $resto): array
    {
        $identificador = null;
        $cpf = null;
        $cnpj = null;
        try {
            foreach ($resto as $campo) {
                if ($campo->etiqueta !== 0xA3) {
                    continue;
                }
                foreach (LeitorDer::filhos($der, LeitorDer::primeiroFilho($der, $campo)) as $extensao) {
                    $partes = LeitorDer::filhos($der, $extensao);
                    $oid = LeitorDer::hex($der, LeitorDer::item($partes, 0));
                    $valor = LeitorDer::valor($der, LeitorDer::item($partes, \count($partes) - 1));
                    if ($oid === self::OID_IDENTIFICADOR_DA_CHAVE) {
                        $identificador = $this->identificador($valor);
                    } elseif ($oid === self::OID_NOMES_ALTERNATIVOS) {
                        [$cpf, $cnpj] = $this->documentosIcp($valor);
                    }
                }
            }
        } catch (FormatoNaoReconhecido) {
            // Extensão malformada: fica o que já foi lido.
        }

        return [$identificador, $cpf, $cnpj];
    }

    private function identificador(string $valor): ?string
    {
        try {
            $no = LeitorDer::abrir($valor);
        } catch (FormatoNaoReconhecido) {
            return null;
        }

        return $no->etiqueta === 0x04 ? LeitorDer::hex($valor, $no) : null;
    }

    /**
     * CPF (OID 2.16.76.1.3.1, posições 9 a 19) e CNPJ (2.16.76.1.3.3) dos otherName do subjectAltName.
     *
     * @return array{?string, ?string}
     *
     * @throws FormatoNaoReconhecido
     */
    private function documentosIcp(string $valor): array
    {
        $cpf = null;
        $cnpj = null;
        $nomes = LeitorDer::abrir($valor);
        foreach (LeitorDer::filhos($valor, $nomes) as $nome) {
            if ($nome->etiqueta !== 0xA0) {
                continue;
            }
            $partes = LeitorDer::filhos($valor, $nome);
            $tipo = LeitorDer::hex($valor, LeitorDer::item($partes, 0));
            $interno = LeitorDer::primeiroFilho($valor, LeitorDer::item($partes, 1));
            if ($interno->construido()) {
                continue;
            }
            $texto = LeitorDer::valor($valor, $interno);
            if ($tipo === self::OID_DADOS_DO_TITULAR_ICP) {
                $cpf = self::digitos(substr($texto, 8, 11), 11) ?? $cpf;
            } elseif ($tipo === self::OID_CNPJ_ICP) {
                $cnpj = self::digitos(trim($texto), 14) ?? $cnpj;
            }
        }

        return [$cpf, $cnpj];
    }

    /**
     * O CN da ICP-Brasil é `NOME:CPF` (e-CPF) ou `RAZÃO SOCIAL:CNPJ` (e-CNPJ). Titular é o que vem antes do `:`.
     *
     * @return array{?string, ?string, ?string}
     */
    private static function partesDoNome(?string $nomeCompleto): array
    {
        if ($nomeCompleto === null) {
            return [null, null, null];
        }

        $partes = explode(':', $nomeCompleto);
        $titular = $partes[0] !== '' ? $partes[0] : $nomeCompleto;
        $sufixo = $partes[1] ?? '';

        return [$titular, self::digitos($sufixo, 11), self::digitos($sufixo, 14)];
    }

    private static function digitos(string $texto, int $quantidade): ?string
    {
        if (\strlen($texto) !== $quantidade || !ctype_digit($texto) || trim($texto, '0') === '') {
            return null;
        }

        return $texto;
    }
}
