<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\StatusDaPerguntaDaIaOutput;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * O polling da pergunta ao BlueJus IA na Central (F3, PR 3a): estado de UMA análise, só se for do próprio usuário.
 * A guarda é na consulta: a conversa IA é a DELE neste escritório (`daIaDe`) e a análise é do escritório E dessa
 * conversa (`findOneDoTenantEAlvo`). Análise de outra pessoa, de outro escritório, de pasta, de outro tipo ou excluída
 * dá o mesmo {@see AnaliseNaoEncontradaException} (404, nunca 403). Não passa pela permissão de módulo da IA.
 *
 * @throws SemAcessoACentralException
 * @throws AnaliseNaoEncontradaException
 */
final class ConsultarStatusDaPerguntaDaIaUseCase
{
    public const MENSAGEM_FALHA = 'Não foi possível responder agora. Tente novamente.';

    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ConversaRepository $conversas,
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $analiseId): StatusDaPerguntaDaIaOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        $conversa = $this->conversas->daIaDe($tenant, $eu) ?? throw new AnaliseNaoEncontradaException($analiseId);
        $conversaId = (int) $conversa->getId();
        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_CONVERSA_IA, $conversaId);
        if ($analise === null
            || $analise->estaExcluida()
            || $analise->getTipo() !== TipoDeAnalise::ChatDaCentral
            || $analise->getSolicitante()?->getId() !== $eu->getId()) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        $status = $analise->getStatus();

        return new StatusDaPerguntaDaIaOutput(
            analiseId: (int) $analise->getId(),
            conversaId: $conversaId,
            status: $status->value,
            emAndamento: $status->emAndamento(),
            terminal: $status->terminal(),
            mensagem: match ($status) {
                StatusDaAnalise::Falhou => self::MENSAGEM_FALHA,
                StatusDaAnalise::Indisponivel => Disponibilidade::NaoConfiguradaNaPlataforma->mensagem(),
                default => null,
            },
        );
    }
}
