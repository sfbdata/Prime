<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * A pessoa tem um turno aberto NESTE escritório: a última batida dela aqui, nas últimas 16 horas até `$agora`, é entrada
 * ou retorno (spec seguranca-b-jornada §3.3). Até 23/10 só a saída e a ida ao repouso que fechavam esse turno passavam
 * livres da chave e do choque; agora toda saída e ida ao repouso passam (decisão do dono, spec
 * `ponto-esquecimento-nao-trava.md` R1), e o turno aberto decide só o aviso da entrada que faltou.
 */
final class TurnoAbertoNoEscritorio
{
    /**
     * A janela que procura a batida que abriu o turno. Cobre o turno noturno (entrada às 22:00, saída às 06:00 do dia
     * seguinte) e qualquer jornada de até 16 horas; uma entrada mais velha que isso é esquecimento, não turno corrente.
     */
    public const JANELA_DO_TURNO_HORAS = 16;

    /** O aviso da saída (ou ida ao repouso) solta, sem turno aberto e sem entrada no dia neste escritório (R1). */
    public const AVISO_SEM_ENTRADA = 'Faltou a entrada neste dia. Envie o registro de esquecimento.';

    /** O rótulo do link do aviso: abre o modal de esquecimento da entrada do dia na tela do ponto. */
    public const ROTULO_ENVIAR = 'Enviar registro de esquecimento';

    public function __construct(
        private readonly RegistroPontoRepository $registros,
    ) {
    }

    public function estaAberto(User $user, Tenant $tenant, \DateTimeImmutable $agora): bool
    {
        $ultima = $this->registros->ultimaBatidaNoTenant($user, $tenant, $agora->modify(sprintf('-%d hours', self::JANELA_DO_TURNO_HORAS)), $agora);

        return in_array($ultima?->getTipo(), [RegistroPonto::TIPO_ENTRADA, RegistroPonto::TIPO_RETORNO], true);
    }
}
