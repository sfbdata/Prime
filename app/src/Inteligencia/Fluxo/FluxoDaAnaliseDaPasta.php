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
 * com sessão — o worker não decide); a "análise anterior" é a última concluída do MESMO comando do
 * drawer (spec inteligencia-drawer-da-pasta, F1) ou, na linha sem comando, do MESMO agente.
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
        $comando = $analise->getComando();
        $contexto = $this->montador->para($tenant, $pasta, $agente, $incluirFinanceiro, self::abasOcultasDo($analise), $comando);
        if ($contexto->vazio()) {
            return null;
        }

        // A anterior é a do MESMO comando (sem comando, a do mesmo agente, como antes).
        $anterior = $this->analises->findUltimaConcluidaDoAlvo($tenant, $analise->getAlvoTipo(), $analise->getAlvoId(), TipoDeAnalise::AnalisePasta, $agente, $comando);
        $analise->registrarContexto($contexto->hash, $contexto->resumo());

        // A resposta anterior volta ao provedor: passa pela máscara do montador (IA-033).
        return $this->prompt->montar($contexto, $this->montador->analiseAnterior($tenant, $anterior?->getResumo()));
    }

    /**
     * As abas ocultas para quem pediu, como a solicitação gravou (spec pasta-ocultar-aba §5). O worker
     * não tem sessão: não decide, obedece. Análise anterior à regra não tem a chave: nada oculto.
     *
     * @return list<string>
     */
    private static function abasOcultasDo(AnaliseDeInteligencia $analise): array
    {
        $abas = $analise->getContextoResumo()['abasOcultas'] ?? [];

        return \is_array($abas) ? array_values(array_filter($abas, 'is_string')) : [];
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
        $comando = $analise->getComando();
        if ($comando !== null) {
            return sprintf('A análise "%s" da pasta %s ficou pronta.', $comando->rotulo(), (string) $pasta->getNup());
        }

        return sprintf(
            'A análise do %s da pasta %s ficou pronta.',
            $analise->getAgente()?->nome() ?? 'agente',
            (string) $pasta->getNup(),
        );
    }
}
