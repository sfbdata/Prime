<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * A lista da carteira (já com busca e Estado aplicados) passa do teto de linhas do "Exportar lista
 * (.xlsx)". Não é erro de sistema: o usuário recorta a lista e exporta em partes. A mensagem é a que
 * vai para a tela.
 */
final class ListaGrandeDemaisParaExportarException extends \DomainException
{
    public function __construct(int $limite)
    {
        parent::__construct(sprintf(
            'A lista tem mais de %s linhas. Use a busca ou o filtro de Estado para exportar em partes.',
            number_format($limite, 0, ',', '.'),
        ));
    }
}
