<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ConversaDaPastaOutput;
use App\Inteligencia\DTO\MensagemDaConversaOutput;
use App\Inteligencia\Entity\PerguntaDaConversa;
use App\Inteligencia\Repository\PerguntaDaConversaRepository;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Pasta\Entity\Pasta;

/**
 * A conversa da aba "Perguntar" (spec inteligencia-drawer-da-pasta, F3): as últimas
 * {@see LIMITE} perguntas DA PESSOA LOGADA na pasta, em ordem cronológica, com a resposta ou o
 * estado de cada uma. A conversa de outra pessoa nunca entra (a consulta é por autor). Pergunta
 * cuja resposta leu uma aba que hoje está oculta para quem lê sai da lista, como nas outras listas
 * da IA ({@see VisibilidadeDaAnaliseDaPasta}).
 *
 * Quem chama (o controller) já conferiu tenant, permissão sobre a pasta e o módulo.
 */
final class ListarConversaDaPastaUseCase
{
    public const LIMITE = 20;

    public function __construct(
        private readonly PerguntaDaConversaRepository $perguntas,
    ) {
    }

    /** @param list<string> $abasOcultas abas da pasta ocultas para quem lê */
    public function executar(Pasta $pasta, User $user, Tenant $tenant, array $abasOcultas = []): ConversaDaPastaOutput
    {
        $perguntas = $this->perguntas->ultimasDaConversa($tenant, (int) $pasta->getId(), $user, self::LIMITE);
        $legiveis = array_filter(
            $perguntas,
            static fn (PerguntaDaConversa $p): bool => VisibilidadeDaAnaliseDaPasta::legivelCom($p->getAnalise(), $abasOcultas),
        );

        return new ConversaDaPastaOutput(array_values(array_map(MensagemDaConversaOutput::fromEntity(...), $legiveis)));
    }
}
