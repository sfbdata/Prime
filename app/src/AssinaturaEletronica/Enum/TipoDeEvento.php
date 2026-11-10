<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/**
 * Os eventos da trilha de auditoria do documento (contrato da etapa 1, seção 3.1; etapa 2, seção 4.3; etapa 3, seção 2,
 * item 9; etapa 5, seção 2, item 5). O texto de cada um sai do catálogo (`assinatura_eletronica.evento.<valor>`), com
 * os parâmetros guardados em `dados` e o `{nome}` do signatário (ou do usuário) do próprio evento.
 */
enum TipoDeEvento: string
{
    case RascunhoSalvo = 'rascunho_salvo';
    case DocumentoCriado = 'documento_criado';
    case Duplicado = 'duplicado';
    case MovidoParaLixeira = 'movido_para_lixeira';
    case Restaurado = 'restaurado';
    case EnvioCanceladoPelaExclusao = 'envio_cancelado_pela_exclusao';

    // Etapa 2: envio e assinatura Simples.
    case DocumentoEnviado = 'documento_enviado';
    case ConviteEnviado = 'convite_enviado';
    case EmailNaoEnviado = 'email_nao_enviado';
    case LinkAberto = 'link_aberto';
    case CodigoEnviado = 'codigo_enviado';
    case CodigoErrado = 'codigo_errado';
    case Assinado = 'assinado';
    case Recusado = 'recusado';
    case DocumentoConcluido = 'documento_concluido';
    case LembreteEnviado = 'lembrete_enviado';
    case EnvioCancelado = 'envio_cancelado';

    // Etapa 3: o vínculo à pasta, com o `{nup}` (e o `{motivo}` do não aplicado) em `dados`.
    case VinculoDefinido = 'vinculo_definido';
    case VinculoFeito = 'vinculo_feito';
    case VinculoNaoAplicado = 'vinculo_nao_aplicado';

    // Etapa 5: gov.br e ICP. O PDF para assinar foi congelado (E5-1), com o `{sha256}` em `dados`; o PDF devolvido foi
    // recusado, com o código do `{motivo}` em `dados` (o arquivo recusado não é guardado).
    case PdfParaAssinarGerado = 'pdf_para_assinar_gerado';
    case PdfAssinadoRecusado = 'pdf_assinado_recusado';

    /** A chave do texto do evento no catálogo de idioma. */
    public function chaveDoTexto(): string
    {
        return 'assinatura_eletronica.evento.' . $this->value;
    }

    /**
     * Evidência da etapa 2: leva `dados.chave` com a versão do segredo que gerou o link e o código (contrato da etapa 2,
     * seção 3). Os eventos da etapa 1 ficam como nasceram.
     */
    public function levaVersaoDaChave(): bool
    {
        return !\in_array($this, [
            self::RascunhoSalvo,
            self::DocumentoCriado,
            self::Duplicado,
            self::MovidoParaLixeira,
            self::Restaurado,
            self::EnvioCanceladoPelaExclusao,
        ], true);
    }
}
