<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
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
        private readonly PastaRepository $pastas,
    ) {
    }

    /**
     * @param list<int> $processosOcultos {@see \App\Pasta\Service\AcessoAPasta::idsDosProcessosOcultos()}
     * @param callable(Pasta): bool $podeEditar {@see \App\Pasta\Service\AcessoAPasta::podeEditar()} da pessoa: retomar muda
     *                                     o estado da pasta, então exige editar cada pasta do processo
     *
     * @throws AcompanhamentoInvalidoException
     */
    public function executarPorNumero(Tenant $tenant, User $usuario, string $numero, array $processosOcultos, callable $podeEditar): Processo
    {
        $digitos = (string) preg_replace('/\D/', '', $numero);
        $processo = $digitos === '' ? null : $this->processos->findByNumeroProcessoDoTenant($digitos, $tenant);
        if ($processo === null) {
            throw new AcompanhamentoInvalidoException('Selecione um processo da lista.');
        }
        $this->executar($tenant, $usuario, $processo, $processosOcultos, $podeEditar);

        return $processo;
    }

    /**
     * @param list<int> $processosOcultos o processo cujas pastas são todas ocultas para a pessoa não abre para ela (K3)
     * @param callable(Pasta): bool $podeEditar {@see \App\Pasta\Service\AcessoAPasta::podeEditar()} da pessoa: retomar muda
     *                                     o estado da pasta, então exige editar cada pasta do processo
     *                                     (processo recém-cadastrado, sem pasta, passa)
     *
     * @throws AcompanhamentoInvalidoException
     */
    public function executar(Tenant $tenant, User $usuario, Processo $processo, array $processosOcultos, callable $podeEditar): void
    {
        if (\in_array((int) $processo->getId(), $processosOcultos, true)) {
            throw new AcompanhamentoInvalidoException('Processo não encontrado.');
        }
        if ($processo->getId() !== null) {
            $this->exigirEdicaoDasPastas($tenant, (int) $processo->getId(), $podeEditar);
        }
        $processo->retomarMonitoramento($usuario, new \DateTimeImmutable());
        $linha = $this->acompanhados->findOneDoTenantPorNumero($tenant, (string) preg_replace('/\D/', '', $processo->getNumeroProcesso()));
        $linha?->limparExclusao();
        $this->processos->save($processo, true);
        $this->reconciliar->executar($tenant);
    }

    /** A mesma regra do "Parar de acompanhar" e do "Excluir processo": cada pasta não excluída do processo precisa ser editável. */
    private function exigirEdicaoDasPastas(Tenant $tenant, int $processoId, callable $podeEditar): void
    {
        foreach ($this->pastas->vinculosDosProcessos([$processoId], $tenant) as $vinculo) {
            $pasta = $vinculo->getPasta();
            if ($pasta->getExcluidaEm() === null && !$podeEditar($pasta)) {
                throw new AcompanhamentoInvalidoException(sprintf('Você não tem permissão para retomar o acompanhamento do processo da Pasta %s.', $pasta->getNup() ?? '#' . $pasta->getId()));
            }
        }
    }
}
