<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Enum\Situacao;

/**
 * Nível de importância da demanda, de 0 a 3, com os motivos para a dica do botão (`impDe()` do
 * desenho, spec VS 18.10.26 §3.2). A escolha manual do usuário vence o cálculo.
 */
final class CalculadoraDeImportancia
{
    private const AGUARDANDO_ACAO = [Situacao::Nova, Situacao::Info, Situacao::Proposta, Situacao::Validacao];

    /** @return array{nivel: int, motivos: list<string>} */
    public function calcular(DemandaExterna $d, int $naoLidas, ?bool $escolha, \DateTimeImmutable $hoje): array
    {
        if ($escolha !== null) {
            return $escolha
                ? ['nivel' => 3, 'motivos' => ['você marcou como importante']]
                : ['nivel' => 0, 'motivos' => ['você marcou como não importante']];
        }

        $fechada = $d->getSituacao()->ehFechada();
        $pontos = [];
        $this->urgencia($d, $fechada, $pontos);
        $this->prazo($d, $fechada, $hoje, $pontos);
        $this->valor($d, $pontos);
        if ($naoLidas > 0) {
            $pontos[$naoLidas . ' mensagem(ns) sem ler'] = 1;
        }
        if (in_array($d->getSituacao(), self::AGUARDANDO_ACAO, true)) {
            $pontos['aguardando ação'] = 1;
        }

        $soma = array_sum($pontos);
        $nivel = match (true) {
            $soma >= 6 => 3,
            $soma >= 4 => 2,
            $soma >= 2 => 1,
            default => 0,
        };

        return ['nivel' => $fechada ? min($nivel, 1) : $nivel, 'motivos' => array_keys($pontos)];
    }

    /** @param array<string, int> $pontos */
    private function urgencia(DemandaExterna $d, bool $fechada, array &$pontos): void
    {
        if ($d->isUrgente() && !$fechada) {
            $pontos['marcada como urgente'] = 3;
        }
        $prioridade = $d->getPrioridade();
        if ($prioridade === 'Urgente' || $prioridade === 'Alta') {
            $pontos['prioridade ' . mb_strtolower($prioridade)] = $prioridade === 'Urgente' ? 3 : 2;
        }
    }

    /** @param array<string, int> $pontos */
    private function prazo(DemandaExterna $d, bool $fechada, \DateTimeImmutable $hoje, array &$pontos): void
    {
        $prazo = $d->getPrazoFinal();
        if ($fechada || $prazo === null) {
            return;
        }
        $dias = (int) $hoje->setTime(12, 0)->diff($prazo->setTime(12, 0))->format('%r%a');
        match (true) {
            $dias <= 1 => $pontos[$dias < 0 ? 'prazo vencido' : 'prazo em até 1 dia'] = 3,
            $dias <= 3 => $pontos['prazo em até 3 dias'] = 2,
            $dias <= 7 => $pontos['prazo na semana'] = 1,
            default => null,
        };
    }

    /** @param array<string, int> $pontos */
    private function valor(DemandaExterna $d, array &$pontos): void
    {
        $propostas = $d->getPropostas();
        $ultima = end($propostas);
        $valor = $ultima instanceof PropostaDemanda ? (float) $ultima->getValor() : 0.0;
        match (true) {
            $valor >= 5000 => $pontos['valor acima de R$ 5 mil'] = 2,
            $valor >= 1000 => $pontos['valor acima de R$ 1 mil'] = 1,
            default => null,
        };
    }
}
