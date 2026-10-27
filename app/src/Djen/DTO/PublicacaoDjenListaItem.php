<?php

declare(strict_types=1);

namespace App\Djen\DTO;

/**
 * Item de LISTA da tela de publicações — projetado por DQL (só as colunas exibidas), para NÃO hidratar
 * o `texto` (TEXT) nem o `payloadDjen` (JSONB) de cada linha da página. Ver PublicacaoDjenRepository.
 *
 * `textoResumo` é o trecho curto do teor que vira o título do cartão na aba Push da pasta (desenho
 * 1.2.3, dc L.2514/5378: `titulo: m.texto`; inventário FPN-012). Só a consulta da PASTA
 * (`listarItensPorNumerosDoTenant`) projeta o começo do teor (`textoInicio`). A lista do MÓDULO
 * não projeta o teor e recebe `null` aqui, como antes: lá a coluna é o órgão e nada muda.
 */
final class PublicacaoDjenListaItem
{
    /** Tamanho do trecho em caracteres. O corte cai no fim da última palavra inteira. */
    public const LIMITE_RESUMO = 220;

    private function __construct(
        public readonly int $id,
        public readonly string $siglaTribunal,
        public readonly ?string $tipoComunicacao,
        public readonly ?string $numeroProcessoExibicao,
        public readonly ?string $dataDisponibilizacao,
        public readonly ?string $nomeOrgao,
        public readonly bool $lida,
        public readonly bool $avulsa,
        public readonly ?string $textoResumo = null,
        /** "Sentença", "Decisão", "Despacho"… (quando a query o projeta): é o que diz o ato, mais que a comunicação. */
        public readonly ?string $tipoDocumento = null,
        /** Pasta que o processo da publicação ocupa (a lista do módulo a projeta; as outras consultas, não). */
        public readonly ?int $pastaId = null,
        public readonly ?string $pastaNup = null,
        /** Data de disponibilização em Y-m-d (a coluna formatada é para exibir; esta é para contar prazo). */
        public readonly ?string $dataIso = null,
        public readonly ?int $prazoDias = null,
        public readonly bool $prazoCumprido = false,
        public readonly ?int $tarefaId = null,
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
            array_key_exists('pastaId', $row) ? ($row['pastaId'] ?? null) === null : ($row['processoId'] ?? null) === null,
            self::resumirTexto(is_string($row['textoInicio'] ?? null) ? $row['textoInicio'] : null),
            self::nullableString($row['tipoDocumento'] ?? null),
            isset($row['pastaId']) ? (int) $row['pastaId'] : null,
            self::nullableString($row['pastaNup'] ?? null),
            $data instanceof \DateTimeInterface ? $data->format('Y-m-d') : (is_string($data) && $data !== '' ? substr($data, 0, 10) : null),
            isset($row['prazoDias']) ? (int) $row['prazoDias'] : null,
            ($row['prazoCumpridoEm'] ?? null) !== null,
            isset($row['tarefaId']) ? (int) $row['tarefaId'] : null,
        );
    }

    /**
     * Texto PURO do teor (que vem em HTML), cortado em `$limite` caracteres no fim da última palavra
     * inteira, com reticências quando cortou. Sem texto útil, `null` (o Twig cai no órgão).
     *
     * A saída é texto, não HTML: as entidades são decodificadas aqui e o Twig escapa na exibição.
     * Por isso um `&lt;script&gt;` do teor aparece como texto e não vira marcação.
     */
    public static function resumirTexto(?string $html, int $limite = self::LIMITE_RESUMO): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        // Conteúdo de script/style não é texto do teor. As tags viram espaço, para que
        // "</p><p>" não cole a última palavra de um parágrafo na primeira do seguinte.
        $texto = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $texto = (string) preg_replace('/<[^>]*>/', ' ', $texto);
        // O teor chega cortado pela consulta (`textoInicio`): uma tag aberta no fim sai inteira.
        $texto = (string) preg_replace('/<[^>]*$/', ' ', $texto);
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = str_replace("\u{00A0}", ' ', $texto);
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        if ($texto === '') {
            return null;
        }
        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        $corte = mb_substr($texto, 0, $limite);
        // O caractere seguinte ao corte diz se a última palavra ficou inteira.
        if (mb_substr($texto, $limite, 1) !== ' ') {
            $espaco = mb_strrpos($corte, ' ');
            if ($espaco !== false && $espaco > 0) {
                $corte = mb_substr($corte, 0, $espaco);
            }
        }

        // Pontuação solta antes das reticências sai ("prazo, …" → "prazo…"). Por regex /u e não
        // por rtrim: o rtrim corta BYTES e mutilaria letra acentuada no fim do trecho.
        return (string) preg_replace('/[\s,;:.\-–—]+$/u', '', $corte) . '…';
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
