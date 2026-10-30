<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\PropostaDeCadastro;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ChamadaDeFerramenta;

/**
 * Das chamadas de ferramenta que o modelo pediu, monta as PROPOSTAS (ainda não persistidas) que valem. Descarta a chamada
 * inválida ({@see InterpretadorDaChamadaDeCadastro}), a de ferramenta que a pessoa não pode usar ({@see GuardaDasPropostas}),
 * a segunda do mesmo tipo e tudo que passar de {@see self::MAXIMO_POR_RESPOSTA}. O modelo só propõe: nada aqui grava
 * Cliente ou Pasta. Nunca loga a chamada (leva dado pessoal).
 */
final class RegistradorDasPropostas
{
    /** Uma proposta por tipo por resposta, e no máximo duas: uma entrada hostil não vira uma fila de cartões. */
    public const MAXIMO_POR_RESPOSTA = 2;

    public function __construct(
        private readonly InterpretadorDaChamadaDeCadastro $interpretador,
        private readonly GuardaDasPropostas $guarda,
    ) {
    }

    /**
     * @param list<ChamadaDeFerramenta> $chamadas
     *
     * @return list<PropostaDeCadastro> as propostas válidas, na ordem em que o modelo as pediu
     */
    public function preparar(User $eu, Tenant $tenant, ConversaDeChat $conversa, array $chamadas, \DateTimeImmutable $agora): array
    {
        $propostas = [];
        $usados = [];
        foreach ($chamadas as $chamada) {
            if (\count($propostas) >= self::MAXIMO_POR_RESPOSTA) {
                break;
            }
            $lida = $this->interpretador->interpretar($chamada);
            if ($lida === null || isset($usados[$lida['tipo']->value]) || !$this->guarda->podePropor($lida['tipo'], $eu, $tenant)) {
                continue;
            }
            $usados[$lida['tipo']->value] = true;
            $propostas[] = new PropostaDeCadastro($tenant, $eu, $conversa, $lida['tipo'], $lida['dados'], $agora);
        }

        return $propostas;
    }
}
