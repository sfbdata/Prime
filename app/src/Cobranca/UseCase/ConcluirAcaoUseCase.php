<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ConcluirAcaoInput;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Exception\ProximaAcaoNaoEncontradaException;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Conclui a Próxima Ação ativa de um Caso de Cobrança (SPEC §14): o gestor registra o `resultado` da
 * ação e PODE, no mesmo passo, definir a próxima.
 *
 * História: a ação é resolvida por id + tenant (guarda multi-tenant); só uma ação ainda `pendente` é
 * concluível (uma já concluída não é ação ativa). Concluir a atual a torna não-pendente, então criar a
 * próxima logo em seguida respeita o limite de 1 ativa por caso (§14). Próxima ação NÃO é evento de
 * histórico (§13) nem alerta (derivado): nada disso é gravado aqui. Conclusão e nova ação são
 * commitadas juntas (flush único).
 */
final class ConcluirAcaoUseCase
{
    public function __construct(
        private readonly ProximaAcaoRepository $proximaAcaoRepository,
    ) {
    }

    public function executar(ConcluirAcaoInput $input, Tenant $tenant, User $usuario): ProximaAcao
    {
        // Guarda multi-tenant: a ação tem de pertencer ao próprio escritório.
        $acao = $this->proximaAcaoRepository->findOneByIdDoTenant((int) $input->acaoId, $tenant);

        if ($acao === null) {
            throw new ProximaAcaoNaoEncontradaException((int) $input->acaoId);
        }

        // Uma ação já concluída não é a ação ativa: não há o que concluir (§14).
        if (!$acao->estaPendente()) {
            throw new ProximaAcaoNaoEncontradaException((int) $acao->getId());
        }

        $acao->concluir((string) $input->resultado);

        // O gestor pode definir a próxima no mesmo passo (§14): concluir a atual libera o limite de 1.
        $proximaDescricao = (string) $input->proximaDescricao;

        if ($proximaDescricao !== '') {
            // Conclusão sem flush; a nova ação pendente fecha a transação (flush único).
            $this->proximaAcaoRepository->salvar($acao);

            $proxima = (new ProximaAcao())
                ->setTenant($acao->getTenant())
                ->setCaso($acao->getCaso())
                ->setDescricao($proximaDescricao)
                ->setPrazo($input->proximoPrazo)
                ->setResponsavel($usuario)
                ->setCriadoPor($usuario);

            $this->proximaAcaoRepository->salvar($proxima, flush: true);

            return $acao;
        }

        // Sem próxima ação: garante a persistência da conclusão com flush.
        $this->proximaAcaoRepository->salvar($acao, flush: true);

        return $acao;
    }
}
