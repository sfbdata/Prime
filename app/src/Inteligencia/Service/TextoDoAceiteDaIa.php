<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

/**
 * Versão do texto do aceite da BlueJus IA (`/admin/inteligencia`, `inteligencia.admin.envio.*`). O aceite do
 * escritório é a data em `ConfiguracaoDeInteligencia::consentimentoEnvioExternoEm`; ele vale para o que o texto
 * dizia quando foi dado.
 *
 * Desde {@see COM_DOCUMENTOS_DESDE} (PR F da spec ia-anthropic-ligar) o texto cita que o conteúdo dos documentos
 * escolhidos pela pessoa vai ao provedor. Aceite anterior continua valendo para o resto da IA, mas NÃO cobre a
 * leitura de documentos: o "Ler N documentos" fica bloqueado até o administrador renovar o aceite (salvar a
 * configuração com a IA ligada grava o aceite de novo).
 *
 * Mudar o texto do aceite para enviar algo novo = nova data aqui (e a regra que a usa), nunca editar o texto sem
 * mexer na data.
 */
final class TextoDoAceiteDaIa
{
    /** Quando o texto passou a citar os documentos escolhidos (24/10/2026, PR F). */
    public const COM_DOCUMENTOS_DESDE = '2026-10-24T17:00:00-03:00';

    public static function comDocumentosDesde(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::COM_DOCUMENTOS_DESDE);
    }

    /** O aceite foi dado com o texto que já cita os documentos? Sem aceite, não. */
    public static function cobreDocumentos(?\DateTimeImmutable $aceite): bool
    {
        return $aceite !== null && $aceite >= self::comDocumentosDesde();
    }
}
