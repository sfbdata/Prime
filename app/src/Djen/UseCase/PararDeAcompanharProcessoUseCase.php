<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
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
    ) {
    }

    /**
     * @param list<string> $numeros
     * @param list<int>    $processosOcultos {@see \App\Pasta\Service\AcessoAPasta::idsDosProcessosOcultos()}
     *
     * @return int quantos números deixaram de ser acompanhados
     *
     * @throws AcompanhamentoInvalidoException quando um dos processos não abre para a pessoa
     */
    public function executar(Tenant $tenant, User $usuario, array $numeros, array $processosOcultos): int
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
}
