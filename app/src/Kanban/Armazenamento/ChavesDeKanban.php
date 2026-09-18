<?php

declare(strict_types=1);

namespace App\Kanban\Armazenamento;

use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanCard;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

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
        return new ChaveDeArquivo(
            self::escopoDe($anexo->getTenant()?->getId(), 'anexo do Kanban'),
            CategoriaDeArquivo::KANBAN_ANEXO,
            $anexo->getCaminho(),
        );
    }

    /**
     * Arquivo novo (E2.4A): o nome é cunhado pelo storage (D8); o escopo sai do CARD, de quem o
     * anexo copia o escritório no construtor — o mesmo que {@see anexo()} vai ler depois.
     */
    public static function novoAnexo(KanbanCard $card, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($card->getTenant()?->getId(), 'anexo novo do Kanban'),
            CategoriaDeArquivo::KANBAN_ANEXO,
            $extensao,
        );
    }

    private static function escopoDe(?int $tenantId, string $oQue): EscopoDeArquivo
    {
        if ($tenantId === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'Não dá para montar a chave de armazenamento de %s sem o escritório dono.',
                $oQue,
            ));
        }

        return EscopoDeArquivo::deTenant($tenantId);
    }
}
