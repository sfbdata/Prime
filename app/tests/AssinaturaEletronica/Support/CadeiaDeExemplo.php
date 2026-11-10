<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\AssinaturaConferida;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoCpf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;

/**
 * Respostas de exemplo do validador de cadeia (protocolo `v1`, etapa 6, seção 2) e resultados do motor para os testes
 * da cadeia (bloco 6B).
 */
final class CadeiaDeExemplo
{
    public const FAIXA = [0, 840, 960, 1200];
    public const FAIXA_ANTIGA = [0, 100, 200, 300];
    public const FAIXA_DO_CARIMBO = [0, 1300, 1400, 600];
    public const SHA = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /**
     * Um item de `assinaturas` que passa na regra da E6-6, com os campos trocados.
     *
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    public static function item(array $troca = []): array
    {
        return $troca + [
            'campo' => 'Signature1',
            'faixa' => self::FAIXA,
            'intacta' => true,
            'valida' => true,
            'confiavel' => true,
            'ancora' => 'icp',
            'titular' => ['cn' => 'FULANA DE TAL:52998224725', 'cpf' => '52998224725', 'cnpj' => null],
            'cadeia' => [['cn' => 'AC Final', 'sha256' => self::SHA]],
            'revogacao' => 'boa',
            'carimbo' => ['presente' => false, 'valido' => false, 'hora' => null],
            'cobertura' => 'arquivo_inteiro',
            'nivel_de_modificacao' => 'nenhuma',
            'docmdp_ok' => null,
            'motivo' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $itens
     *
     * @return array<string, mixed>
     */
    public static function corpo(array $itens): array
    {
        return ['v' => 1, 'assinaturas' => $itens];
    }

    /** @param list<array<string, mixed>> $itens */
    public static function resultado(array $itens): ResultadoDaCadeia
    {
        return ResultadoDaCadeia::deJson(self::corpo($itens));
    }

    /**
     * Um resultado do motor com as assinaturas dadas (a última é a nova, salvo `$novas`).
     *
     * @param list<AssinaturaConferida> $assinaturas
     */
    public static function motor(
        array $assinaturas,
        int $novas = 1,
        SituacaoDaConferencia $situacao = SituacaoDaConferencia::Integro,
        ConferenciaDoPrefixo $prefixo = ConferenciaDoPrefixo::Confere,
    ): ResultadoDaConferencia {
        return new ResultadoDaConferencia(
            situacao: $situacao,
            motivo: 'exemplo',
            tamanho: 1200,
            assinaturas: $assinaturas,
            docMdp: 0,
            alteradoDepoisDaCertificacao: false,
            cifrado: false,
            permiteCarimbo: true,
            prefixo: $prefixo,
            assinaturasNovas: $novas,
            cpf: ConferenciaDoCpf::Confere,
        );
    }

    /**
     * O carimbo de documento (`/DocTimeStamp`) do motor, na faixa dada.
     *
     * @param list<int> $faixa
     */
    public static function carimboDoMotor(array $faixa = self::FAIXA_DO_CARIMBO, int $ordem = 2): AssinaturaConferida
    {
        return self::assinaturaDoMotor($faixa, ordem: $ordem, tipo: ConferenciaDePdf::TIPO_CARIMBO_DO_TEMPO);
    }

    /** @param list<int> $faixa */
    public static function assinaturaDoMotor(
        array $faixa = self::FAIXA,
        VereditoDaAssinatura $veredito = VereditoDaAssinatura::Integra,
        int $ordem = 1,
        string $tipo = 'PAdES',
        ConferenciaDoAcrescimo $acrescimo = ConferenciaDoAcrescimo::NaoSeAplica,
    ): AssinaturaConferida {
        return new AssinaturaConferida(
            ordem: $ordem,
            faixaDeBytes: $faixa,
            faixaValida: true,
            fim: $faixa[2] + $faixa[3],
            cobreODocumento: true,
            bytesDepois: 0,
            tipo: $tipo,
            algoritmoDoResumo: 'SHA-256',
            resumoConfere: $veredito === VereditoDaAssinatura::Integra,
            chaveConfere: $veredito === VereditoDaAssinatura::Integra,
            titular: 'FULANA DE TAL',
            cpf: '52998224725',
            cnpj: null,
            documentoMascarado: '***.982.247-**',
            emissor: 'AC Final',
            emissorDeclaraIcpBrasil: true,
            numeroDeSerie: '0a1b',
            validoDesde: new \DateTimeImmutable('2026-01-01 00:00:00'),
            validoAte: new \DateTimeImmutable('2027-01-01 00:00:00'),
            horaDeclarada: null,
            docMdp: null,
            veredito: $veredito,
            motivo: 'exemplo',
            acrescimo: $acrescimo,
            motivoDoAcrescimo: $acrescimo === ConferenciaDoAcrescimo::NaoSeAplica ? null : 'exemplo',
        );
    }
}
