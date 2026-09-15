<?php

declare(strict_types=1);

namespace App\ServiceDesk\Armazenamento;

use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

/**
 * Traduz o anexo de chamado em {@see ChaveDeArquivo} (E2.2).
 *
 * `ChamadoAnexo` não carrega tenant próprio: no modelo real o escritório é o do `Chamado` a que
 * o anexo pertence, e é por esse caminho que a fábrica chega ao escopo. Sem chamado, ou com
 * chamado sem escritório, não existe chave — recusa, em vez de chutar (R1).
 *
 * Primeiro arquivo em `App\ServiceDesk\`: o domínio ainda mora em `src/Entity/ServiceDesk/`
 * (legado, ver `app/src/CLAUDE.md`), e arquivo novo não entra em pasta legada.
 */
final class ChavesDeServiceDesk
{
    private function __construct()
    {
    }

    public static function anexoDeChamado(ChamadoAnexo $anexo): ChaveDeArquivo
    {
        $id = $anexo->getChamado()?->getTenant()?->getId();

        if ($id === null) {
            throw new ChaveDeArquivoInvalida(
                'Não dá para montar a chave de armazenamento de anexo de chamado sem o escritório do chamado.',
            );
        }

        return new ChaveDeArquivo(
            EscopoDeArquivo::deTenant($id),
            CategoriaDeArquivo::CHAMADO_ANEXO,
            $anexo->getNomeArquivo(),
        );
    }
}
