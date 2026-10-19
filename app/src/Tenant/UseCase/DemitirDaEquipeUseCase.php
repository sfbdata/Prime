<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Exception\MembroDaEquipeNaoEncontrado;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Repository\EquipeDoEscritorioRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * "Demitir (remover da conta)" do menu da equipe (desenho bj-contas.js, `remover`): é o mesmo "Remover do escritório"
 * da tela de equipe ({@see RemoverColaboradorDoEscritorioUseCase}), sem substituto (o que era da pessoa fica sem
 * responsável). Quem: o primário do escritório ou o Master; valem todas as travas da remoção (titular, último
 * administrador, Master, a si mesmo) e o primário não é demitido por aqui (como no menu, "Sem ações disponíveis").
 * Quem está suspenso ou em análise tem o vínculo inativo e a remoção só acha vínculo ativo: ele é reaprovado e
 * removido na MESMA transação; se alguma trava recusar, nada muda.
 */
final class DemitirDaEquipeUseCase
{
    public function __construct(
        private readonly EquipeDoEscritorioRepository $equipe,
        private readonly RegraDosPrimarios $regra,
        private readonly RemoverColaboradorDoEscritorioUseCase $remover,
    ) {
    }

    /** @return string o nome de quem saiu */
    public function executar(User $ator, Tenant $tenant, int $vinculoId): string
    {
        if (!$this->regra->podeGerirPrimarios($ator, $tenant)) {
            throw new OperacaoDePrimarioRecusada('Somente o usuário primário pode gerir a equipe da conta.');
        }
        $vinculo = $this->equipe->membroDoEscritorio($vinculoId, $tenant) ?? throw new MembroDaEquipeNaoEncontrado();
        if ($vinculo->getTenantRole()?->isSystem() === true) {
            throw new OperacaoDePrimarioRecusada('O usuário primário não pode ser demitido por aqui.');
        }

        $this->equipe->emTransacao(function () use ($ator, $tenant, $vinculo): void {
            if ($vinculo->getSituacao() !== SituacaoNaEquipe::Aprovado) {
                $vinculo->alterarSituacao(SituacaoNaEquipe::Aprovado);
                $this->equipe->gravar();
            }
            $this->remover->executar(new RemoverColaboradorInput($ator, $vinculo->getUser(), $tenant, null, OrigemRemocao::Painel));
        });

        return (string) $vinculo->getUser()->getFullName();
    }
}
