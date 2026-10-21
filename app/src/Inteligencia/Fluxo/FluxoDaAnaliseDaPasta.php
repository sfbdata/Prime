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
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Pasta\Entity\Pasta;

/**
 * Os agentes da pasta (fatia 2) como {@see FluxoDeAnalise}: o contexto é o do agente gravado na
 * linha; a decisão sobre o financeiro vem de `contexto_resumo.financeiro` (tomada na solicitação,
 * com sessão — o worker não decide); a "análise anterior" é a última concluída do MESMO comando do
 * drawer (spec inteligencia-drawer-da-pasta, F1) ou, na linha sem comando, do MESMO agente. Os
 * documentos marcados no "Índice inteligente" (F4) vêm de `contexto_resumo.documentosSelecionados`,
 * também decididos e validados na solicitação.
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
        $contexto = $this->montador->para($tenant, $pasta, $agente, $incluirFinanceiro, self::abasOcultasDo($analise), $comando, self::documentosSelecionadosDo($analise));
        if ($contexto->vazio()) {
            return null;
        }

        $anterior = $this->anteriorQuePodeVoltar($analise, $tenant);
        $analise->registrarContexto($contexto->hash, $contexto->resumo());

        // A resposta anterior volta ao provedor: passa pela máscara do montador (IA-033).
        return $this->prompt->montar($contexto, $this->montador->analiseAnterior($tenant, $anterior?->getResumo()));
    }

    /**
     * A anterior é a do MESMO comando (sem comando, a do mesmo agente, como antes), se puder voltar
     * ({@see podeVoltar()}). O "Índice inteligente" (F4) nunca recebe anterior: a seleção muda de um
     * pedido para outro, e um índice antigo citaria documentos que a pessoa não marcou agora.
     */
    private function anteriorQuePodeVoltar(AnaliseDeInteligencia $analise, Tenant $tenant): ?AnaliseDeInteligencia
    {
        $comando = $analise->getComando();
        if ($comando?->ehDaAbaDocumentos() === true) {
            return null;
        }

        $anterior = $this->analises->findUltimaConcluidaDoAlvo($tenant, $analise->getAlvoTipo(), $analise->getAlvoId(), TipoDeAnalise::AnalisePasta, $analise->getAgente(), $comando);

        return $anterior !== null && self::podeVoltar($anterior, $analise) ? $anterior : null;
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

    /**
     * Os documentos marcados na aba Documentos (F4), como a solicitação gravou. Sem a chave (todos os
     * outros comandos e as análises antigas): nulo, sem recorte.
     *
     * @return list<int>|null
     */
    private static function documentosSelecionadosDo(AnaliseDeInteligencia $analise): ?array
    {
        $ids = $analise->getContextoResumo()['documentosSelecionados'] ?? null;

        return \is_array($ids) ? array_values(array_filter($ids, 'is_int')) : null;
    }

    /**
     * A anterior não leu nada que quem pede agora não pode ler: a feita por um colega com o Financeiro ou
     * com uma aba que está oculta para quem pede não volta ao prompt (mesma regra do histórico do Perguntar).
     */
    private static function podeVoltar(AnaliseDeInteligencia $anterior, AnaliseDeInteligencia $atual): bool
    {
        if (self::leFinanceiro($anterior) && !self::leFinanceiro($atual)) {
            return false;
        }

        return VisibilidadeDaAnaliseDaPasta::legivelCom($anterior, self::abasOcultasDo($atual));
    }

    private static function leFinanceiro(AnaliseDeInteligencia $analise): bool
    {
        return ($analise->getContextoResumo()['financeiro'] ?? false) === true;
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
