<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;

/**
 * Importância do currículo, 0 a 3, com os motivos da dica (`impC`, desenho 06 l.1038). A escolha
 * manual vence. As candidaturas (+2) entram na F2b; até lá são zero.
 */
final class CalculadoraDeImportanciaDoCurriculo
{
    /** @return array{nivel: int, motivos: list<string>} */
    public function calcular(Curriculo $cv, ?bool $escolha, \DateTimeImmutable $agora, int $candidaturas = 0): array
    {
        if ($escolha !== null) {
            return $escolha
                ? ['nivel' => 3, 'motivos' => ['você marcou como importante']]
                : ['nivel' => 0, 'motivos' => ['você marcou como não importante']];
        }

        $pontos = array_filter([
            $candidaturas . ' candidatura(s)' => $candidaturas > 0 ? 2 : 0,
            'ainda não analisado' => $cv->getStatus() === StatusCurriculo::Novo ? 1 : 0,
            'favorito' => $cv->getStatus() === StatusCurriculo::Favorito ? 2 : 0,
            'com OAB' => $cv->getSituacao()->temOab() ? 1 : 0,
            'currículo anexado' => $cv->temArquivo(TipoArquivoDoCurriculo::Curriculo) ? 1 : 0,
            'recebido nesta semana' => $cv->getRecebidoEm() > $agora->modify('-7 days') ? 1 : 0,
        ]);
        $soma = array_sum($pontos);

        return [
            'nivel' => match (true) {
                $soma >= 5 => 3,
                $soma >= 3 => 2,
                $soma >= 1 => 1,
                default => 0,
            },
            'motivos' => array_map('strval', array_keys($pontos)),
        ];
    }
}
