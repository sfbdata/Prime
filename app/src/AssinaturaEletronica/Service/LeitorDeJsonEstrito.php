<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * Leitura estrita de JSON que o próprio sistema gravou (a análise da versão do PDF) ou que veio de outro serviço (a
 * resposta do validador de cadeia): o campo tem de existir e ter o tipo pedido, e nada é convertido. O contrário do
 * {@see \App\AssinaturaEletronica\DTO\LeitorDeCampos}, que é tolerante com o que a tela manda.
 *
 * Toda falha é `\UnexpectedValueException` com o nome do campo; quem chama decide o que ela vira.
 */
final class LeitorDeJsonEstrito
{
    private function __construct()
    {
    }

    /** @param array<mixed> $json */
    public static function inteiro(array $json, string $campo): int
    {
        $valor = self::valor($json, $campo);

        return \is_int($valor) ? $valor : throw self::falha($campo, 'inteiro');
    }

    /** @param array<mixed> $json */
    public static function inteiroOuNulo(array $json, string $campo): ?int
    {
        $valor = self::valor($json, $campo);

        return $valor === null || \is_int($valor) ? $valor : throw self::falha($campo, 'inteiro ou nulo');
    }

    /** @param array<mixed> $json */
    public static function booleano(array $json, string $campo): bool
    {
        $valor = self::valor($json, $campo);

        return \is_bool($valor) ? $valor : throw self::falha($campo, 'booleano');
    }

    /** @param array<mixed> $json */
    public static function booleanoOuNulo(array $json, string $campo): ?bool
    {
        $valor = self::valor($json, $campo);

        return $valor === null || \is_bool($valor) ? $valor : throw self::falha($campo, 'booleano ou nulo');
    }

    /** @param array<mixed> $json */
    public static function texto(array $json, string $campo): string
    {
        $valor = self::valor($json, $campo);

        return \is_string($valor) ? $valor : throw self::falha($campo, 'texto');
    }

    /** @param array<mixed> $json */
    public static function textoOuNulo(array $json, string $campo): ?string
    {
        $valor = self::valor($json, $campo);

        return $valor === null || \is_string($valor) ? $valor : throw self::falha($campo, 'texto ou nulo');
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<mixed>
     */
    public static function lista(array $json, string $campo): array
    {
        $valor = self::valor($json, $campo);

        return \is_array($valor) && array_is_list($valor) ? $valor : throw self::falha($campo, 'lista');
    }

    /**
     * Um objeto do JSON (array associativo); o objeto vazio também vale.
     *
     * @param array<mixed> $json
     *
     * @return array<mixed>
     */
    public static function objeto(array $json, string $campo): array
    {
        $valor = self::valor($json, $campo);

        return \is_array($valor) && ($valor === [] || !array_is_list($valor)) ? $valor : throw self::falha($campo, 'objeto');
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<int>
     */
    public static function listaDeInteiros(array $json, string $campo): array
    {
        $lista = self::lista($json, $campo);
        foreach ($lista as $item) {
            if (!\is_int($item)) {
                throw self::falha($campo, 'lista de inteiros');
            }
        }

        /** @var list<int> $lista */
        return $lista;
    }

    /**
     * Data no formato ISO 8601 com fuso (`2026-11-09T10:00:00+00:00`), sem conserto: data impossível não passa.
     *
     * @param array<mixed> $json
     */
    public static function dataOuNula(array $json, string $campo): ?\DateTimeImmutable
    {
        $valor = self::textoOuNulo($json, $campo);
        if ($valor === null) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!' . \DATE_ATOM, $valor);
        if ($data === false || $data->format(\DATE_ATOM) !== $valor) {
            throw self::falha($campo, 'data ISO 8601');
        }

        return $data;
    }

    /**
     * Um caso de enum de texto pelo valor gravado.
     *
     * @template T of \BackedEnum
     *
     * @param array<mixed>    $json
     * @param class-string<T> $enum
     *
     * @return T
     */
    public static function enum(array $json, string $campo, string $enum): \BackedEnum
    {
        $valor = self::valor($json, $campo);
        $caso = \is_string($valor) ? $enum::tryFrom($valor) : null;

        return $caso ?? throw self::falha($campo, 'um dos valores de ' . $enum);
    }

    /** @param array<mixed> $json */
    private static function valor(array $json, string $campo): mixed
    {
        if (!\array_key_exists($campo, $json)) {
            throw new \UnexpectedValueException(sprintf('JSON sem o campo "%s".', $campo));
        }

        return $json[$campo];
    }

    private static function falha(string $campo, string $esperado): \UnexpectedValueException
    {
        return new \UnexpectedValueException(sprintf('O campo "%s" do JSON não é %s.', $campo, $esperado));
    }
}
