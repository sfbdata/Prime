<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * As 27 capitais, por UF: a mesma lista `CAP` do painel.js (desenho 05, Perfis 1.2, VS 21.10.26, L879). A nota por
 * cidade da janela da OAB vale só para a capital da UF; o nome gravado é sempre o daqui (canônico), para que "macapa"
 * e "Macapá" sejam a mesma nota.
 */
final class CapitaisDosEstados
{
    public const CAPITAIS = [
        'AC' => 'Rio Branco', 'AL' => 'Maceió', 'AP' => 'Macapá', 'AM' => 'Manaus', 'BA' => 'Salvador', 'CE' => 'Fortaleza',
        'DF' => 'Brasília', 'ES' => 'Vitória', 'GO' => 'Goiânia', 'MA' => 'São Luís', 'MT' => 'Cuiabá', 'MS' => 'Campo Grande',
        'MG' => 'Belo Horizonte', 'PA' => 'Belém', 'PB' => 'João Pessoa', 'PR' => 'Curitiba', 'PE' => 'Recife', 'PI' => 'Teresina',
        'RJ' => 'Rio de Janeiro', 'RN' => 'Natal', 'RS' => 'Porto Alegre', 'RO' => 'Porto Velho', 'RR' => 'Boa Vista',
        'SC' => 'Florianópolis', 'SP' => 'São Paulo', 'SE' => 'Aracaju', 'TO' => 'Palmas',
    ];

    /** O nome canônico da capital da UF, se a cidade informada for ela (sem acento, sem caixa e sem espaço nas pontas). */
    public static function canonica(string $uf, string $cidade): ?string
    {
        $capital = self::CAPITAIS[$uf] ?? null;

        return $capital !== null && self::chave($capital) === self::chave($cidade) ? $capital : null;
    }

    private static function chave(string $nome): string
    {
        $semAcento = \Normalizer::normalize(trim($nome), \Normalizer::FORM_D);

        return mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', (string) $semAcento));
    }
}
