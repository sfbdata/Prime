<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;

/**
 * Importância da vaga, 0 a 3, com os motivos da dica (`impV`, desenho 06 l.1037). A escolha manual
 * vence. A compatibilidade com o currículo de quem lê (+2 a partir de 70%) é do externo; as
 * candidaturas recebidas para analisar (até +3) são do interno.
 */
final class CalculadoraDeImportanciaDaVaga
{
    /** @return array{nivel: int, motivos: list<string>} */
    public function calcular(Vaga $v, ?bool $escolha, bool $novaDesdeAUltimaVisita, \DateTimeImmutable $agora, ?int $compat = null, int $recebidas = 0): array
    {
        if ($escolha !== null) {
            return $escolha
                ? ['nivel' => 3, 'motivos' => ['você marcou como importante']]
                : ['nivel' => 0, 'motivos' => ['você marcou como não importante']];
        }

        $aberta = $v->situacaoEm($agora) === SituacaoVaga::Aberta;
        $pontos = array_filter([
            'oferta ao vivo' => $v->isOferta() && $aberta ? 3 : 0,
            'inscrições terminam em até 3 dias' => $aberta && $this->prazoPerto($v, $agora) ? 2 : 0,
            $compat . '% compatível com você' => $compat !== null && $compat >= CompatibilidadeDaVaga::ALTA ? 2 : 0,
            $recebidas . ' candidatura(s) para analisar' => min(3, $recebidas),
            'publicada desde sua última visita' => $novaDesdeAUltimaVisita ? 1 : 0,
            'remuneração informada' => $v->getRemuneracao() !== '' ? 1 : 0,
        ]);

        $soma = $aberta ? array_sum($pontos) : min(array_sum($pontos), 1);
        $nivel = match (true) {
            $soma >= 5 => 3,
            $soma >= 3 => 2,
            $soma >= 1 => 1,
            default => 0,
        };

        return ['nivel' => $nivel, 'motivos' => array_map('strval', array_keys($pontos))];
    }

    private function prazoPerto(Vaga $v, \DateTimeImmutable $agora): bool
    {
        $prazo = $v->getInscricoesAte();
        if ($prazo === null) {
            return false;
        }
        $dias = (int) $agora->setTime(12, 0)->diff($prazo->setTime(12, 0))->format('%r%a');

        return $dias >= 0 && $dias <= 3;
    }
}
