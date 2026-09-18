<?php

declare(strict_types=1);

namespace App\Cliente\Armazenamento;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Traduz o documento do cliente em {@see ChaveDeArquivo} (E2.2).
 *
 * O escopo sai da entidade e só dela (R1); o nome vai byte a byte (D8). A justificativa completa
 * mora em `App\Pasta\Armazenamento\ChavesDePasta` — aqui vale a mesma regra, para a categoria
 * `CLIENTE_DOCUMENTO`, cujo diretório em disco é plano e compartilhado entre escritórios: é
 * exatamente o caso em que um tenant errado passaria despercebido no backend local.
 */
final class ChavesDeCliente
{
    private function __construct()
    {
    }

    public static function documento(ClienteDocumento $documento): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            self::escopoDe($documento->getTenant()?->getId(), 'documento de cliente'),
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            $documento->getCaminhoArquivo(),
        );
    }

    /**
     * Arquivo novo (E2.4A): o nome é cunhado pelo storage (D8); o escopo sai do cliente, de quem o
     * documento copia o escritório na criação.
     */
    public static function novoDocumento(Cliente $cliente, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($cliente->getTenant()?->getId(), 'documento novo de cliente'),
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            $extensao,
        );
    }

    private static function escopoDe(?int $tenantId, string $oQue): EscopoDeArquivo
    {
        if ($tenantId === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'Não dá para montar a chave de armazenamento de %s sem o escritório dono.',
                $oQue,
            ));
        }

        return EscopoDeArquivo::deTenant($tenantId);
    }
}
