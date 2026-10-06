<?php

declare(strict_types=1);

namespace App\Inteligencia\Fluxo;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\InterpretadorDeRespostaDePush;
use App\Pasta\Entity\Pasta;

/**
 * "Resumir com IA" do Push (fatia 1) como um {@see FluxoDeAnalise}: o que o handler fazia em
 * linha — contexto das movimentações, [NOVA] contra a última concluída, prompt `push-v1`,
 * interpretação do JSON — agora mora aqui, sem mudar comportamento.
 */
final class FluxoDoResumoDoPush implements FluxoDeAnalise
{
    public function __construct(
        private readonly MontadorDeContextoDoPush $montador,
        private readonly PromptResumoDoPush $prompt,
        private readonly InterpretadorDeRespostaDePush $interpretador,
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function tipo(): TipoDeAnalise
    {
        return TipoDeAnalise::ResumoPush;
    }

    public function preparar(AnaliseDeInteligencia $analise, Tenant $tenant, Pasta $pasta): ?PedidoDeLinguagem
    {
        $contexto = $this->montador->para($tenant, $pasta);
        if ($contexto->vazio()) {
            return null;
        }

        $anterior = $this->analises->findUltimaConcluidaDoAlvo($tenant, $analise->getAlvoTipo(), $analise->getAlvoId(), TipoDeAnalise::ResumoPush);
        $chavesAnteriores = $anterior?->getChavesAnalisadas() ?? [];
        $analise->registrarContexto($contexto->hash, $contexto->resumo($chavesAnteriores));

        return $this->prompt->montar($contexto, $anterior?->getResumo(), $chavesAnteriores);
    }

    public function motivoDeContextoVazio(): string
    {
        return 'sem movimentações';
    }

    public function interpretar(string $texto): ResultadoDaAnalise
    {
        return ResultadoDaAnalise::doPush($this->interpretador->interpretar($texto));
    }

    public function ancoraDaPasta(): string
    {
        return '#push';
    }

    public function textoDaNotificacao(AnaliseDeInteligencia $analise, Pasta $pasta): string
    {
        return sprintf('A análise por IA da pasta %s ficou pronta.', (string) $pasta->getNup());
    }
}
