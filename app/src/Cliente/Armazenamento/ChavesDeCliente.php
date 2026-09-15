<?php

declare(strict_types=1);

namespace App\Cliente\Armazenamento;

use App\Cliente\Entity\ClienteDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

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
        $id = $documento->getTenant()?->getId();

        if ($id === null) {
            throw new ChaveDeArquivoInvalida(
                'Não dá para montar a chave de armazenamento de documento de cliente sem o escritório dono.',
            );
        }

        return new ChaveDeArquivo(
            EscopoDeArquivo::deTenant($id),
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            $documento->getCaminhoArquivo(),
        );
    }
}
