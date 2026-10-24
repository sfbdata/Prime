<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Sinal CONSERVADOR de que a parte pode ter prazo em dobro (Fazenda Pública, MP e Defensoria; CPC arts. 180, 183 e
 * 186), para a tela dizer "pode ser prazo em dobro" (docs/specs/prazo-do-push.md, pergunta 4). O prazo NUNCA é
 * dobrado: quem confere decide.
 *
 * Casa só a expressão inteira, com limite de palavra, sobre o texto sem acento e em minúsculas: "estado de/do/da"
 * só seguido do nome de uma UF ("Construtora Estado Novo" e "estado de saúde" não casam), "união" fora de "união
 * estável", "município de" fora de "município de residência/domicílio/origem".
 */
final class SinalDePrazoEmDobro
{
    private const UFS = 'acre|alagoas|amapa|amazonas|bahia|ceara|espirito santo|goias|maranhao|mato grosso(?: do sul)?|minas gerais'
        . '|para|paraiba|parana|pernambuco|piaui|rio de janeiro|rio grande do (?:norte|sul)|rondonia|roraima|santa catarina'
        . '|sao paulo|sergipe|tocantins';

    private const PADROES = [
        '/\buniao\b(?!\s+estavel)/u',
        '/\bfazenda (?:publica|nacional|estadual|municipal)\b/u',
        '/\bestado d[eoa] (?:' . self::UFS . ')\b/u',
        '/\bmunicipio d[eoa] (?!residencia|domicilio|origem)[a-z]/u',
        '/\bdistrito federal\b/u',
        '/\binss\b/u',
        '/\binstituto nacional do seguro social\b/u',
        '/\bautarquia\b/u',
        '/\bfundacao publica\b/u',
        '/\bministerio publico\b/u',
        '/\bdefensoria publica\b/u',
        '/\bprocuradoria(?:[ -]geral)? d[oa]\b/u',
    ];

    /** @param iterable<string> $textos nomes das partes e o trecho da determinação */
    public static function emAlgum(iterable $textos): bool
    {
        foreach ($textos as $texto) {
            if (self::em($texto)) {
                return true;
            }
        }

        return false;
    }

    public static function em(string $texto): bool
    {
        $normalizado = (string) preg_replace('/\s+/u', ' ', SimilaridadeDeNomes::normalizar($texto));
        foreach (self::PADROES as $padrao) {
            if (preg_match($padrao, $normalizado) === 1) {
                return true;
            }
        }

        return false;
    }
}
