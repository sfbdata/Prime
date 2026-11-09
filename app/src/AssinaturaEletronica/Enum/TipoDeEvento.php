<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/**
 * Os eventos da trilha de auditoria do documento (contrato da etapa 1, seção 3.1). O texto de cada um sai do catálogo
 * (`assinatura_eletronica.evento.<valor>`), com os parâmetros guardados em `dados`. As etapas seguintes acrescentam casos.
 */
enum TipoDeEvento: string
{
    case RascunhoSalvo = 'rascunho_salvo';
    case DocumentoCriado = 'documento_criado';
    case Duplicado = 'duplicado';
    case MovidoParaLixeira = 'movido_para_lixeira';
    case Restaurado = 'restaurado';
    case EnvioCanceladoPelaExclusao = 'envio_cancelado_pela_exclusao';

    /** A chave do texto do evento no catálogo de idioma. */
    public function chaveDoTexto(): string
    {
        return 'assinatura_eletronica.evento.' . $this->value;
    }
}
