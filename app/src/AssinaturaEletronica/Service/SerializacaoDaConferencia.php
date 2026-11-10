<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\AssinaturaConferida;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoCpf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VinculoDoCertificado;

/**
 * O resultado do motor de conferência ({@see ResultadoDaConferencia}) em JSON, para a coluna `analise` da versão do PDF
 * (contrato da etapa 5, seção 2, item 6), e de volta. Fica fora do motor: o motor não sabe que é gravado.
 *
 * O JSON leva `"v": 1` e todos os campos do resultado e de cada assinatura, com os nomes em `snake_case`, os enums pelo
 * valor e as datas em ISO 8601 no UTC (o motor só lê segundos). Titular, CPF, CNPJ e emissor continuam sendo o que o
 * certificado DECLARA (M25): gravar não os torna conferidos.
 *
 * A volta é estrita: versão desconhecida, campo ausente ou de outro tipo lançam `\UnexpectedValueException`.
 */
final class SerializacaoDaConferencia
{
    public const VERSAO = 1;

    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function paraJson(ResultadoDaConferencia $resultado): array
    {
        return [
            'v' => self::VERSAO,
            'situacao' => $resultado->situacao->value,
            'motivo' => $resultado->motivo,
            'tamanho' => $resultado->tamanho,
            'doc_mdp' => $resultado->docMdp,
            'alterado_depois_da_certificacao' => $resultado->alteradoDepoisDaCertificacao,
            'cifrado' => $resultado->cifrado,
            'permite_carimbo' => $resultado->permiteCarimbo,
            'prefixo' => $resultado->prefixo->value,
            'assinaturas_novas' => $resultado->assinaturasNovas,
            'cpf' => $resultado->cpf->value,
            'assinaturas' => array_map(self::assinaturaParaJson(...), $resultado->assinaturas),
        ];
    }

    /**
     * @param array<mixed> $json
     *
     * @throws \UnexpectedValueException versão desconhecida ou campo fora do formato
     */
    public static function deJson(array $json): ResultadoDaConferencia
    {
        if (LeitorDeJsonEstrito::inteiro($json, 'v') !== self::VERSAO) {
            throw new \UnexpectedValueException('Versão desconhecida do JSON da análise do PDF.');
        }

        return new ResultadoDaConferencia(
            situacao: LeitorDeJsonEstrito::enum($json, 'situacao', SituacaoDaConferencia::class),
            motivo: LeitorDeJsonEstrito::texto($json, 'motivo'),
            tamanho: LeitorDeJsonEstrito::inteiro($json, 'tamanho'),
            assinaturas: array_map(self::assinaturaDeJson(...), self::objetos($json, 'assinaturas')),
            docMdp: LeitorDeJsonEstrito::inteiro($json, 'doc_mdp'),
            alteradoDepoisDaCertificacao: LeitorDeJsonEstrito::booleano($json, 'alterado_depois_da_certificacao'),
            cifrado: LeitorDeJsonEstrito::booleano($json, 'cifrado'),
            permiteCarimbo: LeitorDeJsonEstrito::booleano($json, 'permite_carimbo'),
            prefixo: LeitorDeJsonEstrito::enum($json, 'prefixo', ConferenciaDoPrefixo::class),
            assinaturasNovas: LeitorDeJsonEstrito::inteiro($json, 'assinaturas_novas'),
            cpf: LeitorDeJsonEstrito::enum($json, 'cpf', ConferenciaDoCpf::class),
        );
    }

