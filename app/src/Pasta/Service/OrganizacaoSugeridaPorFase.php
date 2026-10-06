<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * "Organização sugerida para esta fase" (DOC-80): a lista de pastas que o desenho propõe para
 * organizar os arquivos, por fase do processo (bj-docsug.js L109-112).
 *
 * Lista estática, POR REGRA — não lê o processo nem os arquivos. A tela diz isso no rótulo
 * ("por regras"). E só MOSTRA: o desenho não tem "criar estas pastas", e não se inventa aqui.
 *
 *  - Base, sempre: 01 Processo, 02 Petições, 03 Decisões, 04 Documentos das partes, 05 Provas.
 *  - Extras da fase (provas, audiência, perícia, sentença, recurso, cumprimento); fase sem extra
 *    conhecido recebe "06 Prazos" (o `|| ['06 Prazos']` do JS).
 *  - Por último: Encerramento.
 *
 * Sem fase (pasta sem catálogo) não há sugestão: lista vazia, e a tela não desenha nada.
 */
final class OrganizacaoSugeridaPorFase
{
    public const BASE = ['01 Processo', '02 Petições', '03 Decisões', '04 Documentos das partes', '05 Provas'];

    /** bj-docsug.js L111 (`extra`). */
    public const EXTRAS = [
        'provas'      => ['06 Prazos e audiências'],
        'audiencia'   => ['06 Prazos e audiências'],
        'pericia'     => ['06 Perícia'],
        'sentenca'    => ['06 Prazos', '07 Recursos'],
        'recurso'     => ['06 Prazos', '07 Recursos'],
        'cumprimento' => ['06 Prazos', '07 Cálculos', '08 Cumprimento de sentença', '09 Pagamentos'],
    ];

    public const EXTRA_PADRAO = ['06 Prazos'];

    public const ULTIMA = 'Encerramento';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function para(?string $fase): array
    {
        $fase = $fase !== null ? trim($fase) : '';
        if ($fase === '') {
            return [];
        }

        return [...self::BASE, ...(self::EXTRAS[$fase] ?? self::EXTRA_PADRAO), self::ULTIMA];
    }

    /**
     * Uma lista por fase do catálogo — o que a tela recebe, para escolher pela fase que o
     * `SugestorDeDocumentos` decidiu (o painel é montado pela extensão Twig, não pelo controller).
     *
     * @return array<string, list<string>>
     */
    public static function porFase(): array
    {
        $porFase = [];
        foreach (array_keys(CatalogoDeDocumentos::NOMES_DAS_FASES) as $fase) {
            $porFase[$fase] = self::para($fase);
        }

        return $porFase;
    }
}
