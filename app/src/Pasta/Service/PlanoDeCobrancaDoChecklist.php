<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\CobrancaDoDia;
use App\Pasta\DTO\PendenciaDoChecklist;

/**
 * Quem é cobrado hoje numa pasta (desenho 02, `ckCobPlano` e `cobrarChecklist`, dc 4278-4298 e 4349-4372).
 * Regra pura, sem banco nem relógio: recebe os pendentes e o dia, devolve as cobranças.
 *
 *  - peso: procuração ou contrato 3; hipossuficiência, identidade, CPF ou RG 2; o resto 1; +1 com 7 dias
 *    ou mais pendente. Urgente = peso 3 ou mais, ou previsão descumprida;
 *  - item com previsão no futuro (ou hoje) fica pausado;
 *  - só em dia útil: urgentes todo dia; dos não urgentes, um por dia na pasta, com 2 dias entre cobranças
 *    do mesmo item; teto de 2 cobranças por dia na pasta, contando as já enviadas hoje;
 *  - nível = cobranças anteriores + 1; a coordenação vai junto no 4º, 7º, 10º... e na previsão descumprida.
 */
final class PlanoDeCobrancaDoChecklist
{
    public const TETO_POR_DIA = 2;
    private const DIAS_ENTRE_COBRANCAS = 2;
    private const DIAS_PARA_SUBIR_PESO = 7;

    public static function peso(string $titulo): int
    {
        if (preg_match('/procura|contrato/iu', $titulo) === 1) {
            return 3;
        }

        return preg_match('/hipossufic|identidade|cpf|rg\b/iu', $titulo) === 1 ? 2 : 1;
    }

    /**
     * @param list<PendenciaDoChecklist> $pendentes
     *
     * @return list<CobrancaDoDia>
     */
    public function doDia(array $pendentes, \DateTimeImmutable $hoje, bool $diaUtil): array
    {
        if (!$diaUtil) {
            return [];
        }

        $dia = $hoje->format('Y-m-d');
        $ativos = $this->ordenar(array_values(array_filter($pendentes, static fn (PendenciaDoChecklist $p): bool => !self::pausado($p, $dia))), $hoje);
        $jaHoje = \count(array_filter($ativos, static fn (array $a): bool => $a['ultimo'] === $dia));

        $escolhidos = array_values(array_filter($ativos, static fn (array $a): bool => $a['urgente'] && $a['ultimo'] !== $dia));
        $normal = $this->proximoNaoUrgente($ativos, $hoje);
        if ($normal !== null) {
            $escolhidos[] = $normal;
        }

        return array_map(
            static fn (array $a): CobrancaDoDia => new CobrancaDoDia($a['p'], \count($a['p']->envios) + 1, $a['quebrou'], $a['dias']),
            \array_slice($escolhidos, 0, max(0, self::TETO_POR_DIA - $jaHoje)),
        );
    }

    /** No 4º aviso e a cada 3 depois, e em previsão descumprida (dc 4362). */
    public static function copiaCoordenacao(CobrancaDoDia $cobranca): bool
    {
        return $cobranca->quebrou || ($cobranca->nivel >= 4 && ($cobranca->nivel - 4) % 3 === 0);
    }

    private static function pausado(PendenciaDoChecklist $p, string $dia): bool
    {
        return $p->previsao !== null && $dia <= $p->previsao->format('Y-m-d');
    }

    /**
     * @param list<PendenciaDoChecklist> $pendentes
     *
     * @return list<array{p: PendenciaDoChecklist, dias: int, peso: int, urgente: bool, quebrou: bool, ultimo: string}>
     */
    private function ordenar(array $pendentes, \DateTimeImmutable $hoje): array
    {
        $dados = array_map(function (PendenciaDoChecklist $p) use ($hoje): array {
            $dias = max(0, (int) $p->desde->setTime(0, 0)->diff($hoje->setTime(0, 0))->format('%r%a'));
            $peso = self::peso($p->titulo) + ($dias >= self::DIAS_PARA_SUBIR_PESO ? 1 : 0);
            $quebrou = $p->previsao !== null && $hoje->format('Y-m-d') > $p->previsao->format('Y-m-d');

            return ['p' => $p, 'dias' => $dias, 'peso' => $peso, 'urgente' => $peso >= 3 || $quebrou, 'quebrou' => $quebrou, 'ultimo' => $p->envios === [] ? '' : $p->envios[\count($p->envios) - 1]];
        }, $pendentes);
        usort($dados, static fn (array $a, array $b): int => [$b['peso'], $a['ultimo']] <=> [$a['peso'], $b['ultimo']]);

        return $dados;
    }

    /**
     * Um não urgente por dia na pasta (se nenhum foi cobrado hoje), com intervalo de 2 dias por item.
     *
     * @param list<array{p: PendenciaDoChecklist, dias: int, peso: int, urgente: bool, quebrou: bool, ultimo: string}> $ativos
     *
     * @return array{p: PendenciaDoChecklist, dias: int, peso: int, urgente: bool, quebrou: bool, ultimo: string}|null
     */
    private function proximoNaoUrgente(array $ativos, \DateTimeImmutable $hoje): ?array
    {
        $dia = $hoje->format('Y-m-d');
        foreach ($ativos as $a) {
            if (!$a['urgente'] && $a['ultimo'] === $dia) {
                return null;
            }
        }
        foreach ($ativos as $a) {
            if ($a['urgente'] || $a['ultimo'] === $dia) {
                continue;
            }
            if ($a['ultimo'] === '' || (int) (new \DateTimeImmutable($a['ultimo']))->diff($hoje->setTime(0, 0))->format('%a') >= self::DIAS_ENTRE_COBRANCAS) {
                return $a;
            }
        }

        return null;
    }
}
