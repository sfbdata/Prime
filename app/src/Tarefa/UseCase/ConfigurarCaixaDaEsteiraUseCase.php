<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tarefa\DTO\ConfigurarCaixaDaEsteiraInput;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\CaixaNaoConfiguravelException;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Service\QuemConfiguraACaixa;

/**
 * "Salvar" do modal "Configurar caixa" (desenho 04 de 22/10, dc L133 e L184-191).
 *
 * **Quem:** o primário do escritório ou quem administra perfis (D2). A esteira é única do escritório: o que
 * muda aqui muda para a equipe inteira.
 *
 * **O quê:** grava o nome da caixa, o nome da etapa e quem é avisado quando chega demanda. Nome vazio volta
 * ao padrão. Se tudo voltou ao padrão (dois nomes vazios e ninguém avisado), a linha é apagada: a caixa
 * volta a ser a padrão, como no "Restaurar padrão".
 *
 * **Regras:** só entra como avisado quem tem vínculo ativo com o escritório e conta ativa. Um id de fora
 * recusa o pedido inteiro, em vez de gravar só a parte boa: id estranho no formulário é adulteração, não
 * engano de digitação. Repetido entra uma vez.
 *
 * **Erros:** `CaixaNaoConfiguravelException`, com a mensagem que o usuário lê.
 */
final class ConfigurarCaixaDaEsteiraUseCase
{
    public function __construct(
        private readonly CaixaDaEsteiraRepository $caixas,
        private readonly UserTenantRepository $vinculos,
        private readonly QuemConfiguraACaixa $quemConfigura,
    ) {
    }

    public function executar(User $usuario, Tenant $escritorio, EtapaDaPasta $etapa, ConfigurarCaixaDaEsteiraInput $input): void
    {
        $this->exigirPermissao($usuario, $escritorio);
        $avisados = $this->avisadosDaEquipe($escritorio, $input->avisados);

        $caixa = $this->caixas->daEtapa($escritorio, $etapa) ?? new CaixaDaEsteira($escritorio, $etapa);
        $caixa->configurar($input->nomeDaCaixa, $input->nomeDaEtapa, $avisados);

        if ($this->voltouAoPadrao($caixa)) {
            $this->apagarSeGravada($caixa);

            return;
        }

        $this->caixas->salvar($caixa, flush: true);
    }

    private function exigirPermissao(User $usuario, Tenant $escritorio): void
    {
        if (!$this->quemConfigura->pode($usuario, $escritorio)) {
            throw CaixaNaoConfiguravelException::semPermissao();
        }
    }

    /**
     * @param list<int> $ids
     * @return list<User>
     */
    private function avisadosDaEquipe(Tenant $escritorio, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $daEquipe = [];
        foreach ($this->vinculos->findAtivosComCargoELotacaoPorTenant($escritorio) as $vinculo) {
            /** @var UserTenant $vinculo */
            $daEquipe[(int) $vinculo->getUser()->getId()] = $vinculo->getUser();
        }

        $avisados = [];
        foreach ($ids as $id) {
            $avisados[] = $daEquipe[$id] ?? throw CaixaNaoConfiguravelException::avisadoForaDaEquipe();
        }

        return $avisados;
    }

    private function voltouAoPadrao(CaixaDaEsteira $caixa): bool
    {
        return $caixa->getNomeDaCaixa() === null
            && $caixa->getNomeDaEtapa() === null
            && $caixa->getAvisados()->isEmpty();
    }

    private function apagarSeGravada(CaixaDaEsteira $caixa): void
    {
        if ($caixa->getId() !== null) {
            $this->caixas->remover($caixa, flush: true);
        }
    }
}
