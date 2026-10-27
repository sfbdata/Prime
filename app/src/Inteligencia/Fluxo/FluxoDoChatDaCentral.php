<?php

declare(strict_types=1);

namespace App\Inteligencia\Fluxo;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Contract\ConversaDaIaNaCentral;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Prompt\PromptDoChatDaCentral;
use App\Inteligencia\Service\InterpretadorDeRespostaDoChat;
use Psr\Clock\ClockInterface;

/**
 * O chat da Central (F3, `docs/specs/ia-chat-central.md`) como {@see FluxoDeAnaliseSemPasta}: o alvo da análise é a
 * conversa IA (`alvo_id`) e a pergunta é a mensagem de `contexto_resumo.mensagemId`. A Central entrega, pela porta
 * {@see ConversaDaIaNaCentral}, o texto da pergunta, o primeiro nome da pessoa e as até 12 mensagens anteriores; tudo
 * passa pela neutralização e pela máscara do escritório (a mesma regra da pergunta da pasta) antes de ir ao modelo.
 *
 * Nada de pasta, equipe ou produtividade entra no pedido. `contexto_resumo` guarda só ids e contagens, nunca texto.
 * Pergunta que a Central não confirma (conversa/mensagem de outro, vínculo encerrado) é `\LogicException`: o handler
 * marca `falhou` SEM chamar o provedor. A resposta e a conclusão da análise são gravadas na mesma transação
 * ({@see aoConcluir()} roda dentro dela): resposta gravada sem análise concluída, ou o contrário, cobraria de novo.
 */
final class FluxoDoChatDaCentral implements FluxoDeAnaliseSemPasta
{
    public function __construct(
        private readonly ConversaDaIaNaCentral $central,
        private readonly PromptDoChatDaCentral $prompt,
        private readonly InterpretadorDeRespostaDoChat $interpretador,
        private readonly MontadorDeContextoDoPush $montador,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function tipo(): TipoDeAnalise
    {
        return TipoDeAnalise::ChatDaCentral;
    }

    public function preparar(AnaliseDeInteligencia $analise, Tenant $tenant): ?PedidoDeLinguagem
    {
        $solicitante = $analise->getSolicitante() ?? throw new \LogicException('Chat sem solicitante.');
        if ($analise->getAlvoTipo() !== AnaliseDeInteligencia::ALVO_CONVERSA_IA) {
            throw new \LogicException('Chat com alvo que não é uma conversa.');
        }
        $conversaId = $analise->getAlvoId();
        $mensagemId = (int) ($analise->getContextoResumo()['mensagemId'] ?? 0);
        if ($mensagemId <= 0) {
            throw new \LogicException('Chat sem a mensagem da pergunta.');
        }

        $contexto = $this->central->contextoDaPergunta($tenant, $conversaId, $mensagemId, $solicitante)
            ?? throw new \LogicException('Chat sem pergunta válida nesta conversa.');

        $historico = [];
        foreach ($contexto['historico'] as $troca) {
            $texto = $this->protegido($tenant, $troca['texto']);
            if ($texto !== '') {
                $historico[] = ['de' => $troca['de'], 'texto' => $texto];
            }
        }
        $pergunta = $this->protegido($tenant, $contexto['texto']);

        // Só ids e contagens: o texto da conversa é da pessoa e não se grava (regra 6 da Inteligência).
        $analise->registrarContexto(
            hash('sha256', $conversaId . '|' . $mensagemId),
            ['conversaId' => $conversaId, 'mensagemId' => $mensagemId, 'historico' => count($historico), 'total' => 1],
        );

        if ($pergunta === '') {
            return null;
        }

        return $this->prompt->montar($this->protegido($tenant, $contexto['nomeDaPessoa']), $historico, $pergunta);
    }

    public function motivoDeContextoVazio(): string
    {
        return 'pergunta vazia';
    }

    public function interpretar(string $texto): ResultadoDaAnalise
    {
        return $this->interpretador->interpretar($texto);
    }

    public function aoConcluir(AnaliseDeInteligencia $analise, Tenant $tenant, ResultadoDaAnalise $resultado): void
    {
        $solicitante = $analise->getSolicitante() ?? throw new \LogicException('Chat sem solicitante.');

        $linhas = [$resultado->resumo];
        foreach ($resultado->pontos as $ponto) {
            $linhas[] = '• ' . $ponto['texto'];
        }

        $this->central->gravarResposta($tenant, $analise->getAlvoId(), $solicitante, implode("\n", $linhas), $this->relogio->now());
    }

    /** Neutralizado e mascarado pela preferência atual do escritório (vazio vira texto vazio). */
    private function protegido(Tenant $tenant, string $texto): string
    {
        return $this->montador->analiseAnterior($tenant, $texto) ?? '';
    }
}
