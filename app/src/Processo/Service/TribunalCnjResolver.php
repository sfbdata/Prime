<?php

declare(strict_types=1);

namespace App\Processo\Service;

use App\Processo\Exception\TribunalNaoIdentificadoException;

/**
 * Deriva o tribunal a partir do número do processo no padrão CNJ.
 *
 * O número unificado segue o formato NNNNNNN-DD.AAAA.J.TR.OOOO, onde o dígito J
 * identifica o Segmento do Poder Judiciário e os dígitos TR identificam o Tribunal
 * dentro daquele segmento. Este serviço extrai J e TR do número e consulta o mapa
 * oficial para obter a sigla do tribunal (ex.: "TJSP") e o alias do índice usado pela
 * API pública do Datajud (ex.: "tjsp"), eliminando a necessidade de o usuário informar
 * a sigla manualmente.
 */
final class TribunalCnjResolver
{
    /**
     * De/para dos códigos CNJ (segmento J → tribunal TR → sigla).
     *
     * A sigla é a forma de exibição/persistência; o alias do índice Datajud é a mesma
     * sigla em minúsculas (ver resolverAlias). Mantém-se o nome do segmento por fidelidade
     * ao mapa oficial e para facilitar auditoria contra a fonte.
     *
     * @var array<string, array{nome: string, tribunais: array<string, string>}>
     */
    private const MAPA = [
        '1' => [
            'nome' => 'Supremo Tribunal Federal',
            'tribunais' => ['00' => 'STF'],
        ],
        '2' => [
            'nome' => 'Conselho Nacional de Justiça',
            'tribunais' => ['00' => 'CNJ'],
        ],
        '3' => [
            'nome' => 'Superior Tribunal de Justiça',
            'tribunais' => ['00' => 'STJ'],
        ],
        '4' => [
            'nome' => 'Justiça Federal',
            'tribunais' => [
                '01' => 'TRF1', '02' => 'TRF2', '03' => 'TRF3', '04' => 'TRF4',
                '05' => 'TRF5', '06' => 'TRF6', '90' => 'CJF',
            ],
        ],
        '5' => [
            'nome' => 'Justiça do Trabalho',
            'tribunais' => [
                '00' => 'TST',
                '01' => 'TRT1', '02' => 'TRT2', '03' => 'TRT3', '04' => 'TRT4',
                '05' => 'TRT5', '06' => 'TRT6', '07' => 'TRT7', '08' => 'TRT8',
                '09' => 'TRT9', '10' => 'TRT10', '11' => 'TRT11', '12' => 'TRT12',
                '13' => 'TRT13', '14' => 'TRT14', '15' => 'TRT15', '16' => 'TRT16',
                '17' => 'TRT17', '18' => 'TRT18', '19' => 'TRT19', '20' => 'TRT20',
                '21' => 'TRT21', '22' => 'TRT22', '23' => 'TRT23', '24' => 'TRT24',
                '90' => 'CSJT',
            ],
        ],
        '6' => [
            'nome' => 'Justiça Eleitoral',
            'tribunais' => [
                '00' => 'TSE',
                '01' => 'TRE-AC', '02' => 'TRE-AL', '03' => 'TRE-AP', '04' => 'TRE-AM',
                '05' => 'TRE-BA', '06' => 'TRE-CE', '07' => 'TRE-DF', '08' => 'TRE-ES',
                '09' => 'TRE-GO', '10' => 'TRE-MA', '11' => 'TRE-MT', '12' => 'TRE-MS',
                '13' => 'TRE-MG', '14' => 'TRE-PA', '15' => 'TRE-PB', '16' => 'TRE-PR',
                '17' => 'TRE-PE', '18' => 'TRE-PI', '19' => 'TRE-RJ', '20' => 'TRE-RN',
                '21' => 'TRE-RS', '22' => 'TRE-RO', '23' => 'TRE-RR', '24' => 'TRE-SC',
                '25' => 'TRE-SE', '26' => 'TRE-SP', '27' => 'TRE-TO',
            ],
        ],
        '7' => [
            'nome' => 'Justiça Militar da União',
            'tribunais' => [
                '00' => 'STM',
                '01' => '1CJM', '02' => '2CJM', '03' => '3CJM', '04' => '4CJM',
                '05' => '5CJM', '06' => '6CJM', '07' => '7CJM', '08' => '8CJM',
                '09' => '9CJM', '10' => '10CJM', '11' => '11CJM', '12' => '12CJM',
            ],
        ],
        '8' => [
            'nome' => 'Justiça Estadual e Distrital',
            'tribunais' => [
                '01' => 'TJAC', '02' => 'TJAL', '03' => 'TJAP', '04' => 'TJAM',
                '05' => 'TJBA', '06' => 'TJCE', '07' => 'TJDFT', '08' => 'TJES',
                '09' => 'TJGO', '10' => 'TJMA', '11' => 'TJMT', '12' => 'TJMS',
                '13' => 'TJMG', '14' => 'TJPA', '15' => 'TJPB', '16' => 'TJPR',
                '17' => 'TJPE', '18' => 'TJPI', '19' => 'TJRJ', '20' => 'TJRN',
                '21' => 'TJRS', '22' => 'TJRO', '23' => 'TJRR', '24' => 'TJSC',
                '25' => 'TJSE', '26' => 'TJSP', '27' => 'TJTO',
            ],
        ],
        '9' => [
            'nome' => 'Justiça Militar Estadual',
            'tribunais' => [
                '13' => 'TJMMG', '21' => 'TJMRS', '26' => 'TJMSP',
            ],
        ],
    ];

    /**
     * Sigla de exibição do tribunal (ex.: "TJSP", "TRE-DF") derivada do número CNJ.
     *
     * @throws TribunalNaoIdentificadoException se o número não estiver no padrão CNJ de
     *                                          20 dígitos ou o par J/TR for desconhecido
     */
    public function resolverSigla(string $numeroProcesso): string
    {
        $digitos = preg_replace('/\D+/', '', $numeroProcesso) ?? '';

        // NNNNNNN(7) DD(2) AAAA(4) J(1) TR(2) OOOO(4) = 20 dígitos.
        if (!preg_match('/^(\d{7})(\d{2})(\d{4})(\d)(\d{2})(\d{4})$/', $digitos, $partes)) {
            throw new TribunalNaoIdentificadoException(sprintf(
                'Número "%s" não está no padrão CNJ de 20 dígitos.',
                $numeroProcesso
            ));
        }

        $segmento = $partes[4];
        $tribunal = $partes[5];

        $sigla = self::MAPA[$segmento]['tribunais'][$tribunal] ?? null;
        if ($sigla === null) {
            throw new TribunalNaoIdentificadoException(sprintf(
                'Segmento "%s" e tribunal "%s" (número "%s") não correspondem a nenhum tribunal conhecido.',
                $segmento,
                $tribunal,
                $numeroProcesso
            ));
        }

        return $sigla;
    }

    /**
     * Alias do índice da API pública do Datajud (ex.: "tjsp", "tre-df"), usado para montar
     * a URL api_publica_<alias>. É a sigla em minúsculas.
     *
     * @throws TribunalNaoIdentificadoException
     */
    public function resolverAlias(string $numeroProcesso): string
    {
        return strtolower($this->resolverSigla($numeroProcesso));
    }
}
