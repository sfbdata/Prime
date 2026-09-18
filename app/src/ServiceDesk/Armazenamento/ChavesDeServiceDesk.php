<?php

declare(strict_types=1);

namespace App\ServiceDesk\Armazenamento;

use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

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
        return new ChaveDeArquivo(
            self::escopoDe($anexo->getChamado()?->getTenant()?->getId(), 'anexo de chamado'),
            CategoriaDeArquivo::CHAMADO_ANEXO,
            $anexo->getNomeArquivo(),
        );
    }

    /**
     * Arquivo novo (E2.4A): o nome é cunhado pelo storage (D8); o escopo sai do CHAMADO — o
     * mesmo caminho que {@see anexoDeChamado()} percorre para ler.
     */
    public static function novoAnexoDeChamado(Chamado $chamado, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($chamado->getTenant()?->getId(), 'anexo novo de chamado'),
            CategoriaDeArquivo::CHAMADO_ANEXO,
            $extensao,
        );
    }

    private static function escopoDe(?int $tenantId, string $oQue): EscopoDeArquivo
    {
        if ($tenantId === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'Não dá para montar a chave de armazenamento de %s sem o escritório do chamado.',
                $oQue,
            ));
        }

        return EscopoDeArquivo::deTenant($tenantId);
    }
}
