<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Agenda\Service\DiasUteisNacionais;
use App\Pasta\DTO\DeterminacaoDoJuizoOutput;
use App\Pasta\DTO\TeorDePublicacaoInput;
use App\Pasta\Entity\PastaPrazo;

/**
 * Os prazos e audiências de UMA publicação do Push, sem banco (docs/specs/prazo-do-push.md §2).
 *
 * - Prazo: só a determinação com prazo explícito em DIAS ({@see DeterminacoesDoJuizo}, nada presumido). O termo
 *   inicial é a publicação no DJEN ({@see DiasUteisNacionais::publicacaoDe()}, o 1º dia útil depois da
 *   disponibilização) e a contagem começa no dia útil seguinte, pelo calendário da UF do escritório
 *   ({@see DiasUteisDaUf}). Úteis quando o teor diz "úteis" ou não diz nada (CPC art. 219); corridos só quando diz
 *   "corridos", e o fim em dia não útil vai ao próximo útil (CPC art. 224, § 1º). Prazo em horas não gera nada.
 * - Audiência: só quando a frase designa a audiência e o teor traz a data escrita (dd/mm/aaaa ou por extenso), lida
 *   pelo {@see LeitorDeDatas}. Sem data no teor, nada.
 *
 * Não conta feriado municipal, suspensão do tribunal, prazo em dobro nem intimação fora do DJEN: por isso a tela diz
 * "prazo calculado, confira".
 */
final class CalculadoraDoPrazoDoPush
{
    public const TITULO           = 'Prazo do Push';
    public const TITULO_AUDIENCIA = 'Audiência do Push';

    /** O destinatário do extrator, como vai no título gravado. */
    private const DESTINATARIOS = [
        'partes'   => 'partes',
        'autor'    => 'parte autora',
        'reu'      => 'parte ré',
        'perito'   => 'perito',
        'cartorio' => 'cartório',
    ];

    /** A data escrita na frase (dd/mm[/aaaa] ou "20 de novembro"), e não "hoje" ou "amanhã". */
    private const DATA_ESCRITA = '/\d{1,2}\s*[\/.-]\s*\d{1,2}|\d{1,2}\s*(?:de\s+)?(?:jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)/u';

    public function __construct(
        private readonly DeterminacoesDoJuizo $determinacoes,
        private readonly DiasUteisNacionais $diasNacionais,
    ) {
    }

    /**
     * @return list<array{tipo: string, titulo: string, data: \DateTimeImmutable, hora: ?string, estimado: bool}>
     */
    public function daPublicacao(TeorDePublicacaoInput $teor, DiasUteisDaUf $calendario): array
    {
        if ($teor->data === null) {
            return [];
        }

        $determinacoes = $this->determinacoes->determinacoesDoTeor($teor);
        $saida = [];
        foreach ($determinacoes as $d) {
            if ($d->prazoQuantidade === null || $d->prazoUnidade !== 'dias') {
                continue;
            }
            $saida[] = [
                'tipo'     => PastaPrazo::TIPO_PRAZO,
                'titulo'   => self::titulo($d),
                'data'     => $this->vencimento($teor->data, $d->prazoQuantidade, $d->prazoContagem === 'corridos', $calendario),
                'hora'     => null,
                'estimado' => true,
            ];
        }

        return [...$saida, ...self::audiencias($teor, $teor->data, $determinacoes)];
    }

    /** Último dia do prazo de `$dias` dias contado da publicação no DJEN da disponibilização. */
    public function vencimento(\DateTimeImmutable $disponibilizacao, int $dias, bool $corridos, DiasUteisDaUf $calendario): \DateTimeImmutable
    {
        $publicacao = $this->diasNacionais->publicacaoDe($disponibilizacao);
        $dia = $calendario->proximoUtil($publicacao->modify('+1 day'));
        if ($corridos) {
            return $calendario->proximoUtil($dia->modify('+' . ($dias - 1) . ' days'));
        }

        for ($contados = 1; $contados < $dias;) {
            $dia = $dia->modify('+1 day');
            if ($calendario->ehUtil($dia)) {
                ++$contados;
            }
        }

        return $dia;
    }

    /** "Prazo do Push: Réplica (parte autora)" com ato e destinatário; sem destinatário, "Prazo do Push". */
    private static function titulo(DeterminacaoDoJuizoOutput $d): string
    {
        $destinatario = self::DESTINATARIOS[$d->destinatario ?? ''] ?? null;

        return $destinatario === null ? self::TITULO : self::TITULO . ': ' . $d->ato . ' (' . $destinatario . ')';
    }

    /**
     * @param list<DeterminacaoDoJuizoOutput> $determinacoes
     *
     * @return list<array{tipo: string, titulo: string, data: \DateTimeImmutable, hora: ?string, estimado: bool}>
     */
    private static function audiencias(TeorDePublicacaoInput $teor, \DateTimeImmutable $disponibilizacao, array $determinacoes): array
    {
        $designada = array_filter($determinacoes, static fn (DeterminacaoDoJuizoOutput $d): bool => $d->ato === 'Audiência');
        if ($designada === []) {
            return [];
        }

        $saida = [];
        foreach (LeitorDeDatas::ler(LeitorDeDatas::textoDoHtml($teor->texto), $disponibilizacao) as $lida) {
            if ($lida['tipo'] !== PastaPrazo::TIPO_AUDIENCIA || $lida['estimado']
                || preg_match(self::DATA_ESCRITA, SugestorDeDocumentos::normalizar($lida['frase'])) !== 1) {
                continue;
            }
            $saida[$lida['data'] . $lida['hora']] = [
                'tipo'     => PastaPrazo::TIPO_AUDIENCIA,
                'titulo'   => self::TITULO_AUDIENCIA,
                'data'     => new \DateTimeImmutable($lida['data']),
                'hora'     => $lida['hora'] !== '' ? $lida['hora'] : null,
                'estimado' => false,
            ];
        }

        return array_values($saida);
    }
}
