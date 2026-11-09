<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;
use App\Ponto\UseCase\AnalisarJustificativaUseCase;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * O abono do "Abonar selecionadas" da aba Equipe (spec ponto-desenho-0811, E5) é o mesmo da ficha do colaborador:
 * `AnalisarJustificativaUseCase::abonarPorId` (E9), com a trava, a releitura, a batida do esquecimento, a trilha e o
 * aviso à pessoa. A recusa dele vira a frase que o lote mostra; cada justificativa grava na própria transação, então
 * uma recusa não desfaz as outras.
 */
#[AsAlias(id: AbonoDeJustificativa::class)]
final class AbonoPelaAnaliseDaJustificativa implements AbonoDeJustificativa
{
    public function __construct(private readonly AnalisarJustificativaUseCase $analise)
    {
    }

    public function abonar(JustificativaPonto $justificativa, Tenant $escritorio, User $analista): ?string
    {
        try {
            $this->analise->abonarPorId((int) $justificativa->getId(), $analista, $escritorio);
        } catch (AnaliseDeJustificativaRecusadaException $recusa) {
            return $recusa->getMessage();
        }

        return null;
    }
}
