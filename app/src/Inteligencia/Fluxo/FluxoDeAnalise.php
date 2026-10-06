<?php

declare(strict_types=1);

namespace App\Inteligencia\Fluxo;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\RespostaInvalidaException;
use App\Pasta\Entity\Pasta;

/**
 * O passo específico de cada {@see TipoDeAnalise} dentro do worker. O
 * `ProcessarAnaliseDeInteligenciaHandler` fica com o que é comum — fronteira de tenant, máquina
 * de estados, provedor, classificação de falha, retry, notificação —, e delega a estes dois
 * métodos o que muda por tipo: montar o contexto e o pedido, e interpretar a resposta.
 *
 * Um fluxo por tipo (`FluxoDoResumoDoPush`, `FluxoDaAnaliseDaPasta`); o handler escolhe pelo
 * `tipo` da linha. Tenant explícito em toda leitura: roda sem sessão.
 */
interface FluxoDeAnalise
{
    public function tipo(): TipoDeAnalise;

    /**
     * Lê o contexto (tenant explícito), registra hash + resumo na análise e monta o pedido ao
     * provedor. Devolve null quando não há o que analisar — a análise vira `falhou` com
     * {@see motivoDeContextoVazio()}, sem chamar o provedor.
     *
     * @throws ContextoBloqueadoException sigilo: nunca sai
     */
    public function preparar(AnaliseDeInteligencia $analise, Tenant $tenant, Pasta $pasta): ?PedidoDeLinguagem;

    public function motivoDeContextoVazio(): string;

    /**
     * @throws RespostaInvalidaException
     */
    public function interpretar(string $texto): ResultadoDaAnalise;

    /** Âncora da pasta para a notificação ("#push", "#ia"). */
    public function ancoraDaPasta(): string;

    public function textoDaNotificacao(AnaliseDeInteligencia $analise, Pasta $pasta): string;
}
