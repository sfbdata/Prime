<?php

declare(strict_types=1);

namespace App\Kanban\Armazenamento;

use App\Kanban\Entity\KanbanAnexo;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

/**
 * Traduz o anexo do Kanban em {@see ChaveDeArquivo} (E2.2).
 *
 * `KanbanAnexo::getCaminho()` guarda só o NOME do arquivo (é o retorno de `salvar()`), apesar do
 * nome do campo — a E1 já corrigiu quem o tratava como caminho completo. O tenant é copiado do
 * card na construção da entidade; a fábrica lê os dois dela e de mais nada (R1).
 */
final class ChavesDeKanban
{
    private function __construct()
    {
    }

    public static function anexo(KanbanAnexo $anexo): ChaveDeArquivo
    {
        $id = $anexo->getTenant()?->getId();

        if ($id === null) {
            throw new ChaveDeArquivoInvalida(
                'Não dá para montar a chave de armazenamento de anexo do Kanban sem o escritório dono.',
            );
        }

        return new ChaveDeArquivo(
            EscopoDeArquivo::deTenant($id),
            CategoriaDeArquivo::KANBAN_ANEXO,
            $anexo->getCaminho(),
        );
    }
}