    /** @return array<string, mixed> */
    private static function assinaturaParaJson(AssinaturaConferida $a): array
    {
        return [
            'ordem' => $a->ordem,
            'faixa' => $a->faixaDeBytes,
            'faixa_valida' => $a->faixaValida,
            'fim' => $a->fim,
            'cobre_o_documento' => $a->cobreODocumento,
            'bytes_depois' => $a->bytesDepois,
            'tipo' => $a->tipo,
            'algoritmo_do_resumo' => $a->algoritmoDoResumo,
            'resumo_confere' => $a->resumoConfere,
            'chave_confere' => $a->chaveConfere,
            'titular' => $a->titular,
            'cpf' => $a->cpf,
            'cnpj' => $a->cnpj,
            'documento_mascarado' => $a->documentoMascarado,
            'emissor' => $a->emissor,
            'emissor_declara_icp_brasil' => $a->emissorDeclaraIcpBrasil,
            'numero_de_serie' => $a->numeroDeSerie,
            'valido_desde' => self::data($a->validoDesde),
            'valido_ate' => self::data($a->validoAte),
            'hora_declarada' => self::data($a->horaDeclarada),
            'doc_mdp' => $a->docMdp,
            'veredito' => $a->veredito->value,
            'motivo' => $a->motivo,
            'vinculo_do_certificado' => $a->vinculoDoCertificado->value,
            'acrescimo' => $a->acrescimo->value,
            'motivo_do_acrescimo' => $a->motivoDoAcrescimo,
        ];
    }

    /** @param array<mixed> $j */
    private static function assinaturaDeJson(array $j): AssinaturaConferida
    {
        return new AssinaturaConferida(
            ordem: LeitorDeJsonEstrito::inteiro($j, 'ordem'),
            faixaDeBytes: LeitorDeJsonEstrito::listaDeInteiros($j, 'faixa'),
            faixaValida: LeitorDeJsonEstrito::booleano($j, 'faixa_valida'),
            fim: LeitorDeJsonEstrito::inteiro($j, 'fim'),
            cobreODocumento: LeitorDeJsonEstrito::booleano($j, 'cobre_o_documento'),
            bytesDepois: LeitorDeJsonEstrito::inteiro($j, 'bytes_depois'),
            tipo: LeitorDeJsonEstrito::texto($j, 'tipo'),
            algoritmoDoResumo: LeitorDeJsonEstrito::textoOuNulo($j, 'algoritmo_do_resumo'),
            resumoConfere: LeitorDeJsonEstrito::booleanoOuNulo($j, 'resumo_confere'),
            chaveConfere: LeitorDeJsonEstrito::booleanoOuNulo($j, 'chave_confere'),
            titular: LeitorDeJsonEstrito::textoOuNulo($j, 'titular'),
            cpf: LeitorDeJsonEstrito::textoOuNulo($j, 'cpf'),
            cnpj: LeitorDeJsonEstrito::textoOuNulo($j, 'cnpj'),
            documentoMascarado: LeitorDeJsonEstrito::textoOuNulo($j, 'documento_mascarado'),
            emissor: LeitorDeJsonEstrito::textoOuNulo($j, 'emissor'),
            emissorDeclaraIcpBrasil: LeitorDeJsonEstrito::booleano($j, 'emissor_declara_icp_brasil'),
            numeroDeSerie: LeitorDeJsonEstrito::textoOuNulo($j, 'numero_de_serie'),
            validoDesde: LeitorDeJsonEstrito::dataOuNula($j, 'valido_desde'),
            validoAte: LeitorDeJsonEstrito::dataOuNula($j, 'valido_ate'),
            horaDeclarada: LeitorDeJsonEstrito::dataOuNula($j, 'hora_declarada'),
            docMdp: LeitorDeJsonEstrito::inteiroOuNulo($j, 'doc_mdp'),
            veredito: LeitorDeJsonEstrito::enum($j, 'veredito', VereditoDaAssinatura::class),
            motivo: LeitorDeJsonEstrito::texto($j, 'motivo'),
            vinculoDoCertificado: LeitorDeJsonEstrito::enum($j, 'vinculo_do_certificado', VinculoDoCertificado::class),
            acrescimo: LeitorDeJsonEstrito::enum($j, 'acrescimo', ConferenciaDoAcrescimo::class),
            motivoDoAcrescimo: LeitorDeJsonEstrito::textoOuNulo($j, 'motivo_do_acrescimo'),
        );
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<array<mixed>>
     */
    private static function objetos(array $json, string $campo): array
    {
        return array_map(
            static fn (mixed $item): array => \is_array($item) ? $item : throw new \UnexpectedValueException(sprintf('Item de "%s" que não é objeto.', $campo)),
            LeitorDeJsonEstrito::lista($json, $campo),
        );
    }

    private static function data(?\DateTimeImmutable $data): ?string
    {
        return $data?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
