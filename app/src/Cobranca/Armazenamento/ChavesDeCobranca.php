<?php

declare(strict_types=1);

namespace App\Cobranca\Armazenamento;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CarteiraDocumento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

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

    /**
     * Arquivo novo (E2.4A): o nome é cunhado pelo storage (D8). O escopo sai do CASO — a entidade
     * que já existe no upload e contra a qual o UseCase confere o tenant do documento novo.
     */
    public static function novoDocumentoDeCaso(CasoCobranca $caso, string $extensao): NovoArquivo
    {
        return self::novo($caso->getTenant(), $extensao, 'documento novo de caso');
    }

    public static function novoDocumentoDeAcordo(Acordo $acordo, string $extensao): NovoArquivo
    {
        return self::novo($acordo->getTenant(), $extensao, 'documento novo de acordo');
    }

    public static function novoDocumentoDeCarteira(Carteira $carteira, string $extensao): NovoArquivo
    {
        return self::novo($carteira->getTenant(), $extensao, 'documento novo de carteira');
    }

    private static function chave(?Tenant $tenant, string $caminhoArquivo, string $oQue): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            self::escopoDe($tenant, $oQue),
            CategoriaDeArquivo::COBRANCA_DOCUMENTO,
            $caminhoArquivo,
        );
    }

    private static function novo(?Tenant $tenant, string $extensao, string $oQue): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($tenant, $oQue),
            CategoriaDeArquivo::COBRANCA_DOCUMENTO,
            $extensao,
        );
    }

    private static function escopoDe(?Tenant $tenant, string $oQue): EscopoDeArquivo
    {
        $id = $tenant?->getId();

        if ($id === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'Não dá para montar a chave de armazenamento de %s sem o escritório dono.',
                $oQue,
            ));
        }

        return EscopoDeArquivo::deTenant($id);
    }
}
