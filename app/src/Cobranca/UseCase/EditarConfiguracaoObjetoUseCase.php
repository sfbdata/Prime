<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarConfiguracaoObjetoInput;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Entity\Tenant\Tenant;

/**
 * Edita a CONFIGURAÇÃO DE ENCARGOS de um Objeto de Cobrança já existente (spec "cascata de encargos ao
 * vivo sem snapshot" §3.1/§4, #9-T3).
 *
 * História: um gestor sobrepõe, para um objeto específico (unidade/veículo/matrícula...), uma ou mais
 * das 10 taxas/bases/carências que hoje herda da carteira — por exemplo, um imóvel com juros
 * contratuais diferentes do padrão da carteira. É o NÍVEL 2 (o "meio") da cascata
 * `Carteira → Objeto → Obrigação`: o override vale para TODAS as obrigações de TODOS os casos deste
 * objeto, AO VIVO (sem congelar nada) — `ResolvedorConfigEncargos::resolverDoObjeto` lê estas colunas
 * na hora de cada cálculo, não há snapshot a recalcular aqui.
 *
 * Cada campo é gravado como veio do formulário: preenchido = override; `null` = volta a herdar a
 * carteira (o gestor "desfaz" o override deixando o campo em branco). Não há reidratação especial —
 * o Input já chega com os 10 campos, inclusive os que o usuário não tocou (o Form os pré-carrega com o
 * valor atual do objeto, spec §4 "a tela já abre preenchida").
 *
 * O objeto é resolvido por id + tenant (guarda multi-tenant, invariável 24): inexistente/de outro
 * escritório é erro de entrada (ObjetoNaoEncontradoException), tratado no controller. O `atualizadoEm`
 * é setado automaticamente pela entidade (PreUpdate).
 */
final class EditarConfiguracaoObjetoUseCase
{
    public function __construct(
        private readonly ObjetoCobrancaRepository $objetoRepository,
    ) {
    }

    public function executar(EditarConfiguracaoObjetoInput $input, Tenant $tenant): ObjetoCobranca
    {
        // Guarda multi-tenant: só um objeto do próprio escritório pode ser configurado.
        $objeto = $this->objetoRepository->findOneByIdDoTenant((int) $input->objetoId, $tenant);

        if ($objeto === null) {
            throw new ObjetoNaoEncontradoException((int) $input->objetoId);
        }

        $objeto->setTaxaJurosMensalBp($input->taxaJurosMensalBp);
        $objeto->setRegimeJuros($input->regimeJuros);
        $objeto->setTaxaMultaBp($input->taxaMultaBp);
        $objeto->setBaseMulta($input->baseMulta);
        $objeto->setTaxaCorrecaoBp($input->taxaCorrecaoBp);
        $objeto->setBaseCorrecao($input->baseCorrecao);
        $objeto->setTaxaHonorariosBp($input->taxaHonorariosBp);
        $objeto->setBaseHonorarios($input->baseHonorarios);
        $objeto->setCarenciaHonorariosDias($input->carenciaHonorariosDias);
        $objeto->setToleranciaJurosMultaDias($input->toleranciaJurosMultaDias);

        $this->objetoRepository->salvar($objeto, true);

        return $objeto;
    }
}
