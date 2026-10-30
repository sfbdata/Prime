<?php

declare(strict_types=1);

namespace App\Calculo\Service;

use App\Calculo\DTO\CalculoInput;
use App\Calculo\DTO\ResultadoDoCalculo;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tudo o que a tela e o PDF mostram do cálculo, já em texto pt-BR: valores arredondados na exibição,
 * datas dd/mm/aaaa, critérios e avisos. Os números vêm do `ResultadoDoCalculo` sem tocar neles.
 */
final class ApresentacaoDoCalculo
{
    public function __construct(private readonly TranslatorInterface $tradutor)
    {
    }

    public static function dinheiro(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    public static function numero(float $v, int $casas): string
    {
        return number_format($v, $casas, ',', '');
    }

    public static function data(string $iso): string
    {
        return $iso === '' ? '' : implode('/', array_reverse(explode('-', $iso)));
    }

    /** AAAA-MM-DD vira MM/AAAA. */
    public static function mesAno(string $iso): string
    {
        return $iso === '' ? '' : substr($iso, 5, 2) . '/' . substr($iso, 0, 4);
    }

    public function criterioDeIndice(CalculoInput $in): string
    {
        return $this->tradutor->trans('calculo.criterio.' . match ($in->indice) {
            'nenhum' => 'indice_nenhum',
            'inpc' => 'indice_inpc',
            default => 'indice_oficial',
        });
    }

    /** Frase dos juros seguida da frase de incidência (é o texto do cartão "Juros" do PDF). */
    public function criterioDeJuros(CalculoInput $in): string
    {
        $juros = match ($in->juros) {
            'nenhum' => $this->tradutor->trans('calculo.criterio.juros_nenhum'),
            'legal' => $this->tradutor->trans('calculo.criterio.juros_legal'),
            default => $this->tradutor->trans('calculo.criterio.juros_simples', ['taxa' => $in->juros === '1' ? '1%' : '0,5%']),
        };
        $data = self::data($in->dataJuros);
        $inicio = match ($in->incidencia) {
            'fixa' => $this->tradutor->trans('calculo.criterio.inicio_fixa', ['data' => $data]),
            'citacao' => $this->tradutor->trans('calculo.criterio.inicio_citacao', ['data' => $data]),
            default => $this->tradutor->trans('calculo.criterio.inicio_valores'),
        };

        return $juros . ' ' . $inicio;
    }

    public function criterios(CalculoInput $in): string
    {
        return $this->criterioDeIndice($in) . ' ' . $this->criterioDeJuros($in);
    }

    /** Linha "Data:" do cartão Juros do PDF. */
    public function dataDosJuros(CalculoInput $in): string
    {
        $data = self::data($in->dataJuros);

        return $this->tradutor->trans('calculo.criterio.' . match ($in->incidencia) {
            'fixa' => 'data_fixa',
            'citacao' => 'data_citacao',
            default => 'data_valores',
        }, ['data' => $data]);
    }

    /**
     * Linhas do agrupamento. `nivel`: 0 comum, 1 destaque (Débito), 2 total final. Só entra o que existe.
     *
     * @return list<array{rotulo: string, valor: float, nivel: int}>
     */
    public function resumo(CalculoInput $in, ResultadoDoCalculo $r): array
    {
        $t = fn (string $chave, array $p = []): string => $this->tradutor->trans('calculo.resumo.' . $chave, $p);
        $linhas = [['rotulo' => $t('subtotal'), 'valor' => $r->subtotal, 'nivel' => 0]];
        if ($r->multa !== 0.0) {
            $linhas[] = ['rotulo' => $t('multa', ['pct' => $in->multa]), 'valor' => $r->multa, 'nivel' => 0];
        }
        if ($r->honorarios !== 0.0) {
            $linhas[] = ['rotulo' => $t('honorarios', ['pct' => $in->honorarios]), 'valor' => $r->honorarios, 'nivel' => 0];
        }
        if ($r->abatimentosCorrigidos !== 0.0) {
            $linhas[] = ['rotulo' => $t('abatimentos'), 'valor' => -$r->abatimentosCorrigidos, 'nivel' => 0];
        }
        $linhas[] = ['rotulo' => $t('debito'), 'valor' => $r->debito, 'nivel' => 1];
        if ($r->multa523 !== 0.0) {
            $linhas[] = ['rotulo' => $t('multa_523'), 'valor' => $r->multa523, 'nivel' => 0];
        }
        if ($r->honorarios523 !== 0.0) {
            $linhas[] = ['rotulo' => $t('honorarios_523'), 'valor' => $r->honorarios523, 'nivel' => 0];
        }
        $linhas[] = ['rotulo' => $t('total'), 'valor' => $r->total, 'nivel' => 2];

        return $linhas;
    }

    /** Aviso de parcelas projetadas ('' se não há). */
    public function avisoDeProjecao(ResultadoDoCalculo $r): string
    {
        return $r->parcelasProjetadas > 0 ? $this->tradutor->trans('calculo.aviso.projetadas', ['n' => $r->parcelasProjetadas]) : '';
    }

    /** Aviso de meses sem índice ('' se não há). */
    public function avisoDeMesesSemIndice(ResultadoDoCalculo $r): string
    {
        return $r->mesesSemIndice > 0 ? $this->tradutor->trans('calculo.aviso.sem_indice', ['n' => $r->mesesSemIndice]) : '';
    }

    /**
     * Resultado no formato que a tela consome (JSON).
     *
     * @return array<string, mixed>
     */
    public function paraTela(CalculoInput $in, ResultadoDoCalculo $r): array
    {
        $linhas = array_map(fn ($l): array => [
            'd' => self::data($l->data),
            't' => $l->descricao,
            'v' => self::dinheiro($l->valor),
            'f' => self::numero($l->fator, 6),
            'c' => self::dinheiro($l->corrigido),
            'jp' => self::numero($l->jurosPct, 4) . '%',
            'j' => self::dinheiro($l->juros),
            'tot' => self::dinheiro($l->total),
            'proj' => $l->projecao($r->ate),
        ], $r->linhas);

        return [
            'ate' => self::data($r->ate),
            'linhas' => $linhas,
            'resumo' => array_map(static fn (array $x): array => ['r' => $x['rotulo'], 'v' => 'R$ ' . self::dinheiro($x['valor']), 'k' => $x['nivel']], $this->resumo($in, $r)),
            'criterios' => $this->criterios($in),
            'aviso' => $this->avisoDeProjecao($r),
            'atencao' => $this->avisoDeMesesSemIndice($r),
            'projTag' => $this->tradutor->trans('calculo.pdf.projecao'),
        ];
    }
}
