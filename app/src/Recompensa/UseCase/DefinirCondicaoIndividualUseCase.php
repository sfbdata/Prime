<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\DefinirCondicaoIndividualInput;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Condição individual (★) de um indicador: valor, "libera com", carência e recorrência próprios (menu ⋮ da lista).
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Dado de plataforma, sem tenant: o indicador é qualquer
 * usuário que já indicou (o Master vê todos por desenho); o id vem da URL e é conferido contra as indicações, então um
 * id qualquer não ganha condição.
 * Fluxo principal: cria ou atualiza a condição do indicador; campo vazio usa a regra geral. Recorrente exige o valor
 * mensal (só guardado; quem lança o crédito mensal é a F6). Sem nenhum campo próprio nem recorrência, a condição não muda
 * nada e é removida (equivale a "voltar à regra geral").
 * Fluxos alternativos: indicador inexistente (404); valor fora do intervalo recusa (422), sem gravar.
 * Auditoria: CondicaoDeRecompensa é Auditavel; cada mudança entra na trilha do Master.
 */
final class DefinirCondicaoIndividualUseCase
{
    public function __construct(
        private readonly CondicaoDeRecompensaRepository $condicoes,
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
    public function executar(DefinirCondicaoIndividualInput $input): void
    {
        if ($this->indicacoes->contarDoUsuario($input->userId) === 0) {
            throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        }
        $usuario = $this->em->find(User::class, $input->userId) ?? throw new IndicadorNaoEncontrado('Indicador não encontrado.');

        $condicao = $this->condicoes->buscarDoIndicador($input->userId) ?? new CondicaoDeRecompensa($usuario);
        $condicao->definir($input->valorCentavos, $input->minEscritorios, $input->carenciaDias, $input->recorrente, $input->mensalCentavos, $input->motivo);
        $condicao->registrarAlteracao($input->ator, $this->relogio->now());

        $this->escopoDePlataforma->executar(function () use ($condicao): void {
            $condicao->semEfeito() ? $this->condicoes->remover($condicao, flush: true) : $this->condicoes->salvar($condicao, flush: true);
        });
    }
}
