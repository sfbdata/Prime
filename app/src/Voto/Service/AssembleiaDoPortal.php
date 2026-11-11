<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Entity\Assembleia;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Porta pública: nunca confia no escritório da sessão nem aceita rascunho ou escritório bloqueado. */
final class AssembleiaDoPortal
{
    public function __construct(private readonly AssembleiaRepository $assembleias, private readonly LiberacaoDoVotoRepository $liberacoes)
    {
    }

    public function exigir(string $codigo): Assembleia
    {
        $a = $this->assembleias->buscarPublicadaPeloCodigo($codigo);
        if ($a === null || $a->getTenant()->isActive() !== true || $a->getTenant()->getExcluidoEm() !== null) {
            throw new NotFoundHttpException();
        }
        if ($this->liberacoes->findDoTenantParaOMaster($a->getTenant())?->isLiberado() !== true) {
            throw new NotFoundHttpException();
        }

        return $a;
    }
}
