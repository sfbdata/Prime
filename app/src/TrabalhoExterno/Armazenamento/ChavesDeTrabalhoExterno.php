<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Armazenamento;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DocumentoDemanda;

/**
 * Traduz o documento de uma demanda em {@see ChaveDeArquivo}. O escopo é SEMPRE o escritório
 * solicitante (dono da demanda), mesmo quando quem anexa é o Master: o arquivo é do pedido, e a
 * purga daquele escritório o leva junto.
 */
final class ChavesDeTrabalhoExterno
{
    private function __construct()
    {
    }

    public static function documento(DocumentoDemanda $documento): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            self::escopoDe($documento->getDemanda()),
            CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO,
            $documento->getNomeArmazenado(),
        );
    }

    public static function novoDocumento(DemandaExterna $demanda, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($demanda), CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO, $extensao);
    }

    private static function escopoDe(DemandaExterna $demanda): EscopoDeArquivo
    {
        $tenantId = $demanda->getTenant()->getId();
        if ($tenantId === null) {
            throw new ChaveDeArquivoInvalida('Não dá para montar a chave do documento sem o escritório solicitante.');
        }

        return EscopoDeArquivo::deTenant($tenantId);
    }
}
