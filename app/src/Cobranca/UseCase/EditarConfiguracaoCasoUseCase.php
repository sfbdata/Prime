<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarConfiguracaoCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Edita os HONORÁRIOS de um Caso de Cobrança âncora (Ajuste 2, Fatia A) e recalcula na hora os
 * honorários das obrigações automáticas e vivas do caso.
 *
 * História (D-A2-1/D-A2-2): a tela do objeto mostra o caso âncora; editar "os honorários do objeto" é
 * editar a config de honorários do caso — forma, percentual, base e carência (nenhuma coluna nova). O
 * caso é resolvido por id + tenant (guarda multi-tenant): inexistente/de outro escritório é erro de
 * entrada (CasoNaoEncontradoException), tratado no controller.
 *
 * Recálculo imediato do HONORÁRIO (D-A2-3, é dinheiro): mudar o percentual aqui atualiza já o honorário
 * materializado. Para cada obrigação AUTOMÁTICA (não congelada) e VIVA do caso
 * (`ObrigacaoRepository::paraRecalculoDeEncargosDoCaso`), recalcula SOMENTE o HONORÁRIO para HOJE, sobre
 * a base ATUAL (`valorOriginal + juros + multa + correção`). Congeladas (Liquidada/Substituída) ficam
 * intactas. No modelo "ao vivo" o honorário também é recomputado na leitura (hidratação); este recálculo
 * apenas mantém a coluna coerente de imediato para quem lê o cache antes da próxima hidratação.
 *
 * ⚠️ Juros/multa/correção ficam INTACTOS de propósito. A versão inicial recompunha os QUATRO encargos
 * com `calcular()`, e uma auditoria adversarial pegou a bomba: eles descem da CARTEIRA (o caso só
 * snapshota a taxa de honorário), então se a taxa da carteira tivesse sido baixada, este laço reduziria
 * o exigível de TODAS as automáticas do caso num POST, sem guard de alocado. Recalcular só o honorário
 * fechava isso por construção.
 *
 * 🔴 **A SEGUNDA METADE DESSA GARANTIA CAIU, E O CÓDIGO AQUI NÃO FOI AJUSTADO.** O argumento original
 * terminava assim: *"o honorário fica fora do exigível (INV-E2), logo pode subir OU descer livremente
 * aqui"*. A spec `cobranca-honorario-no-total.md` REVOGOU a INV-E2 — o honorário entra no
 * `valorExigivel()`. Consequência: mexer na config de honorários deste caso **move o exigível de todas
 * as automáticas dele num POST, sem guard de alocado** — exatamente a bomba que o desenho fechou. Uma
 * dívida com `alocado >= exigível` pode virar "quitada" por uma edição de tela.
 *
 * ⏳ **Fatia própria, e a decisão é do dono** (registrada no `docs/HANDOFF_ESPELHO_CONTABILIDADE.md`):
 * se baixar o percentual fizer uma dívida virar paga, o sistema recusa, avisa ou deixa? Medido em
 * produção em 20/08: **483 casos, ZERO com config de honorário própria, ZERO já editados** — a tela
 * nunca foi usada, então a exposição hoje é nula e o risco acorda no primeiro uso. O comentário fica
 * assim, com o defeito à vista, em vez de ser "atualizado" e sumir com a única pista dele.
 *
 * Persistência em flush ÚNICO: o evento de auditoria entra sem flush e o `salvar($caso, true)` fecha a
 * transação (o caso, as obrigações managed do laço e o evento numa unidade só).
 */
final class EditarConfiguracaoCasoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly ResolvedorConfigEncargos $resolvedor,
        private readonly CalculadoraEncargos $calculadora,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(EditarConfiguracaoCasoInput $input, Tenant $tenant, User $usuario): CasoCobranca
    {
        // Guarda multi-tenant: só um caso do próprio escritório pode ser configurado.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Snapshot ANTES, para o evento de auditoria documentar o antes/depois.
        $formaAntes = $caso->getFormaHonorarios();
        $percentualAntes = $caso->getPercentualHonorarios();
        $baseAntes = $caso->getBaseHonorarios();
        $carenciaAntes = $caso->getCarenciaHonorariosDias();

        // Aplica a nova config de honorários do caso (só honorários — D-A2-2).
        $caso->setFormaHonorarios($input->formaHonorarios);
        $caso->setPercentualHonorarios($input->percentualHonorarios);
        $caso->setBaseHonorarios($input->baseHonorarios);
        $caso->setCarenciaHonorariosDias($input->carenciaHonorariosDias);

        // Recálculo imediato (D-A2-3): a config já reflete o caso novo (o resolvedor lê os getters).
        $hoje = new \DateTimeImmutable('today');
        $recalculadas = 0;

        foreach ($this->obrigacaoRepository->paraRecalculoDeEncargosDoCaso($caso) as $obrigacao) {
            // Belt-and-suspenders do INV-E4: o predicado já exclui congeladas, mas se uma escapar o
            // laço a pula — número travado por gente nunca é sobrescrito por este recálculo.
            if ($obrigacao->encargosCongelados()) {
                continue;
            }

            $config = $this->resolvedor->resolver($obrigacao);
            $dias = $this->calculadora->diasDeAtraso($obrigacao->getVencimentoOriginal(), $hoje);

            // SÓ O HONORÁRIO é recalculado, sobre o exigível ATUAL (juros/multa/correção já
            // materializados). Nada de `calcular()` aqui: recompor os quatro reduziria o exigível se a
            // taxa da carteira caísse, sem freio — a bomba da F2 (ver docblock). `honorarios()` é
            // aritmética pura (não estoura como o regime composto), então não há caso a pular.
            $novoHonorario = $this->calculadora->honorarios(
                $obrigacao->getValorOriginal(),
                $obrigacao->getJuros(),
                $obrigacao->getMulta(),
                $obrigacao->getCorrecao(),
                $config,
                $dias,
            );

            // Grava mantendo juros/multa/correção INTACTOS; só o honorário muda. ⚠️ Aqui dizia "(o
            // exigível não se move)" — falso desde que a INV-E2 caiu: o honorário ENTRA no exigível,
            // logo mexer nele MOVE o exigível. É o defeito descrito no topo da classe.
            // Não congela: a obrigação segue Viva e a leitura recalcula ao vivo (este recálculo só mantém
            // o cache do honorário coerente de imediato).
            $obrigacao->definirEncargos(
                $obrigacao->getJuros(),
                $obrigacao->getMulta(),
                $obrigacao->getCorrecao(),
                $novoHonorario,
                $hoje,
            );
            ++$recalculadas;
        }

        // Auditoria: UM evento no histórico do caso (não um por obrigação — seria ruído). Sem flush
        // aqui; o salvar do caso fecha a transação. Mudança de config FINANCEIRA precisa de autor: o
        // evento registra QUEM alterou os honorários (achado da revisão da Fatia A).
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ValorAtualizadoReconhecido,
            $usuario,
            sprintf(
                'Honorários do caso atualizados; %d obrigação(ões) automática(s) recalculada(s).',
                $recalculadas,
            ),
            [
                'formaAntes' => $formaAntes->value,
                'formaDepois' => $caso->getFormaHonorarios()->value,
                'percentualAntes' => $percentualAntes,
                'percentualDepois' => $caso->getPercentualHonorarios(),
                'baseAntes' => $baseAntes?->value,
                'baseDepois' => $caso->getBaseHonorarios()?->value,
                'carenciaAntes' => $carenciaAntes,
                'carenciaDepois' => $caso->getCarenciaHonorariosDias(),
                // Só o honorário destas foi recalculado — mas o exigível MUDOU junto, porque o
                // honorário está dentro dele desde que a INV-E2 caiu (ver o topo da classe).
                'obrigacoesComHonorarioRecalculado' => $recalculadas,
            ],
        );

        // Flush único: caso + obrigações managed + evento numa transação só.
        $this->casoRepository->salvar($caso, true);

        return $caso;
    }
}
