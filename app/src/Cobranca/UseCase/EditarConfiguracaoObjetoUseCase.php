<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarConfiguracaoObjetoInput;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\FormaHonorarios;
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
 *
 * Guarda de servidor (revisão da branch inteira, I-1): quando a carteira do objeto tem
 * `FormaHonorarios::SemPercentual`, a FORMA de cobrar honorário só existe na carteira — sem forma, um
 * override de alíquota no objeto divergiria do split de pagamento (que zera por forma=SemPercentual)
 * enquanto o exigível ainda cobraria honorário via `CalculadoraEncargos`. O campo desabilitado no HTML
 * (`_campos_config_encargos.html.twig`) é só UX; um POST forjado não pode gravar o override. Por isso o
 * override de honorário do objeto é sempre anulado aqui quando a carteira não cobra percentual,
 * independentemente do que veio no Input.
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

        // Guarda de servidor: carteira sem forma percentual de honorário não pode ter override no
        // objeto — o disabled do HTML é só UX, aqui é a rede real (I-1 da revisão da branch inteira).
        if ($objeto->getCarteira()?->getFormaHonorarios() === FormaHonorarios::SemPercentual) {
            $objeto->setTaxaHonorariosBp(null);
            $objeto->setBaseHonorarios(null);
        }

        $this->objetoRepository->salvar($objeto, true);

        return $objeto;
    }
}
