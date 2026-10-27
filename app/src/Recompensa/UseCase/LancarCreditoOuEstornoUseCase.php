<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\LancarCreditoOuEstornoInput;
use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Crédito ou estorno manual no extrato de um indicador (menu ⋮ › "Lançar crédito ou estorno"; desenho 05 Perfis 1.2 VS
 * 25.10.26, diálogo `bonus`; spec perfis-1.2-vs-25-10-recompensas-e-planos, F2). Risco ALTO: mexe no livro de dinheiro.
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Dado de plataforma, sem tenant: o indicador é qualquer usuário
 * que já indicou; o id vem da URL e é conferido contra as indicações.
 * Fluxo principal: grava um lançamento `bonus` (valor com sinal, nunca zero). O saldo não é gravado: o painel soma os
 * lançamentos na leitura e nunca mostra saldo negativo.
 * Idempotência: a chave é única. Repetir a mesma chave com o mesmo conteúdo não lança de novo e responde como sucesso
 * (duplo clique, reenvio). A chave fica travada até o fim da transação, então dois pedidos simultâneos passam um de cada vez.
 * Fluxos alternativos: indicador inexistente (404); mesma chave com outro indicador ou valor recusa (422), sem gravar.
 * Auditoria: LancamentoDeRecompensa é Auditavel; cada lançamento entra na trilha do Master.
 */
final class LancarCreditoOuEstornoUseCase
{
    public function __construct(
        private readonly LancamentoDeRecompensaRepository $lancamentos,
        private readonly IndicacaoRepository $indicacoes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /**
     * @throws IndicadorNaoEncontrado
     * @throws RecompensaRecusada
     */
    public function executar(LancarCreditoOuEstornoInput $input): void
    {
        if ($this->indicacoes->contarDoUsuario($input->userId) === 0) {
            throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        }
        $usuario = $this->em->find(User::class, $input->userId) ?? throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        $novo = new LancamentoDeRecompensa($usuario, LancamentoDeRecompensa::TIPO_BONUS, $input->valorCentavos, $input->observacao, $input->chaveDeIdempotencia, $input->ator, $this->relogio->now());

        $existente = $this->escopoDePlataforma->executar(fn (): ?LancamentoDeRecompensa => $this->indicacoes->emTransacaoTravada($input->userId, function () use ($novo): ?LancamentoDeRecompensa {
            $this->lancamentos->travarChave($novo->getChaveDeIdempotencia());
            $existente = $this->lancamentos->buscarPorChave($novo->getChaveDeIdempotencia());
            if ($existente === null) {
                $this->lancamentos->salvar($novo);
            }

            return $existente;
        }));

        // Fora da transação: uma exceção dentro dela fecharia o EntityManager.
        if ($existente !== null && ($existente->getUser()?->getId() !== $usuario->getId() || $existente->getValorCentavos() !== $novo->getValorCentavos() || $existente->getTipo() !== LancamentoDeRecompensa::TIPO_BONUS)) {
            throw new RecompensaRecusada('Este pedido já foi usado para outro lançamento. Feche a janela e abra de novo.');
        }
    }
}
