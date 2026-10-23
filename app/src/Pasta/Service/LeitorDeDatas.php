<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Leitor central de datas e prazos escritos em texto livre (advocacia), portado do desenho 1.2.3
 * (`Component.lerDatas`, `diaUtil`, `somarDias`; E12c).
 *
 * Divide o texto em frases. Uma frase só vira prazo se tiver uma palavra de tipo (audiência, perícia, julgamento,
 * sustentação oral, contestação, recurso, manifestação, protocolo, pagamento, reunião, ou "prazo/vencimento/até o
 * dia/data limite"). Reconhece datas numéricas (16/10/2026, 15/11, 16.10.26) e por extenso ("20 de novembro de
 * 2026"); sem ano, vale o ano da data-base, ou o seguinte se já passou. Sem data, "prazo de N dias (úteis|corridos)"
 * vira data ESTIMADA, contada da data-base em dias úteis (fim de semana, 9 feriados fixos e recesso de 20/12 a
 * 20/01); "amanhã" e "hoje" também valem. Data precedida de contexto passado ("realizada", "cumprido", "protocolado
 * em"…) é ignorada; em remarcação ("redesignada para", "nova data") vale a data depois do "para". A hora vem junto
 * quando escrita perto ("às 14h30", "14:00").
 *
 * Diferenças deliberadas do protótipo: quebra de parágrafo separa frases (o protótipo juntava parágrafos do editor
 * numa frase só e a palavra de tipo de um contaminava o outro); número de processo CNJ e outros números com ponto não
 * viram data, nem ano fora de 1990 a 2100 (no protótipo "8.07.0001" de um CNJ virava 08/07/1901).
 */
final class LeitorDeDatas
{
    private const MESES = [
        'jan' => 1, 'janeiro' => 1, 'fev' => 2, 'fevereiro' => 2, 'mar' => 3, 'marco' => 3, 'abr' => 4, 'abril' => 4,
        'mai' => 5, 'maio' => 5, 'jun' => 6, 'junho' => 6, 'jul' => 7, 'julho' => 7, 'ago' => 8, 'agosto' => 8,
        'set' => 9, 'setembro' => 9, 'out' => 10, 'outubro' => 10, 'nov' => 11, 'novembro' => 11, 'dez' => 12, 'dezembro' => 12,
    ];

    private const FERIADOS = ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25'];

    /** [regex sobre o texto sem acento e minúsculo, tipo, título] — a primeira que casa decide a frase. */
    private const TIPOS = [
        ['/audiencia/', 'audiencia', 'Audiência'],
        ['/pericia/', 'prazo', 'Perícia'],
        ['/sessao de julgamento|julgamento|pauta/', 'prazo', 'Sessão de julgamento'],
        ['/sustentacao oral/', 'prazo', 'Sustentação oral'],
        ['/contestacao|contestar/', 'prazo', 'Prazo para contestação'],
        ['/recurso|apelacao|agravo|embargos/', 'prazo', 'Prazo recursal'],
        ['/manifestacao|manifestar|impugna/', 'prazo', 'Prazo para manifestação'],
        ['/protocol/', 'prazo', 'Protocolo'],
        ['/pagamento|boleto|parcela|deposito|custas|guia/', 'prazo', 'Vencimento de pagamento'],
        ['/reuniao|atendimento/', 'prazo', 'Reunião'],
        ['/prazo|vencimento|vence|ate o dia|data limite|limite/', 'prazo', ''],
    ];

    private const PASSADO = '/(realizad|ocorrid|cumprid|cancelad|protocolad[oa] em|juntad[oa] em|pag[oa] em|quitad|encerrad|decorrid)/';

    /**
     * Sem $calendario, a estimativa é a de sempre (fim de semana, 9 feriados fixos e recesso). Com ele, conta pelo
     * calendário do sistema e o prazo em dias corridos que cai em dia não útil vai ao próximo útil; data escrita e
     * audiência nunca mudam (docs/specs/prazo-feriado-calendario.md).
     *
     * @return list<array{tipo: string, titulo: string, data: string, hora: string, estimado: bool, frase: string}>
     */
    public static function ler(string $texto, ?\DateTimeImmutable $base = null, ?DiasUteisDaUf $calendario = null): array
    {
        $base = ($base ?? new \DateTimeImmutable())->setTime(0, 0);
        $frases = preg_split('/\n+|(?<=[.;!?])\s+/u', $texto) ?: [];
        $out = [];

        foreach ($frases as $frase) {
            $f = self::normalizar($frase);
            $tipo = null;
            foreach (self::TIPOS as $t) {
                if (preg_match($t[0], $f) === 1) {
                    $tipo = $t;
                    break;
                }
            }
            if ($tipo === null) {
                continue;
            }

            $achados = [];
            if (preg_match_all('/(\d{1,2})\s*[\/.-]\s*(\d{1,2})(?:\s*[\/.-]\s*(\d{2,4}))?/', $f, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($ms as $m) {
                    [$tudo, $i] = $m[0];
                    $d = (int) $m[1][0];
                    $mo = (int) $m[2][0];
                    if ($d < 1 || $d > 31 || $mo < 1 || $mo > 12) {
                        continue;
                    }
                    // Número colado (CNJ, CPF, valor): dígito, ou ponto/traço de numeração, antes; dígito depois.
                    if (($i > 0 && (ctype_digit($f[$i - 1]) || ($i > 1 && in_array($f[$i - 1], ['.', '-'], true) && ctype_digit($f[$i - 2]))))
                        || preg_match('/^[\d.-]*\d/', substr($f, $i + strlen($tudo))) === 1) {
                        continue;
                    }
                    $temAno = isset($m[3]) && $m[3][1] >= 0 && $m[3][0] !== '';
                    $ano = $temAno ? (int) (strlen($m[3][0]) === 2 ? '20' . $m[3][0] : $m[3][0]) : (int) $base->format('Y');
                    if ($ano < 1990 || $ano > 2100) {
                        continue;
                    }
                    $x = self::valida($ano, $mo, $d);
                    if ($x !== null && !$temAno && $x < $base) {
                        $x = self::valida($ano + 1, $mo, $d);
                    }
                    if ($x !== null) {
                        $achados[] = ['x' => $x, 'i' => $i];
                    }
                }
            }
            $reExt = '/(\d{1,2})\s*(?:de\s+)?(jan(?:eiro)?|fev(?:ereiro)?|mar(?:co)?|abr(?:il)?|mai(?:o)?|jun(?:ho)?|jul(?:ho)?|ago(?:sto)?|set(?:embro)?|out(?:ubro)?|nov(?:embro)?|dez(?:embro)?)\.?(?:\s*(?:de\s+)?(\d{4}))?/';
            if (preg_match_all($reExt, $f, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($ms as $m) {
                    $mo = self::MESES[$m[2][0]];
                    $temAno = isset($m[3]) && $m[3][1] >= 0 && $m[3][0] !== '';
                    $ano = $temAno ? (int) $m[3][0] : (int) $base->format('Y');
                    $x = self::valida($ano, $mo, (int) $m[1][0]);
                    if ($x !== null && !$temAno && $x < $base) {
                        $x = self::valida($ano + 1, $mo, (int) $m[1][0]);
                    }
                    if ($x !== null) {
                        $achados[] = ['x' => $x, 'i' => $m[0][1]];
                    }
                }
            }

            $estimado = false;
            if ($achados === []) {
                if (preg_match('/(?:prazo de|em|no prazo de|dentro de)\s*(\d{1,3})\s*(?:\(\w+\)\s*)?dias?\s*(uteis|corridos)?/', $f, $r, PREG_OFFSET_CAPTURE) === 1) {
                    $uteis = ($r[2][0] ?? '') !== 'corridos';
                    $achados[] = ['x' => self::estimar($base, (int) $r[1][0], $uteis, $calendario), 'i' => $r[0][1]];
                    $estimado = true;
                } elseif (preg_match('/\bamanha\b/', $f, $r, PREG_OFFSET_CAPTURE) === 1) {
                    $achados[] = ['x' => self::somarDias($base, 1, false), 'i' => $r[0][1]];
                } elseif (preg_match('/\bhoje\b/', $f, $r, PREG_OFFSET_CAPTURE) === 1) {
                    $achados[] = ['x' => $base, 'i' => $r[0][1]];
                }
            }
            if ($achados === []) {
                continue;
            }

            $usar = $achados;
            if (preg_match('/(redesignad|remarcad|adiad|transferid|nova data|alterad[oa] para)/', $f) === 1) {
                $p = preg_match('/\bpara\b/', $f, $pm, PREG_OFFSET_CAPTURE) === 1 ? $pm[0][1] : -1;
                $depois = array_values(array_filter($achados, static fn (array $a): bool => $a['i'] > $p));
                $lista = $depois !== [] ? $depois : $achados;
                $usar = [$lista[count($lista) - 1]];
            }

            $fraseLimpa = trim($frase);
            $fraseLimpa = mb_strtoupper(mb_substr($fraseLimpa, 0, 1)) . mb_substr($fraseLimpa, 1);
            foreach ($usar as $a) {
                $ini = max(0, $a['i'] - 40);
                if (preg_match(self::PASSADO, substr($f, $ini, $a['i'] - $ini)) === 1) {
                    continue;
                }
                $hora = '';
                if (preg_match('/(?:as|a partir das|horario)?\s*(\d{1,2})\s*(?:h|:)\s*(\d{2})?\b/', substr($f, $a['i'], 40), $h) === 1
                    && (int) $h[1] < 24 && preg_match('/(:|h)/', $h[0]) === 1) {
                    $hora = str_pad($h[1], 2, '0', STR_PAD_LEFT) . ':' . (($h[2] ?? '') !== '' ? $h[2] : '00');
                }
                $out[] = [
                    'tipo' => $tipo[1],
                    'titulo' => $tipo[2] !== '' ? $tipo[2] : (mb_strlen($fraseLimpa) > 60 ? mb_substr($fraseLimpa, 0, 57) . '…' : $fraseLimpa),
                    'data' => $a['x']->format('Y-m-d'),
                    'hora' => $hora,
                    'estimado' => $estimado,
                    'frase' => $fraseLimpa,
                ];
            }
        }

        return $out;
    }

    /** Texto do editor rico para o leitor: parágrafo e quebra viram fim de linha, o resto das tags some. */
    public static function textoDoHtml(string $html): string
    {
        $t = (string) preg_replace('#</(p|div|li|h[1-6])>|<br\s*/?>#i', "\n", $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{00A0}", ' ', $t);
        $t = (string) preg_replace('/[ \t]+/u', ' ', $t);

        return trim((string) preg_replace('/\s*\n\s*/u', "\n", $t));
    }

    public static function diaUtil(\DateTimeImmutable $d): bool
    {
        $w = (int) $d->format('w');
        $md = $d->format('m-d');
        $m = (int) $d->format('n');
        $dd = (int) $d->format('j');
        $recesso = ($m === 12 && $dd >= 20) || ($m === 1 && $dd <= 20);

        return $w !== 0 && $w !== 6 && !in_array($md, self::FERIADOS, true) && !$recesso;
    }

    public static function somarDias(\DateTimeImmutable $base, int $n, bool $uteis): \DateTimeImmutable
    {
        $d = $base->setTime(0, 0);
        if (!$uteis) {
            return $d->modify('+' . $n . ' days');
        }
        $c = 0;
        while ($c < $n) {
            $d = $d->modify('+1 day');
            if (self::diaUtil($d)) {
                ++$c;
            }
        }

        return $d;
    }

    /** "prazo de N dias": sem calendário, {@see somarDias()}; com ele, pelo calendário do sistema. */
    private static function estimar(\DateTimeImmutable $base, int $n, bool $uteis, ?DiasUteisDaUf $calendario): \DateTimeImmutable
    {
        if ($calendario === null) {
            return self::somarDias($base, $n, $uteis);
        }
        $d = $base->setTime(0, 0);
        if (!$uteis) {
            // CPC, art. 224, §1º: termo em dia não útil prorroga ao próximo útil (com N = 0, o termo é a própria base).
            return $calendario->proximoUtil($d->modify('+' . $n . ' days'));
        }
        for ($c = 0; $c < $n;) {
            $d = $d->modify('+1 day');
            if ($calendario->ehUtil($d)) {
                ++$c;
            }
        }

        // Mesmo §1º: com N = 0 úteis o termo é a base, que pode cair em dia não útil; com N > 0 já é útil.
        return $calendario->proximoUtil($d);
    }

    private static function normalizar(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_D);

        return mb_strtolower((string) preg_replace('/\p{Mn}/u', '', $n === false ? $s : $n));
    }

    private static function valida(int $ano, int $mes, int $dia): ?\DateTimeImmutable
    {
        return checkdate($mes, $dia, $ano) ? new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia)) : null;
    }
}
