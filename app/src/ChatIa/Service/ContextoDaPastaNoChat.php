<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Service\PastaDoContextoDaIa;
use App\ChatIa\Exception\SemAceiteNoChatException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;

/**
 * A pasta citada numa pergunta do chat, validada e lida AGORA pelo servidor (o cliente nunca manda texto de contexto).
 *
 * Ordem: aceite do escritório ({@see SemAceiteNoChatException}, 409) → {@see PastaDoContextoDaIa::liberar} (pasta por id
 * + escritório, cadeado/permissão: 404 igual; segredo de justiça: {@see ContextoBloqueadoException}, 409) → relê a pasta
 * e monta o contexto do Gestor com a decisão de financeiro e abas dela (o mascaramento do escritório vale dentro do
 * montador) → blocos de dado já neutralizados de {@see PromptDoAgente::blocosDoContexto}.
 */
final class ContextoDaPastaNoChat
{
    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly PastaDoContextoDaIa $liberacao,
        private readonly PastaRepository $pastas,
        private readonly MontadorDeContextoDaPasta $montador,
        private readonly PromptDoAgente $agente,
    ) {
    }

    /**
     * @return array{pasta: Pasta, blocos: list<string>}
     *
     * @throws SemAceiteNoChatException
     * @throws PastaDaIaNaoEncontradaException
     * @throws ContextoBloqueadoException
     */
    public function ler(User $eu, Tenant $tenant, int $pastaId): array
    {
        if (!($this->configuracoes->findDoTenant($tenant)?->aceiteDeEnvioDado() ?? false)) {
            throw new SemAceiteNoChatException('O escritório precisa dar o aceite de envio de dados à I.A para citar uma pasta.');
        }

        $decisao = $this->liberacao->liberar($eu, $tenant, $pastaId);
        $pasta = $this->pastas->findOneBy(['id' => $decisao['pastaId'], 'tenant' => $tenant]) ?? throw new PastaDaIaNaoEncontradaException();
        $contexto = $this->montador->para($tenant, $pasta, PastaDoContextoDaIa::AGENTE, $decisao['financeiro'], $decisao['abasOcultas']);

        return ['pasta' => $pasta, 'blocos' => $this->agente->blocosDoContexto($contexto)];
    }
}
