<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * "Baixar PDF com selo" com o limitador da geração estourado (por usuário na tela; por IP e por token no link). Só o
 * pedido que gera de verdade conta: o download do PDF já guardado não passa pelo limitador. Vira 429 com "Muitos PDFs
 * gerados nos últimos minutos. Tente de novo em alguns minutos.".
 */
final class MuitosPdfsGerados extends GeracaoDoPdfRecusada
{
    public const CHAVE = 'assinatura_eletronica_tela.js.pdf.limite';

    public function __construct()
    {
        parent::__construct(self::CHAVE);
    }
}
