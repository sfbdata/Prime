<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Versão do texto do aceite da BlueJus IA (`/admin/inteligencia`, `inteligencia.admin.envio.*`), gravada em
 * `inteligencia_configuracao.consentimento_versao` junto com a data do aceite. O aceite vale para o que o
 * texto dizia na versão gravada.
 *
 * - {@see SemDocumentos} (1): o texto até o PR F. As linhas anteriores à migration `Version20261024150400`
 *   ficaram com ela.
 * - {@see ComDocumentos} (2): o texto cita que o conteúdo dos documentos escolhidos pela pessoa vai ao
 *   provedor (PR F da spec ia-anthropic-ligar). Só esta versão (ou maior) libera o "Ler N documentos".
 *
 * Aceite de versão menor continua valendo para o resto da IA; o administrador renova salvando a configuração
 * com a IA ligada, o que grava a versão {@see atual()}. Mudar o texto do aceite para enviar algo novo = caso
 * novo aqui (e a regra que o usa), nunca editar o texto sem mudar a versão.
 */
enum VersaoDoAceiteDaIa: int
{
    case SemDocumentos = 1;
    case ComDocumentos = 2;

    /** A versão do texto que a tela mostra hoje: é a que um aceite novo grava. */
    public static function atual(): self
    {
        return self::ComDocumentos;
    }

    /** O aceite gravado nesta versão cobre a leitura de documentos? */
    public static function cobreDocumentos(int $versaoGravada): bool
    {
        return $versaoGravada >= self::ComDocumentos->value;
    }
}
