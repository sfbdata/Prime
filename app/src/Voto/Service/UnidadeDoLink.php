<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;

/**
 * A unidade do link pessoal `/votar/{token}` (spec docs/specs/voto-assembleia-condominio.md, seções 4 e 5).
 *
 * O banco guarda só o hash do token; a busca não depende do filtro de tenant (o morador pode estar logado em outro
 * escritório, ou em nenhum). O escritório vem da própria unidade, e o Voto precisa estar liberado pelo Master para
 * ESSE escritório: sem a liberação, o link se comporta como inexistente (null, a página responde 404).
 */
final class UnidadeDoLink
{
    public function __construct(
        private readonly TokenDaUnidade $tokens,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly LiberacaoDoVotoRepository $liberacoes,
    ) {
    }

    /** A unidade do token, ou null se o token não existe ou o escritório dela não tem o Voto liberado. */
    public function localizar(string $token): ?UnidadeDaAssembleia
    {
        if (1 !== preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        $unidade = $this->unidades->findOneByTokenHash($this->tokens->hash($token));
        if (null === $unidade) {
            return null;
        }

        return true === $this->liberacoes->findDoTenant($unidade->getTenant())?->isLiberado() ? $unidade : null;
    }
}
