<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Agenda\DTO\DataDoSistemaOutput as D;
use App\Agenda\UseCase\ListarDatasDoSistemaUseCase;
use App\Entity\Tenant\Tenant;

/**
 * Dia útil para a cobrança do checklist: segunda a sexta (desenho 02, `ckCobPlano`) e fora dos feriados
 * nacionais, estaduais (UF do escritório) e dos cadastrados no Ponto. A fonte é a Agenda
 * (`ListarDatasDoSistemaUseCase`, a fonte única de datas do sistema), nunca o Ponto direto.
 */
final class DiaUtilDoEscritorio
{
    public function __construct(
        private readonly ListarDatasDoSistemaUseCase $datas,
    ) {
    }

    public function ehDiaUtil(Tenant $escritorio, \DateTimeImmutable $dia): bool
    {
        if ((int) $dia->format('N') >= 6) {
            return false;
        }

        $inicio = $dia->setTime(0, 0);
        foreach ($this->datas->executar($escritorio, null, $inicio, $inicio)['datas'] as $data) {
            if ($data->data === $inicio->format('Y-m-d') && self::folga($data)) {
                return false;
            }
        }

        return true;
    }

    private static function folga(D $data): bool
    {
        return $data->contaNoPonto || \in_array($data->tipo, [D::TIPO_FERIADO, D::TIPO_ESTADUAL], true);
    }
}
