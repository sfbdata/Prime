<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\DeterminacaoDoJuizoOutput;
use App\Pasta\DTO\TeorDePublicacaoInput;
use App\Pasta\Entity\PastaPrazo;

/**
 * Os prazos e audiências de UMA publicação do Push, sem banco (docs/specs/prazo-do-push.md §2).
 *
 * - Prazo: só a determinação com prazo explícito em DIAS ({@see DeterminacoesDoJuizo}, nada presumido). O termo
 *   inicial é a publicação no DJEN (o 1º dia útil depois da disponibilização, Lei 11.419/2006, art. 4º, § 3º) e a
 *   contagem começa no dia útil seguinte; os dois pelo MESMO calendário, o da UF do escritório ({@see DiasUteisDaUf}).
 *   Úteis quando o teor diz "úteis" ou não diz nada (CPC art. 219); corridos só quando diz "corridos", sem contar o
 *   recesso de 20/12 a 20/01 (CPC art. 220), e o fim em dia não útil vai ao próximo útil (CPC art. 224, § 1º).
 *   Prazo em horas não gera nada. A frase que NARRA o passado ("intimada para emendar no prazo de 15 dias, quedou-se
 *   inerte") também não: o prazo dela já correu.
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

    /** Narração do passado: o particípio da comunicação junto com a inércia da parte. */
    private const NARRACAO_PARTICIPIO = '/\b(?:intimad|citad|notificad)[ao]s?\b/u';
    private const NARRACAO_INERCIA    = '/quedou|deixou (?:transcorrer|decorrer)|permaneceu inerte|nao se manifestou|decorreu (?:o prazo|in albis)/u';

    public function __construct(
        private readonly DeterminacoesDoJuizo $determinacoes,
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
            if ($d->prazoQuantidade === null || $d->prazoUnidade !== 'dias' || self::narraOPassado($d)) {
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

    /**
     * Último dia do prazo de `$dias` dias contado da publicação no DJEN da disponibilização. Em úteis conta só o dia
     * útil da UF; em corridos conta todo dia fora do recesso e, se o fim cair em dia não útil, vai ao próximo útil.
     */
    public function vencimento(\DateTimeImmutable $disponibilizacao, int $dias, bool $corridos, DiasUteisDaUf $calendario): \DateTimeImmutable
    {
        $publicacao = $calendario->proximoUtil($disponibilizacao->setTime(0, 0)->modify('+1 day'));
        $dia = $calendario->proximoUtil($publicacao->modify('+1 day'));
        for ($contados = 1; $contados < $dias;) {
            $dia = $dia->modify('+1 day');
            if ($corridos ? !self::noRecesso($dia) : $calendario->ehUtil($dia)) {
                ++$contados;
            }
        }

        return $corridos ? $calendario->proximoUtil($dia) : $dia;
    }

    /** CPC art. 220: de 20/12 a 20/01 o prazo não corre, nem o de dias corridos. */
    private static function noRecesso(\DateTimeImmutable $dia): bool
    {
        $mesDia = $dia->format('md');

        return $mesDia >= '1220' || $mesDia <= '0120';
    }

    /** "A parte autora, intimada para emendar no prazo de 15 dias, quedou-se inerte": narra um prazo que já correu. */
    private static function narraOPassado(DeterminacaoDoJuizoOutput $d): bool
    {
        $frase = SugestorDeDocumentos::normalizar($d->trecho);

        return preg_match(self::NARRACAO_PARTICIPIO, $frase) === 1 && preg_match(self::NARRACAO_INERCIA, $frase) === 1;
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
