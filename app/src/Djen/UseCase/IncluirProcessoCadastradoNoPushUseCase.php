<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;

/**
 * Leva um processo já cadastrado no escritório para o Push (modal "Vincular processo", desenho 11): retoma o
 * monitoramento, desfaz um "Excluir processo" anterior e reconcilia as publicações, que passam a aparecer como Vinculadas.
 */
final class IncluirProcessoCadastradoNoPushUseCase
{
    public function __construct(
        private readonly ProcessoRepository $processos,
        private readonly ProcessoAcompanhadoRepository $acompanhados,
        private readonly ReconciliarPublicacoesComProcessosUseCase $reconciliar,
    ) {
    }

    /**
     * @param list<int> $processosOcultos {@see \App\Pasta\Service\AcessoAPasta::idsDosProcessosOcultos()}
     *
     * @throws AcompanhamentoInvalidoException
     */
    public function executarPorNumero(Tenant $tenant, User $usuario, string $numero, array $processosOcultos): Processo
    {
        $digitos = (string) preg_replace('/\D/', '', $numero);
        $processo = $digitos === '' ? null : $this->processos->findByNumeroProcessoDoTenant($digitos, $tenant);
        if ($processo === null) {
            throw new AcompanhamentoInvalidoException('Selecione um processo da lista.');
        }
        $this->executar($tenant, $usuario, $processo, $processosOcultos);

        return $processo;
    }

    /**
     * @param list<int> $processosOcultos o processo cujas pastas são todas ocultas para a pessoa não abre para ela (K3)
     *
     * @throws AcompanhamentoInvalidoException
     */
    public function executar(Tenant $tenant, User $usuario, Processo $processo, array $processosOcultos): void
    {
        if (\in_array((int) $processo->getId(), $processosOcultos, true)) {
            throw new AcompanhamentoInvalidoException('Processo não encontrado.');
        }
        $processo->retomarMonitoramento($usuario, new \DateTimeImmutable());
        $linha = $this->acompanhados->findOneDoTenantPorNumero($tenant, (string) preg_replace('/\D/', '', $processo->getNumeroProcesso()));
        $linha?->limparExclusao();
        $this->processos->save($processo, true);
        $this->reconciliar->executar($tenant);
    }
}
