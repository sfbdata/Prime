<?php

declare(strict_types=1);

namespace App\ChatIa\Armazenamento;

use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\ConversaDeChat;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * As chaves de arquivo dos anexos do Chat I.A. O escopo sai da ENTIDADE (a conversa ou o anexo): a chave de um
 * escritório nunca é montada com o id de outro. Categoria plana, subpasta `chat-ia` da raiz dos documentos de pasta.
 */
final class ChavesDeChatIa
{
    private function __construct()
    {
    }

    /** O arquivo de um anexo já gravado. */
    public static function anexo(AnexoDeChat $anexo): ChaveDeArquivo
    {
        return new ChaveDeArquivo(self::escopoDe($anexo->getTenant()), CategoriaDeArquivo::CHAT_IA_ANEXO, $anexo->getChave());
    }

    /** Anexo novo de uma conversa: o nome é cunhado pelo storage (D8). */
    public static function novoAnexo(ConversaDeChat $conversa, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($conversa->getTenant()), CategoriaDeArquivo::CHAT_IA_ANEXO, $extensao);
    }

    private static function escopoDe(?Tenant $tenant): EscopoDeArquivo
    {
        return EscopoDeArquivo::deTenant((int) ($tenant ?? throw new \LogicException('Anexo do chat sem escritório.'))->getId());
    }
}
