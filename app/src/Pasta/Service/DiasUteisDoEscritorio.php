<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Agenda\DTO\DataDoSistemaOutput as D;
use App\Agenda\Service\CalendarioDoSistema;

/**
 * Dia útil para a estimativa de prazo, pelo calendário do sistema da Agenda e a UF do escritório
 * (docs/specs/prazo-feriado-calendario.md, seção 2).
 *
 * Não é útil: sábado, domingo, feriado nacional do tipo feriado (inclui a Sexta-feira Santa), feriado
 * estadual da UF e o recesso de 20/12 a 20/01 (CPC, art. 220). Continuam úteis, de propósito: ponto
 * facultativo, datas de tribunal, eleições e comemorativas (contar esses dias empurraria a estimativa para
 * depois do prazo real). Os dias não úteis de cada ano e UF ficam guardados em memória.
 */
final class DiasUteisDoEscritorio
{
    /** @var array<string, array<string, true>> "ano|UF" => datas Y-m-d não úteis */
    private array $naoUteis = [];

    public function __construct(
        private readonly CalendarioDoSistema $calendario,
    ) {
    }

    public function ehUtil(\DateTimeImmutable $dia, ?string $uf): bool
    {
        $semana = (int) $dia->format('w');
        if ($semana === 0 || $semana === 6) {
            return false;
        }

        return !isset($this->naoUteisDoAno((int) $dia->format('Y'), self::uf($uf))[$dia->format('Y-m-d')]);
    }

    /** O próprio dia, se for útil; senão o primeiro útil depois dele. */
    public function proximoUtil(\DateTimeImmutable $dia, ?string $uf): \DateTimeImmutable
    {
        $d = $dia->setTime(0, 0);
        while (!$this->ehUtil($d, $uf)) {
            $d = $d->modify('+1 day');
        }

        return $d;
    }

    /**
     * O N-ésimo dia útil DEPOIS de `$inicio` (o próprio dia não conta), como o prazo padrão do despacho da meta
     * (desenho 04B de 22/10, L118: 5 dias úteis a partir de hoje). Zero devolve o próprio dia.
     */
    public function somarUteis(\DateTimeImmutable $inicio, int $dias, ?string $uf): \DateTimeImmutable
    {
        $d = $inicio->setTime(0, 0);
        for ($contados = 0; $contados < $dias;) {
            $d = $d->modify('+1 day');
            if ($this->ehUtil($d, $uf)) {
                ++$contados;
            }
        }

        return $d;
    }

    /**
     * Os dias NÃO úteis de `$de` a `$ate`, inclusive, em `Y-m-d` (fins de semana incluídos): a lista que a tela
     * usa para recontar os dias úteis no navegador pela mesma regra (C12).
     *
     * @return list<string>
     */
    public function naoUteisEntre(\DateTimeImmutable $de, \DateTimeImmutable $ate, ?string $uf): array
    {
        $lista = [];
        $fim = $ate->setTime(0, 0);
        for ($d = $de->setTime(0, 0); $d <= $fim; $d = $d->modify('+1 day')) {
            if (!$this->ehUtil($d, $uf)) {
                $lista[] = $d->format('Y-m-d');
            }
        }

        return $lista;
    }

    /** Fixa a UF, para passar ao {@see LeitorDeDatas}. */
    public function daUf(?string $uf): DiasUteisDaUf
    {
        return new DiasUteisDaUf($this, $uf);
    }

    /** @return array<string, true> */
    private function naoUteisDoAno(int $ano, string $uf): array
    {
        $chave = $ano . '|' . $uf;
        if (!isset($this->naoUteis[$chave])) {
            $datas = $this->calendario->doPeriodo(
                new \DateTimeImmutable(sprintf('%04d-01-01', $ano)),
                new \DateTimeImmutable(sprintf('%04d-12-31', $ano)),
                $uf !== '' ? [$uf] : [],
            );
            $this->naoUteis[$chave] = [];
            foreach ($datas as $d) {
                if (self::contaComoNaoUtil($d, $uf)) {
                    $this->naoUteis[$chave][$d->data] = true;
                }
            }
        }

        return $this->naoUteis[$chave];
    }

    private static function contaComoNaoUtil(D $d, string $uf): bool
    {
        return ($d->calendario === D::CAL_NACIONAL && $d->tipo === D::TIPO_FERIADO)
            || ($d->calendario === D::CAL_ESTADUAL && $d->uf === $uf)
            || $d->tipo === D::TIPO_FORENSE;
    }

    private static function uf(?string $uf): string
    {
        return strtoupper(trim((string) $uf));
    }
}
