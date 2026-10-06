<?php

declare(strict_types=1);

namespace App\Inteligencia\Fluxo;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\InterpretadorDeRespostaDoAgente;
use App\Pasta\Entity\Pasta;

/**
 * Os agentes da pasta (fatia 2) como {@see FluxoDeAnalise}: o contexto é o do agente gravado na
 * linha; a decisão sobre o financeiro vem de `contexto_resumo.financeiro` (tomada na solicitação,
 * com sessão — o worker não decide); a "análise anterior" é a última concluída do MESMO agente.
 *
 * Linha de `analise_pasta` sem agente não existe pelo UseCase; se aparecer, é dado corrompido e
 * o fluxo recusa com `\LogicException` (o handler a trata como falha, sem chamar o provedor).
 */
final class FluxoDaAnaliseDaPasta implements FluxoDeAnalise
{
    public function __construct(
        private readonly MontadorDeContextoDaPasta $montador,
        private readonly PromptDoAgente $prompt,
        private readonly InterpretadorDeRespostaDoAgente $interpretador,
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function tipo(): TipoDeAnalise
    {
        return TipoDeAnalise::AnalisePasta;
    }

    public function preparar(AnaliseDeInteligencia $analise, Tenant $tenant, Pasta $pasta): ?PedidoDeLinguagem
    {
        $agente = $analise->getAgente();
        if ($agente === null) {
            throw new \LogicException('Análise de pasta sem agente.');
        }

        $incluirFinanceiro = ($analise->getContextoResumo()['financeiro'] ?? false) === true;
        $contexto = $this->montador->para($tenant, $pasta, $agente, $incluirFinanceiro);
        if ($contexto->vazio()) {
            return null;
        }

        $anterior = $this->analises->findUltimaConcluidaDoAlvo($tenant, $analise->getAlvoTipo(), $analise->getAlvoId(), TipoDeAnalise::AnalisePasta, $agente);
        $analise->registrarContexto($contexto->hash, $contexto->resumo());

        return $this->prompt->montar($contexto, $anterior?->getResumo());
    }

    public function motivoDeContextoVazio(): string
    {
        return 'sem dados para o agente';
    }

    public function interpretar(string $texto): ResultadoDaAnalise
    {
        return $this->interpretador->interpretar($texto);
    }

    public function ancoraDaPasta(): string
    {
        return '#ia';
    }

    public function textoDaNotificacao(AnaliseDeInteligencia $analise, Pasta $pasta): string
    {
        return sprintf(
            'A análise do %s da pasta %s ficou pronta.',
            $analise->getAgente()?->nome() ?? 'agente',
            (string) $pasta->getNup(),
        );
    }
}
