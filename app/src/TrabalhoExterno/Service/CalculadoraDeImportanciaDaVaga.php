<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;

/**
 * Importância da vaga, 0 a 3, com os motivos da dica (`impV`, desenho 06 l.1037). A escolha manual
 * vence. A compatibilidade com o currículo (+2) entra na F3 e as candidaturas para analisar (até +3)
 * na F2b; o resto é do desenho.
 */
final class CalculadoraDeImportanciaDaVaga
{
    /** @return array{nivel: int, motivos: list<string>} */
    public function calcular(Vaga $v, ?bool $escolha, bool $novaDesdeAUltimaVisita, \DateTimeImmutable $agora): array
    {
        if ($escolha !== null) {
            return $escolha
                ? ['nivel' => 3, 'motivos' => ['você marcou como importante']]
                : ['nivel' => 0, 'motivos' => ['você marcou como não importante']];
        }

        $aberta = $v->situacaoEm($agora) === SituacaoVaga::Aberta;
        $pontos = [];
        if ($v->isOferta() && $aberta) {
            $pontos['oferta ao vivo'] = 3;
        }
        $prazo = $v->getInscricoesAte();
        if ($aberta && $prazo !== null) {
            $dias = (int) $agora->setTime(12, 0)->diff($prazo->setTime(12, 0))->format('%r%a');
            if ($dias >= 0 && $dias <= 3) {
                $pontos['inscrições terminam em até 3 dias'] = 2;
            }
        }
        if ($novaDesdeAUltimaVisita) {
            $pontos['publicada desde sua última visita'] = 1;
        }
        if ($v->getRemuneracao() !== '') {
            $pontos['remuneração informada'] = 1;
        }

        $soma = $aberta ? array_sum($pontos) : min(array_sum($pontos), 1);
        $nivel = match (true) {
            $soma >= 5 => 3,
            $soma >= 3 => 2,
            $soma >= 1 => 1,
            default => 0,
        };

        return ['nivel' => $nivel, 'motivos' => array_keys($pontos)];
    }
}
