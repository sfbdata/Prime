<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\ProcessoAcompanhado;
use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Processo\Repository\ProcessoRepository;

/**
 * "Excluir processo" da aba "Processos da OAB" (desenho 11, spec `docs/specs/push-excluir-processo.md`): o processo
 * sai do acompanhamento, das pastas em que está e do Relatório. As publicações já recebidas e as anotações ficam.
 * Quem não pode editar alguma das pastas não exclui: nada é gravado pela metade.
 */
final class ExcluirProcessoDoPushUseCase
{
    public function __construct(
        private readonly ProcessoRepository $processos,
        private readonly ProcessoAcompanhadoRepository $acompanhados,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acesso,
        private readonly DesvincularProcessoUseCase $desvincular,
    ) {
    }

    /** @throws AcompanhamentoInvalidoException */
    public function executar(Tenant $tenant, User $usuario, string $numero): void
    {
        $digitos = (string) preg_replace('/\D/', '', $numero);
        if (strlen($digitos) !== 20) {
            throw new AcompanhamentoInvalidoException('Informe o número completo do processo (20 dígitos, padrão CNJ).');
        }

        $processo = $this->processos->findByNumeroProcessoDoTenant($digitos, $tenant);
        $pastasDoProcesso = [];
        if ($processo !== null) {
            foreach ($this->pastas->vinculosDosProcessos([(int) $processo->getId()], $tenant) as $vinculo) {
                $pasta = $vinculo->getPasta();
                if ($pasta->getExcluidaEm() !== null) {
                    continue;
                }
                if (!$this->acesso->podeEditar($usuario, $pasta)) {
                    throw new AcompanhamentoInvalidoException(sprintf('Você não tem permissão para tirar o processo da Pasta %s. Nada foi excluído.', $pasta->getNup() ?? '#' . $pasta->getId()));
                }
                $pastasDoProcesso[] = $pasta;
            }
        }

        $agora = new \DateTimeImmutable();
        foreach ($pastasDoProcesso as $pasta) {
            $this->desvincular->executar($pasta, $processo, $usuario);
        }
        $processo?->pausarMonitoramento($usuario, $agora);

        $linha = $this->acompanhados->findOneDoTenantPorNumero($tenant, $digitos)
            ?? new ProcessoAcompanhado($tenant, $digitos, false, $usuario);
        $linha->excluir($agora);
        $this->acompanhados->salvar($linha);
    }
}
