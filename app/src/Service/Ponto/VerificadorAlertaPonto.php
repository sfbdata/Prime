<?php

namespace App\Service\Ponto;

use App\Entity\Auth\User;
use App\Entity\Ponto\JornadaTenant;

class VerificadorAlertaPonto
{
    private const TOLERANCIA_MINUTOS = 5;

    public function __construct(
        private readonly JornadaResolver $jornadaResolver,
    ) {}

    /**
     * @return array{alertar: bool, mensagem: string, tipo: string, horario: string}
     */
    public function verificar(User $user, \DateTimeImmutable $agora, ?JornadaTenant $jornadaTenant = null): array
    {
        if (!$this->jornadaResolver->resolverAlertaHabilitado($user, $jornadaTenant)) {
            return ['alertar' => false, 'mensagem' => '', 'tipo' => '', 'horario' => ''];
        }

        $batidas = $this->jornadaResolver->resolverBatidasEsperadasHoje($user, $agora, $jornadaTenant);

        if (empty($batidas)) {
            return ['alertar' => false, 'mensagem' => '', 'tipo' => '', 'horario' => ''];
        }

        $agoraMin = (int) $agora->format('H') * 60 + (int) $agora->format('i');

        foreach ($batidas as $batida) {
            if ($batida['horario'] === null) {
                continue;
            }
            [$h, $m] = explode(':', $batida['horario']);
            if (abs($agoraMin - ((int) $h * 60 + (int) $m)) <= self::TOLERANCIA_MINUTOS) {
                return ['alertar' => true, ...$batida];
            }
        }

        return ['alertar' => false, 'mensagem' => '', 'tipo' => '', 'horario' => ''];
    }
}
