<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * Faixa etária dos usuários (desenho 05 - Perfis 1.0, `Component.IDADES`), calculada pela data de
 * nascimento do Meu Perfil (`UserProfile.dataNascimento`). Quem não tem data não entra na conta e é
 * contado à parte, e quem tem menos de 18 anos também (fica fora das faixas, mas na nota da tela,
 * para a conta fechar). A idade média é a média das idades reais, não um ponto médio de faixa.
 */
final class FaixaEtaria
{
    /** Rótulo, idade mínima, idade máxima (inclusive). */
    public const FAIXAS = [
        ['18 a 24', 18, 24],
        ['25 a 34', 25, 34],
        ['35 a 44', 35, 44],
        ['45 a 54', 45, 54],
        ['55 ou +', 55, 200],
    ];

    /**
     * Chave do rótulo no catálogo (spec idioma-do-sistema, P2b): "18 a 24" é master.faixa_etaria.intervalo com
     * {min} e {max}; a última faixa, aberta ("55 ou +"), é master.faixa_etaria.ou_mais com {min}. Rótulo que não é
     * de uma faixa devolve nulo (quem traduz deixa como veio). O CatalogoDasRecusasDoMasterTest confere que o
     * português do catálogo é o rótulo de FAIXAS.
     *
     * @return array{0: string, 1: array<string, int>}|null
     */
    public static function chaveDoRotulo(string $rotulo): ?array
    {
        foreach (self::FAIXAS as $i => [$daFaixa, $min, $max]) {
            if ($daFaixa === $rotulo) {
                return $i === array_key_last(self::FAIXAS)
                    ? ['master.faixa_etaria.ou_mais', ['min' => $min]]
                    : ['master.faixa_etaria.intervalo', ['min' => $min, 'max' => $max]];
            }
        }

        return null;
    }

    /**
     * @param list<?string> $nascimentos datas 'Y-m-d' (ou nulas)
     *
     * @return array{faixas: list<array{rot: string, n: int}>, media: ?int, semData: int, menores: int}
     */
    public static function distribuir(array $nascimentos, \DateTimeImmutable $hoje): array
    {
        $contagem = array_fill(0, count(self::FAIXAS), 0);
        $idades   = [];
        $semData  = 0;
        $menores  = 0;

        foreach ($nascimentos as $nascimento) {
            $idade = self::idade($nascimento, $hoje);
            if ($idade === null) {
                $semData++;
                continue;
            }

            $idades[] = $idade;
            if ($idade < self::FAIXAS[0][1]) {
                $menores++;
            }
            foreach (self::FAIXAS as $i => [, $min, $max]) {
                if ($idade >= $min && $idade <= $max) {
                    $contagem[$i]++;
                    break;
                }
            }
        }

        $faixas = [];
        foreach (self::FAIXAS as $i => [$rotulo]) {
            $faixas[] = ['rot' => $rotulo, 'n' => $contagem[$i]];
        }

        return [
            'faixas'  => $faixas,
            'media'   => $idades === [] ? null : (int) round(array_sum($idades) / count($idades)),
            'semData' => $semData,
            'menores' => $menores,
        ];
    }

    public static function idade(?string $nascimento, \DateTimeImmutable $hoje): ?int
    {
        if ($nascimento === null || $nascimento === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($nascimento, 0, 10));
        if ($data === false || $data > $hoje) {
            return null;
        }

        return $data->diff($hoje)->y;
    }
}
