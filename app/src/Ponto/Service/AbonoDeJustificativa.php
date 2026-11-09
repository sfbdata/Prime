<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;

/**
 * Abona UMA justificativa já conferida como deste escritório, de quem tem vínculo ativo nele, e que quem analisa pode
 * analisar (P43 a). É a regra do abono da ficha do colaborador: a releitura, a situação "em análise", a batida do
 * esquecimento, o horário futuro, a gravação e o aviso à pessoa ficam do lado de quem implementa.
 *
 * Serve ao "Abonar selecionadas" da aba Equipe (`AbonarJustificativasDaEquipeUseCase`, spec ponto-desenho-0811 E5).
 */
interface AbonoDeJustificativa
{
    /** Null quando abonou; senão, o motivo de não ter abonado, para mostrar a quem analisa. */
    public function abonar(JustificativaPonto $justificativa, Tenant $escritorio, User $analista): ?string;
}
