<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\DefinirLimiteDeArmazenamentoInput;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Auth\User;

/**
 * O Master dá a um escritório um limite de espaço próprio, ou o deixa sem limite.
 *
 * Quem dispara: o Master, no Painel (desenho 05, "Editar limite de armazenamento"). A autorização
 * (`ROLE_SUPER_ADMIN`) é do controller que chama; este caso de uso confia que chegou um Master.
 *
 * Regras do desenho: justificativa obrigatória (vai para a auditoria) e nunca abaixo do uso:
 * "Reduzir não apaga arquivos, por isso não é permitido abaixo do uso."
 */
final class DefinirLimiteDeArmazenamentoUseCase
{
    public function __construct(
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly PlanoRepository $planos,
        private readonly ConsumoDeArmazenamentoRepository $consumo,
        private readonly SituacaoDoEspaco $situacao,
    ) {
    }

    public function executar(DefinirLimiteDeArmazenamentoInput $input, ?User $master): RetratoDoEspaco
    {
        $this->exigirJustificativa($input->justificativa);
        if (!$input->semLimite) {
            $this->exigirQueNaoFiqueAbaixoDoUso($input->tenantId, (int) $input->cotaGb);
        }

        $espaco = $this->espacos->doTenantOuNovo($input->tenantId, $this->planos->free());
        $espaco->definirLimite($input->cotaGb, $input->semLimite, trim($input->justificativa), $master);
        $this->espacos->salvar($espaco, flush: true);

        return $this->situacao->doEscritorio($input->tenantId);
    }

    private function exigirJustificativa(string $justificativa): void
    {
        if (trim($justificativa) === '') {
            throw new \DomainException('A justificativa é obrigatória e fica na auditoria.');
        }
    }

    private function exigirQueNaoFiqueAbaixoDoUso(int $tenantId, int $cotaGb): void
    {
        $usados = $this->consumo->bytesDoEscritorio($tenantId);
        if ($cotaGb * RetratoDoEspaco::BYTES_POR_GB < $usados) {
            throw new \DomainException(sprintf(
                'O novo limite é menor que o volume em uso (%s). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso.',
                RetratoDoEspaco::formatarGb($usados),
            ));
        }
    }
}
