<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\DesfazerZeramentosInput;
use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Exception\ZeramentoNaoPermitidoException;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\Zeramento\ColunasZeraveis;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * "Desfazer zeramentos" do menu da coluna (DASH-014; desenho: "Volta a contar desde o início"):
 * desfaz TODOS os zeramentos ativos da coluna no escritório da sessão, os da equipe e os de cada
 * pessoa. Nada é apagado: cada linha ganha `desfeito_em` e `desfeito_por`, e a contagem volta a ser
 * a do período inteiro.
 *
 * Mesma régua do zerar: só o Master ou um primário ativo (403 para os outros).
 */
final class DesfazerZeramentosDoDashboardUseCase
{
    public function __construct(
        private readonly DashboardZeramentoRepository $repository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    /** @return int quantos zeramentos foram desfeitos (0 quando a coluna não tinha nenhum) */
    public function executar(DesfazerZeramentosInput $input): int
    {
        if (!$this->regraDosPrimarios->podeGerirPrimarios($input->autor, $input->tenant)) {
            throw new ZeramentoNaoPermitidoException('Só o usuário primário pode desfazer zeramentos.');
        }
        ColunasZeraveis::exigir($input->coluna);

        $ativos = $this->ativosDaColuna($input);
        foreach ($ativos as $zeramento) {
            $zeramento->desfazer($input->autor, $input->agora);
        }
        if ($ativos !== []) {
            $this->repository->salvar($ativos[0], flush: true);
        }

        return count($ativos);
    }

    /** @return list<DashboardZeramento> */
    private function ativosDaColuna(DesfazerZeramentosInput $input): array
    {
        return array_values(array_filter(
            $this->repository->listarAtivos($input->tenant),
            static fn (DashboardZeramento $z): bool => $z->getColuna() === $input->coluna,
        ));
    }
}
