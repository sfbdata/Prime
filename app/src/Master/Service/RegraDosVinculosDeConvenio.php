<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Entity\Tenant\Tenant;
use App\Master\DTO\ContextoDosVinculos;
use App\Master\DTO\EscritorioParaConvenio;
use App\Master\DTO\MudancasDoVinculo;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Enum\OrigemDoVinculo;

/**
 * A regra dos vínculos de um escritório, sem banco (spec docs/specs/convenios.md, §5.1, §5.2 e §6.1). Idempotente:
 * rodar duas vezes no mesmo estado não muda nada.
 *
 * - Vínculo aberto de convênio fora da validade ou desligado: fecha (`validade` ou `convenio_desligado`).
 * - Vínculo OAB aberto: fecha sem estado válido (`estado_mudou`) e com a UF "sem convênio" (o motivo do contexto). Sem
 *   OAB válida do criador, marca a prova pendente e fecha depois de 30 dias (`prova_divergente`); a prova de volta
 *   tira a marca. O convênio OAB é nacional: mudar de um estado com convênio para outro não fecha nada.
 * - Sem vínculo OAB aberto, cria um quando cabe: chave geral ligada, convênio OAB vigente, escritório fora da
 *   quarentena, UF válida e não desligada, e OAB válida do criador.
 * - Escritório em quarentena: nada é criado; o que está aberto segue até a purga.
 */
final class RegraDosVinculosDeConvenio
{
    public const DIAS_DE_PROVA_PENDENTE = 30;

    /** @param list<ConvenioVinculo> $abertos */
    public function aplicar(Tenant $tenant, EscritorioParaConvenio $escritorio, array $abertos, ContextoDosVinculos $contexto): MudancasDoVinculo
    {
        $mudancas = new MudancasDoVinculo();
        $oabAberto = false;
        foreach ($abertos as $vinculo) {
            $motivo = $this->motivoDoFim($vinculo, $escritorio, $contexto, $mudancas);
            if ($motivo !== null) {
                // Nunca antes do início (relógio para trás): encerrar não pode recusar no meio da volta.
                $vinculo->encerrar($motivo, max($contexto->agora, $vinculo->getInicio()));
                ++$mudancas->encerrados;
                continue;
            }
            $oabAberto = $oabAberto || $vinculo->getConvenio()->ehOab();
        }
        if (!$oabAberto && $this->cabeOab($escritorio, $contexto) && $contexto->oab !== null) {
            $mudancas->novo = new ConvenioVinculo($tenant, $contexto->oab, OrigemDoVinculo::Oab, $contexto->agora);
        }

        return $mudancas;
    }

    private function motivoDoFim(ConvenioVinculo $vinculo, EscritorioParaConvenio $escritorio, ContextoDosVinculos $contexto, MudancasDoVinculo $mudancas): ?MotivoDoFimDoVinculo
    {
        $convenio = $vinculo->getConvenio();
        if (!$convenio->vigenteEm($contexto->agora)) {
            $vencido = $convenio->getValidoAte() !== null && $convenio->getValidoAte()->format('Y-m-d') < $contexto->agora->format('Y-m-d');

            return $vencido ? MotivoDoFimDoVinculo::Validade : MotivoDoFimDoVinculo::ConvenioDesligado;
        }
        if (!$convenio->ehOab()) {
            return null;
        }
        if ($escritorio->uf === null) {
            return MotivoDoFimDoVinculo::EstadoMudou;
        }
        if ($contexto->ufDesligada($escritorio->uf)) {
            return $contexto->motivoDaUf;
        }

        return $this->conferirProva($vinculo, $escritorio, $contexto, $mudancas);
    }

    private function conferirProva(ConvenioVinculo $vinculo, EscritorioParaConvenio $escritorio, ContextoDosVinculos $contexto, MudancasDoVinculo $mudancas): ?MotivoDoFimDoVinculo
    {
        if ($escritorio->oabValida) {
            $mudancas->provasDeVolta += $vinculo->limparProvaPendente() ? 1 : 0;

            return null;
        }
        $mudancas->provasPendentes += $vinculo->marcarProvaPendente($contexto->agora) ? 1 : 0;
        $prazo = $contexto->agora->modify('-' . self::DIAS_DE_PROVA_PENDENTE . ' days');

        return $vinculo->getProvaPendenteDesde() <= $prazo ? MotivoDoFimDoVinculo::ProvaDivergente : null;
    }

    private function cabeOab(EscritorioParaConvenio $escritorio, ContextoDosVinculos $contexto): bool
    {
        return $contexto->chaveLigada
            && $contexto->oab?->vigenteEm($contexto->agora) === true
            && !$escritorio->emQuarentena
            && $escritorio->uf !== null
            && !$contexto->ufDesligada($escritorio->uf)
            && $escritorio->oabValida;
    }
}
