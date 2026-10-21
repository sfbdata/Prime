<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\DTO\AtributosDoDestinatario;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\FiltroDoPublico;

/**
 * O motor do "Filtrar destinatários" (desenho 05 VS 21.10.26, L635), na ordem do dc. Quem atende a todos os campos
 * preenchidos fica; a pessoa sem o dado do primeiro campo que precisa dele (gênero, idade, UF, cargo ou aniversário)
 * conta uma vez em "sem essa informação no cadastro (ficam de fora)". Papel, OAB e plano não contam como falta: quem
 * não tem conta só não atende. Puro: a data de referência vem de fora, para a campanha recorrente usar a da vez.
 */
final class FiltroDeDestinatarios
{
    private const SEM_INFORMACAO = 'sem';
    private const FORA = 'fora';

    /**
     * @param list<DestinatarioDaCampanha>          $lista
     * @param array<string, AtributosDoDestinatario> $atributos e-mail => atributos
     *
     * @return array{0: list<DestinatarioDaCampanha>, 1: int} os que atendem e quantos ficaram sem a informação
     */
    public static function aplicar(array $lista, array $atributos, FiltroDoPublico $filtro, \DateTimeImmutable $hoje): array
    {
        if (!$filtro->ativo()) {
            return [$lista, 0];
        }
        $termos = $filtro->termos();
        $atendem = [];
        $semInformacao = 0;
        foreach ($lista as $d) {
            $motivo = self::recusa($d, $atributos[$d->email] ?? new AtributosDoDestinatario(), $filtro, $termos, $hoje);
            if ($motivo === null) {
                $atendem[] = $d;
            }
            if ($motivo === self::SEM_INFORMACAO) {
                ++$semInformacao;
            }
        }

        return [$atendem, $semInformacao];
    }

    /** @param list<string> $termos */
    private static function recusa(DestinatarioDaCampanha $d, AtributosDoDestinatario $a, FiltroDoPublico $f, array $termos, \DateTimeImmutable $hoje): ?string
    {
        return self::porGenero($a, $f)
            ?? self::porIdade($a, $f, $hoje)
            ?? self::porUf($a, $f)
            ?? self::porBusca($d, $termos)
            ?? self::igual($f->papel, $a->papel)
            ?? self::porCargo($a, $f)
            ?? self::igual($f->oab, $a->oab)
            ?? self::porPlano($a, $f)
            ?? self::porAniversario($a, $f, $hoje);
    }

    private static function porGenero(AtributosDoDestinatario $a, FiltroDoPublico $f): ?string
    {
        return $f->genero === null ? null : self::comDado($a->genero, $f->genero);
    }

    private static function porIdade(AtributosDoDestinatario $a, FiltroDoPublico $f, \DateTimeImmutable $hoje): ?string
    {
        if ($f->idade === null) {
            return null;
        }
        // Nascimento no futuro é dado ruim: conta como sem a informação, e não numa faixa.
        if ($a->nascimento === null || $a->nascimento > $hoje) {
            return self::SEM_INFORMACAO;
        }
        $anos = $a->nascimento->diff($hoje)->y;
        [$de, $ate] = FiltroDoPublico::FAIXAS_DE_IDADE[$f->idade];

        return $anos >= $de && $anos <= $ate ? null : self::FORA;
    }

    private static function porUf(AtributosDoDestinatario $a, FiltroDoPublico $f): ?string
    {
        return $f->uf === null ? null : self::comDado($a->uf, $f->uf);
    }

    /** @param list<string> $termos */
    private static function porBusca(DestinatarioDaCampanha $d, array $termos): ?string
    {
        if ($termos === []) {
            return null;
        }
        $alvo = mb_strtolower($d->email . ' ' . $d->nome);
        foreach ($termos as $termo) {
            if (str_contains($alvo, $termo)) {
                return null;
            }
        }

        return self::FORA;
    }

    private static function porCargo(AtributosDoDestinatario $a, FiltroDoPublico $f): ?string
    {
        return $f->cargo === null ? null : self::comDado($a->cargo, $f->cargo);
    }

    private static function porPlano(AtributosDoDestinatario $a, FiltroDoPublico $f): ?string
    {
        return $f->plano === null || \in_array($f->plano, $a->planos, true) ? null : self::FORA;
    }

    /** O mês de nascimento é o deste mês (`mes`) ou o do próximo (`prox`, dezembro vira janeiro). */
    private static function porAniversario(AtributosDoDestinatario $a, FiltroDoPublico $f, \DateTimeImmutable $hoje): ?string
    {
        if ($f->aniversario === null) {
            return null;
        }
        if ($a->nascimento === null) {
            return self::SEM_INFORMACAO;
        }
        $mes = (int) $hoje->format('n');
        $alvo = $f->aniversario === 'prox' ? $mes % 12 + 1 : $mes;

        return (int) $a->nascimento->format('n') === $alvo ? null : self::FORA;
    }

    private static function comDado(?string $valor, string $esperado): ?string
    {
        if ($valor === null) {
            return self::SEM_INFORMACAO;
        }

        return $valor === $esperado ? null : self::FORA;
    }

    private static function igual(?string $esperado, ?string $valor): ?string
    {
        return $esperado === null || $valor === $esperado ? null : self::FORA;
    }
}
