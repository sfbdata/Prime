<?php

declare(strict_types=1);

namespace App\Cobranca\Armazenamento;

use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Entity\CarteiraDocumento;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

/**
 * Traduz os três documentos de Cobrança (caso, acordo, carteira) em {@see ChaveDeArquivo} (E2.2).
 *
 * Os três moram no MESMO diretório isolado por tenant (`cobrancas/<tenantId>/`, decisão
 * deliberada registrada em `EnviarDocumentoAcordoUseCase`), então a categoria é uma só:
 * `COBRANCA_DOCUMENTO`. O que muda é a entidade — e é dela que o escopo sai.
 *
 * Os UseCases de exclusão recebem um `$tenant` por parâmetro e já o comparam com o da entidade
 * antes de qualquer coisa. Mesmo assim a chave **nunca** é montada a partir do parâmetro (R1):
 * aqui o isolamento é físico, e um escopo trocado apontaria para a subpasta de outro escritório.
 */
final class ChavesDeCobranca
{
    private function __construct()
    {
    }

    public static function documentoDeCaso(CobrancaDocumento $documento): ChaveDeArquivo
    {
        return self::chave($documento->getTenant(), $documento->getCaminhoArquivo(), 'documento de caso');
    }

    public static function documentoDeAcordo(AcordoDocumento $documento): ChaveDeArquivo
    {
        return self::chave($documento->getTenant(), $documento->getCaminhoArquivo(), 'documento de acordo');
    }

    public static function documentoDeCarteira(CarteiraDocumento $documento): ChaveDeArquivo
    {
        return self::chave($documento->getTenant(), $documento->getCaminhoArquivo(), 'documento de carteira');
    }

    private static function chave(?Tenant $tenant, string $caminhoArquivo, string $oQue): ChaveDeArquivo
    {
        $id = $tenant?->getId();

        if ($id === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'Não dá para montar a chave de armazenamento de %s sem o escritório dono.',
                $oQue,
            ));
        }

        return new ChaveDeArquivo(
            EscopoDeArquivo::deTenant($id),
            CategoriaDeArquivo::COBRANCA_DOCUMENTO,
            $caminhoArquivo,
        );
    }
}
