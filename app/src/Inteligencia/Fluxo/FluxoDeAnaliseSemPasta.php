<?php

declare(strict_types=1);

namespace App\Inteligencia\Fluxo;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\RespostaInvalidaException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * O passo específico de um {@see TipoDeAnalise} cujo alvo NÃO é uma pasta (o chat da Central: o alvo é a conversa).
 * O `ProcessarAnaliseDeInteligenciaHandler` guarda o que é comum (tenant, máquina de estados, disponibilidade,
 * provedor, falhas) e vai por um ramo que não procura pasta nem notifica. Tenant explícito: roda sem sessão.
 */
#[AutoconfigureTag(self::TAG)]
interface FluxoDeAnaliseSemPasta
{
    public const TAG = 'app.inteligencia.fluxo_sem_pasta';

    public function tipo(): TipoDeAnalise;

    /**
     * Relê o contexto (tenant explícito), registra hash + resumo na análise e monta o pedido. Null = nada a analisar:
     * a análise vira `falhou` com {@see motivoDeContextoVazio()}, sem chamar o provedor.
     *
     * @throws \LogicException análise inconsistente (o handler marca `falhou` sem chamar o provedor)
     */
    public function preparar(AnaliseDeInteligencia $analise, Tenant $tenant): ?PedidoDeLinguagem;

    public function motivoDeContextoVazio(): string;

    /**
     * @throws RespostaInvalidaException
     */
    public function interpretar(string $texto): ResultadoDaAnalise;

    /**
     * Roda DENTRO da mesma transação que conclui a análise (resposta gravada e análise concluída juntas).
     */
    public function aoConcluir(AnaliseDeInteligencia $analise, Tenant $tenant, ResultadoDaAnalise $resultado): void;
}
