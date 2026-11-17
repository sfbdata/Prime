<?php

declare(strict_types=1);

namespace App\Voto\Exception;

/** O modelo .docx (papel timbrado) enviado para a minuta de ata não serve. A mensagem, do desenho, pode ir para a tela. */
final class ModeloDeAtaInvalidoException extends \DomainException
{
}
