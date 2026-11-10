<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

/**
 * A classe de cada falha do selo (spec assinatura-documentos-etapa-6, tabela 2.1; etapa 8, E8-4). Decide o que o
 * {@see SeloDoPdfFinal} faz: tentar de novo pela mensagem do worker ou guardar o PDF final sem o selo.
 */
enum ClasseDaFalhaNoSelo: string
{
    /** Validador fora do ar, ocupado, sem tempo, sem âncoras ou a ACT sem responder: a mensagem tenta de novo. */
    case Passageira = 'passageira';

    /** Erro do nosso lado (token, rota, pedido malformado): `logger->error` a cada tentativa, e tenta de novo. */
    case Configuracao = 'configuracao';

    /** O mesmo PDF dá o mesmo resultado (ilegível, grande demais, não selável): guarda sem o selo na hora. */
    case Definitiva = 'definitiva';

    /** `503 selo_certificado_invalido`: os `secrets` existem, mas não servem. Pede ação do Samuel; tenta de novo. */
    case CertificadoInvalido = 'certificado_invalido';
}
