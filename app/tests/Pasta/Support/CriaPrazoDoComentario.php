<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Support;

use App\Entity\Auth\UserTenant;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Pasta\Service\PrazoDoComentario;
use App\Repository\AuditLogRepository;
use App\Tests\Tenant\Support\CriaPoderSemPrazo;

/**
 * Monta o {@see PrazoDoComentario} de verdade para os testes unitários dos UseCases de editar e excluir comentário
 * (spec `edicao-sem-prazo-master-primario.md`). Sem argumentos vale a regra de sempre (ver {@see CriaPoderSemPrazo}).
 */
trait CriaPrazoDoComentario
{
    use CriaPoderSemPrazo;

    protected function prazoDoComentario(?UserTenant $vinculoDePrimario = null, bool $opcaoLigada = true, ?AuditLogRepository $trilha = null): PrazoDoComentario
    {
        $poder = $this->poderSemPrazo($vinculoDePrimario, $opcaoLigada);

        return new PrazoDoComentario(new JanelaDeEdicaoDeComentario(), $poder, $this->auditoriaSemPrazo($poder, $trilha));
    }
}
