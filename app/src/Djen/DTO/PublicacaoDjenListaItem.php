<?php

declare(strict_types=1);

namespace App\Djen\DTO;

/**
 * Item de LISTA da tela de publicações — projetado por DQL (só as colunas exibidas), para NÃO hidratar
 * o `texto` (TEXT) nem o `payloadDjen` (JSONB) de cada linha da página. Ver PublicacaoDjenRepository.
 */
final class PublicacaoDjenListaItem
{
    private function __construct(
        public readonly int $id,
        public readonly string $siglaTribunal,
        public readonly ?string $tipoComunicacao,
        public readonly ?string $numeroProcessoExibicao,
        public readonly ?string $dataDisponibilizacao,
        public readonly ?string $nomeOrgao,
        public readonly bool $lida,
        public readonly bool $avulsa,
    ) {
    }

    /**
     * @param array<string, mixed> $row linha projetada (aliases da query)
     */
    public static function fromRow(array $row): self
    {
        $mascara = $row['numeroProcessoComMascara'] ?? null;
        $numero = (string) ($row['numeroProcesso'] ?? '');
        $data = $row['dataDisponibilizacao'] ?? null;

        return new self(
            (int) $row['id'],
            (string) ($row['siglaTribunal'] ?? ''),
            self::nullableString($row['tipoComunicacao'] ?? null),
            is_string($mascara) && $mascara !== '' ? $mascara : ($numero !== '' ? $numero : null),
            self::formatarData($data),
            self::nullableString($row['nomeOrgao'] ?? null),
            (bool) ($row['lida'] ?? false),
            ($row['processoId'] ?? null) === null,
        );
    }

    private static function formatarData(mixed $data): ?string
    {
        if ($data instanceof \DateTimeInterface) {
            return $data->format('d/m/Y');
        }

        // Fallback defensivo caso a hidratação devolva a data como string 'Y-m-d'.
        if (is_string($data) && $data !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($data, 0, 10));

            return $parsed !== false ? $parsed->format('d/m/Y') : null;
        }

        return null;
    }

    private static function nullableString(mixed $valor): ?string
    {
        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }
}
