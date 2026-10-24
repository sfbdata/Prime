<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O escritório deu o aceite da BlueJus IA numa versão do texto que não cita os documentos ({@see \App\Inteligencia\Enum\VersaoDoAceiteDaIa}):
 * a IA segue valendo para o resto, mas o "Ler N documentos" não sai. O controller responde 409 com a mensagem;
 * nada é persistido nem conta no limitador.
 */
final class AceiteDesatualizadoException extends \DomainException
{
    public const MOTIVO = 'aceite_desatualizado';

    public function __construct()
    {
        parent::__construct('A leitura de documentos espera o aceite novo do escritório: o administrador renova o aceite na configuração da BlueJus IA.');
    }

    public function motivo(): string
    {
        return self::MOTIVO;
    }
}
