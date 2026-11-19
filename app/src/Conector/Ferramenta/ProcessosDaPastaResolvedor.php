<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Service\PermissionChecker;

/**
 * Acha o processo e a nota técnica do escritório da pessoa e faz as conferências que valem para mais de uma ação da
 * `processos_da_pasta`: segredo (6.2.3), recurso `processo` de editar e o envio automático ao cliente principal.
 */
final class ProcessosDaPastaResolvedor
{
    public function __construct(
        private readonly ProcessoRepository $processos,
        private readonly NotaTecnicaRepository $notas,
        private readonly PermissionChecker $permissoes,
        private readonly EnvioAutomaticoDoClienteRepository $envios,
    ) {
    }

    /**
     * O processo do escritório, por id: alheio ou inexistente é "não encontrado", em segredo é a recusa do segredo.
     * `$daPasta` exige que já esteja vinculado a esta pasta.
     *
     * @throws ErroDaFerramenta
     */
    public function processo(int $processoId, Pasta $pasta, Tenant $escritorio, bool $daPasta): Processo
    {
        $processo = $this->processos->findOneByIdDoTenant($processoId, $escritorio);
        if (!$processo instanceof Processo || ($daPasta && !$pasta->temProcesso($processo))) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        if ($processo->emSegredoDeJustica()) {
            throw ErroDaFerramenta::emSegredo();
        }

        return $processo;
    }

    /** A nota é do escritório E deste processo: o id sozinho não abre nota de outro. */
    public function nota(int $notaId, Processo $processo, Tenant $escritorio): NotaTecnica
    {
        $nota = $this->notas->findOneByIdDoTenant($notaId, $escritorio);
        if ($nota === null || $nota->getProcesso()->getId() !== $processo->getId()) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return $nota;
    }

    /** Editar o processo (cartão e nota técnica) pede o recurso `processo` de editar, como a tela. */
    public function exigirEdicaoDoProcesso(Processo $processo, ChamadaDoConector $chamada): void
    {
        $contexto = $chamada->contexto;
        if (!$this->permissoes->canAccessResource($contexto->pessoa, $contexto->escritorio, AccessRequest::RESOURCE_PROCESSO, (int) $processo->getId(), AccessRequest::ACTION_EDIT)) {
            throw ErroDaFerramenta::semPermissao();
        }
    }

    /**
     * Pasta cujo cliente principal tem o envio automático ligado (qualquer chave): as publicações do processo vinculado
     * passariam a ir para ele, então a chamada inteira é recusada (abertura da 6.6).
     *
     * @throws ErroDaFerramenta
     */
    public function exigirSemEnvioAutomatico(Pasta $pasta, Tenant $escritorio): void
    {
        $principal = $pasta->getClientePrincipal();
        $linha = $principal === null ? null : $this->envios->doClienteDoTenant($principal, $escritorio);
        if ($linha === null) {
            return;
        }
        foreach (ChaveDoEnvioAutomatico::cases() as $chave) {
            if ($linha->ligada($chave)) {
                throw ErroDaFerramenta::envioAutomatico();
            }
        }
    }
}
