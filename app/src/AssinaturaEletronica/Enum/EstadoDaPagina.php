<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/**
 * O que a tela pública do signatário mostra (contrato da etapa 2, seção 5.3, campo `estado` do `Publico`). Só "pronto"
 * abre o formulário; os demais são os bloqueios do desenho (inventário G), e "invalido" é o link que não existe.
 */
enum EstadoDaPagina: string
{
    case Pronto = 'pronto';
    case AguardandoAnterior = 'aguardando_anterior';
    case PrazoEncerrado = 'prazo_encerrado';
    case Cancelado = 'cancelado';
    case Recusado = 'recusado';
    case Concluido = 'concluido';
    case JaAssinado = 'ja_assinado';
    case Invalido = 'invalido';

    /** A chave do texto que o servidor devolve quando a ação pública esbarra neste estado. */
    public function chaveDoTexto(): string
    {
        return 'assinatura_eletronica.publica.estado.' . $this->value;
    }
}
