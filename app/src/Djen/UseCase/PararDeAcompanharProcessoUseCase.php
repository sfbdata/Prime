<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Repository\ProcessoRepository;

/**
 * "Parar de acompanhar" da aba "Processos da OAB" (desenho 11): o processo cadastrado é pausado no
 * monitoramento (o mesmo da aba Push da pasta) e o número incluído na mão deixa de ser consultado.
 * As publicações já recebidas e as anotações ficam. O processo cujas pastas são todas ocultas para a pessoa
 * (K3) não abre para ela: se um dos números for dele, nada é gravado.
 */
final class PararDeAcompanharProcessoUseCase
{
    public function __construct(
        private readonly ProcessoRepository $processos,
        private readonly ProcessoAcompanhadoRepository $acompanhados,
        private readonly PastaRepository $pastas,
    ) {
    }

    /**
     * @param list<string> $numeros
     * @param list<int>             $processosOcultos {@see \App\Pasta\Service\AcessoAPasta::idsDosProcessosOcultos()}
     * @param callable(Pasta): bool $podeEditar       {@see \App\Pasta\Service\AcessoAPasta::podeEditar()} da pessoa: pausar
     *                                                muda o estado da pasta, então exige editar cada pasta do processo
     *
     * @return int quantos números deixaram de ser acompanhados
     *
     * @throws AcompanhamentoInvalidoException quando um dos processos não abre para a pessoa ou ela não edita
     *                                         alguma pasta dele (nada é pausado)
     */
    public function executar(Tenant $tenant, User $usuario, array $numeros, array $processosOcultos, callable $podeEditar): int
    {
        $achados = [];
        foreach (array_unique(array_map(static fn (string $n): string => (string) preg_replace('/\D/', '', $n), $numeros)) as $digitos) {
            if ($digitos === '') {
                continue;
            }
            $processo = $this->processos->findByNumeroProcessoDoTenant($digitos, $tenant);
            if ($processo !== null && \in_array((int) $processo->getId(), $processosOcultos, true)) {
                throw new AcompanhamentoInvalidoException('Processo não encontrado.');
            }
            if ($processo !== null) {
                $this->exigirEdicaoDasPastas($tenant, (int) $processo->getId(), $podeEditar);
            }
            $achados[$digitos] = $processo;
        }

        $agora = new \DateTimeImmutable();
        $parados = 0;
        foreach ($achados as $digitos => $processo) {
            $linha = $this->acompanhados->findOneDoTenantPorNumero($tenant, $digitos);
            if ($processo !== null && $processo->monitoramentoAtivo()) {
                $processo->pausarMonitoramento($usuario, $agora);
                ++$parados;
            } elseif ($linha !== null && $linha->isAcompanhando()) {
                ++$parados;
            }
            if ($linha !== null && $linha->isAcompanhando()) {
                $linha->pararDeAcompanhar();
            }
        }
        $this->acompanhados->gravar();

        return $parados;
    }

    /** A mesma regra do "Excluir processo": cada pasta não excluída do processo precisa ser editável pela pessoa. */
    private function exigirEdicaoDasPastas(Tenant $tenant, int $processoId, callable $podeEditar): void
    {
        foreach ($this->pastas->vinculosDosProcessos([$processoId], $tenant) as $vinculo) {
            $pasta = $vinculo->getPasta();
            if ($pasta->getExcluidaEm() === null && !$podeEditar($pasta)) {
                throw new AcompanhamentoInvalidoException(sprintf('Você não tem permissão para pausar o processo da Pasta %s. Nada foi pausado.', $pasta->getNup() ?? '#' . $pasta->getId()));
            }
        }
    }
}
