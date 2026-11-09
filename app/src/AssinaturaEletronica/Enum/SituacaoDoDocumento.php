<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/**
 * Situação de um documento para assinar (contrato da etapa 1, seção 3.1). "Expirado" não é situação gravada: a tela
 * calcula (Aguardando com prazo vencido), como o desenho.
 */
enum SituacaoDoDocumento: string
{
    case Rascunho = 'rascunho';
    case Aguardando = 'aguardando';
    case Concluido = 'concluido';
    case Recusado = 'recusado';
    case Cancelado = 'cancelado';
}
