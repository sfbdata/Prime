<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\ZeramentoOutput;
use App\Dashboard\DTO\ZerarColunaInput;
use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Exception\ZeramentoNaoPermitidoException;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\Zeramento\ColaboradorDoEscritorio;
use App\Dashboard\Zeramento\ColunasZeraveis;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * "Zerar" de uma coluna da tabela Desempenho (DASH-014; desenho "01 - Dashboard 1.2.2", `zerarCol` e
 * `dzConfirmar`), para uma pessoa ou para a equipe toda.
 *
 * Não apaga nada: grava um ponto de corte (`contar_desde` = agora) e quem fez. A partir dele a
 * coluna conta só o que veio depois (CortesDoDashboard), até alguém desfazer.
 *
 * Só zera quem gere o escritório: o Master ou um primário ativo (RegraDosPrimarios::
 * podeGerirPrimarios, a mesma régua de quem dá e tira o perfil de primário); os outros recebem
 * ZeramentoNaoPermitidoException (403) e na tela veem "Pedir para zerar". A pessoa tem de ser
 * colaboradora ativa do escritório da sessão (404); a coluna, uma da tabela (400).
 */
final class ZerarColunaDoDashboardUseCase
{
    public function __construct(
        private readonly DashboardZeramentoRepository $repository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly ColaboradorDoEscritorio $colaboradores,
    ) {
    }

    public function executar(ZerarColunaInput $input): ZeramentoOutput
    {
        $this->exigirQuemGereOEscritorio($input);
        ColunasZeraveis::exigir($input->coluna);
        $pessoa = $this->colaboradores->buscar($input->tenant, $input->pessoaId);

        $zeramento = new DashboardZeramento($input->tenant, $input->coluna, $pessoa, $input->agora, $input->autor, $input->agora);
        $this->repository->salvar($zeramento, flush: true);

        return ZeramentoOutput::fromEntity($zeramento);
    }

    private function exigirQuemGereOEscritorio(ZerarColunaInput $input): void
    {
        if (!$this->regraDosPrimarios->podeGerirPrimarios($input->autor, $input->tenant)) {
            throw new ZeramentoNaoPermitidoException('Só o usuário primário pode zerar dados estatísticos.');
        }
    }
}
