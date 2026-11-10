<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;

/**
 * Abona justificativas já conferidas como deste escritório, de quem tem vínculo ativo nele, e que quem analisa pode
 * analisar (P43 a). É a regra do abono da ficha do colaborador: a releitura, a situação "em análise", a batida do
 * esquecimento, o horário futuro, a gravação, a trilha e o aviso à pessoa ficam do lado de quem implementa.
 *
 * Serve ao "Abonar selecionadas" da aba Equipe (`AbonarJustificativasDaEquipeUseCase`, spec ponto-desenho-0811 E5).
 * O desenho (L818) separa as duas: a própria de quem analisa é analisada sozinha, com o evento dela na trilha; as da
 * equipe são abonadas de uma vez, com UM evento "Abono em lote" para todas.
 */
interface AbonoDeJustificativa
{
    /** A própria de quem analisa, sozinha. Null quando abonou; senão, o motivo de não ter abonado, para a tela. */
    public function abonar(JustificativaPonto $justificativa, Tenant $escritorio, User $analista): ?string;

    /**
     * As da equipe, de uma vez: cada uma pela regra do abono da ficha, e as abonadas entram na trilha num evento
     * "Abono em lote" só, gravado junto com os abonos. A recusa de uma não desfaz as outras.
     *
     * @param list<JustificativaPonto> $daEquipe
     *
     * @return array<int, string> o motivo de cada uma que não foi abonada, pelo id; as que não estão aqui foram abonadas
     */
    public function abonarDaEquipe(array $daEquipe, Tenant $escritorio, User $analista): array;
}
