<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RecursosDoSistema;

/**
 * A liberação do conector de I.A. num escritório, nos dois níveis da decisão 14.a (decisão do Samuel, 19/11) (`docs/specs/mcp-remoto-oauth.md`),
 * conferida na autorização, na troca do código e a cada requisição do `/mcp` (9.2). Na renovação, não: desligar ou
 * bloquear só suspende, e a renovação continua girando dentro dos 7 dias
 * ({@see ConferenciaDaConcessao::exigirNaRenovacao()}); no `/mcp` a suspensão dá 403, sem despacho:
 *
 * 1. a chave geral `conector_ia` do Master ({@see RecursosDoSistema}; sem registro, vale ligada);
 * 2. o conector ligado pelo administrador do escritório, com o aceite da versão vigente. O Master não libera
 *    escritório por escritório: a chave geral é só o interruptor de emergência.
 *
 * Escritório sem linha em `conector_ia_escritorio` não está ligado. Falha fechada: qualquer nível que falte recusa
 * com {@see AcessoDoConectorRecusado::liberacao()}.
 */
final class LiberacaoDoConector
{
    /** A chave do conector no `CatalogoDeRecursos` (nível 1, interruptor de emergência). */
    public const CHAVE_GERAL = 'conector_ia';

    public function __construct(
        private readonly RecursosDoSistema $recursos,
        private readonly EscritorioDoConectorRepository $escritorios,
    ) {
    }

    /** @throws AcessoDoConectorRecusado com o motivo {@see AcessoDoConectorRecusado::MOTIVO_LIBERACAO} */
    public function exigir(Tenant $tenant): void
    {
        if (!$this->recursos->estaAtivo(self::CHAVE_GERAL)) {
            throw AcessoDoConectorRecusado::liberacao();
        }

        $escritorio = $this->escritorios->buscarDoEscritorio($tenant);
        if (!$escritorio instanceof EscritorioDoConector || !$escritorio->ligadoComAceite()) {
            throw AcessoDoConectorRecusado::liberacao();
        }
    }
}
