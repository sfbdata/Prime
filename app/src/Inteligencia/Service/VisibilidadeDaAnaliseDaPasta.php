<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * Quem lê uma análise de IA da pasta (spec pasta-ocultar-aba §5, revisão de permissão): a análise só
 * aparece se NENHUMA aba de onde ela tirou conteúdo estiver oculta para quem lê. É a regra única,
 * usada pelas listas (painel dos agentes, lista do Push) e pela barreira das rotas por id
 * (`BarreiraDeAnaliseDeAbaOcultaListener`); análise bloqueada é 404, sem confirmar que existe.
 *
 * As abas de uma análise ({@see abasDaAnalise()}):
 *  - resumo do Push → Push;
 *  - análise de agente → as abas das seções que o agente lê (`Agente::secoes()`), menos as que
 *    estavam ocultas para quem pediu (`contexto_resumo.abasOcultas`: nada delas entrou) e menos o
 *    Financeiro quando a solicitação o deixou de fora (`contexto_resumo.financeiro`). Os documentos
 *    lidos com o Financeiro visível incluem os de contrato, que são do Financeiro: a análise que leu
 *    documentos depende também dele nesse caso;
 *  - resumo de documento → Documentos;
 *  - pergunta livre (aba Perguntar, F3 do drawer) → como a análise do agente que respondeu (o
 *    Gestor), pela mesma regra; sem agente gravado (não existe pelo UseCase) → todas as abas.
 *
 * A pergunta livre tem uma regra a mais nas rotas por id ({@see podeLer()}): só quem perguntou a
 * lê. A conversa é da pessoa (spec inteligencia-drawer-da-pasta, F3); um colega que adivinhe o id
 * recebe o mesmo 404 da aba oculta.
 */
final class VisibilidadeDaAnaliseDaPasta
{
    public function __construct(
        private readonly VisibilidadeDasAbasDaPasta $visibilidadeDasAbas,
    ) {
    }

    /** @return list<AbaDaPasta> */
    public static function abasDaAnalise(AnaliseDeInteligencia $analise): array
    {
        return match ($analise->getTipo()) {
            TipoDeAnalise::ResumoPush      => [AbaDaPasta::Push],
            TipoDeAnalise::ResumoDocumento => [AbaDaPasta::Documentos],
            TipoDeAnalise::AnalisePasta    => self::abasDoAgente($analise),
            TipoDeAnalise::PerguntaLivre   => self::abasDoAgente($analise),
            TipoDeAnalise::ChatDaCentral   => [],
        };
    }

    /** @param list<string> $abasOcultas ids das abas ocultas para quem lê */
    public static function legivelCom(AnaliseDeInteligencia $analise, array $abasOcultas): bool
    {
        foreach (self::abasDaAnalise($analise) as $aba) {
            if (\in_array($aba->value, $abasOcultas, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * As abas ocultas desta pasta para quem lê, em ids (uma consulta às regras).
     *
     * @return list<string>
     */
    public function abasOcultasPara(Pasta $pasta, User $leitor): array
    {
        return array_map(static fn (AbaDaPasta $a): string => $a->value, $this->visibilidadeDasAbas->abasOcultasPara($pasta, $leitor));
    }

    public function podeLer(AnaliseDeInteligencia $analise, Pasta $pasta, User $leitor): bool
    {
        if ($analise->getTipo() === TipoDeAnalise::PerguntaLivre && $analise->getSolicitante()?->getId() !== $leitor->getId()) {
            return false;
        }

        return self::legivelCom($analise, $this->abasOcultasPara($pasta, $leitor));
    }

    /** @return list<AbaDaPasta> */
    private static function abasDoAgente(AnaliseDeInteligencia $analise): array
    {
        $agente = $analise->getAgente();
        if ($agente === null) {
            return AbaDaPasta::cases();
        }

        $resumo = $analise->getContextoResumo();
        $ocultasNaSolicitacao = \is_array($resumo['abasOcultas'] ?? null) ? $resumo['abasOcultas'] : [];
        // A seção Financeiro só entrou se a solicitação a liberou (`financeiro`); os contratos entre os
        // documentos entraram sempre que o Financeiro NÃO estava oculto para quem pediu.
        $foraDoContexto = ($resumo['financeiro'] ?? false) === true
            ? $ocultasNaSolicitacao
            : [...$ocultasNaSolicitacao, AbaDaPasta::Financeiro->value];

        $abas = [];
        foreach ($agente->secoes() as $secao) {
            $aba = $secao->abaDaPasta();
            if ($aba !== null && !\in_array($aba->value, $foraDoContexto, true)) {
                $abas[$aba->value] = $aba;
            }
        }
        if (isset($abas[AbaDaPasta::Documentos->value]) && !\in_array(AbaDaPasta::Financeiro->value, $ocultasNaSolicitacao, true)) {
            $abas[AbaDaPasta::Financeiro->value] = AbaDaPasta::Financeiro;
        }

        return array_values($abas);
    }
}
