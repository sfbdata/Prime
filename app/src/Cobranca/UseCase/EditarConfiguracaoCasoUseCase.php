<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarConfiguracaoCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\EncargosInexequiveisException;
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
 * Recálculo imediato (D-A2-3, é dinheiro): mudar o percentual aqui não pode esperar o cron. Para cada
 * obrigação AUTOMÁTICA (não congelada) e VIVA do caso — MESMO predicado do cron F3, escopado ao caso
 * (`ObrigacaoRepository::paraRecalculoDeEncargosDoCaso`) —, resolve a config já com o caso novo e
 * materializa os quatro encargos para HOJE (`definirEncargos`, SEM congelar). Congeladas ficam
 * intactas (INV-E4). SEM freio de redução: baixar o percentual é decisão deliberada do gestor, então
 * reduzir honorário aqui é esperado (o freio segue vivo só no cron, não neste laço). Estouro de
 * precisão (`EncargosInexequiveisException`) pula AQUELA obrigação e conta — um caso patológico não
 * derruba a edição inteira.
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
        $puladas = 0;

        foreach ($this->obrigacaoRepository->paraRecalculoDeEncargosDoCaso($caso) as $obrigacao) {
            // Belt-and-suspenders do INV-E4: o predicado já exclui congeladas, mas se uma escapar o
            // laço a pula — número travado por gente nunca é sobrescrito por este recálculo.
            if ($obrigacao->encargosCongelados()) {
                continue;
            }

            $config = $this->resolvedor->resolver($obrigacao);

            try {
                $novos = $this->calculadora->calcular(
                    $obrigacao->getValorOriginal(),
                    $obrigacao->getVencimentoOriginal(),
                    $config,
                    $hoje,
                );
            } catch (EncargosInexequiveisException) {
                // Estouro de precisão (regime composto + atraso longo): pula esta e segue.
                ++$puladas;

                continue;
            }

            // SEM freio de redução e SEM congelar: automática recalculada para hoje (INV-E4 preservado).
            $obrigacao->definirEncargos($novos['juros'], $novos['multa'], $novos['correcao'], $novos['honorarios'], $hoje);
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
                'obrigacoesRecalculadas' => $recalculadas,
                'obrigacoesPuladas' => $puladas,
            ],
        );

        // Flush único: caso + obrigações managed + evento numa transação só.
        $this->casoRepository->salvar($caso, true);

        return $caso;
    }
}
