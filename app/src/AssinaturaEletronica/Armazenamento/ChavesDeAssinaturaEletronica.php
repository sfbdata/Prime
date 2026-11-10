<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Armazenamento;

use App\AssinaturaEletronica\Entity\ConfiguracaoDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * As chaves de arquivo da tela Assinatura: o PDF enviado e a logo própria de cada documento, a logo padrão do
 * escritório, a rubrica de cada signatário que assinou (etapa 2), a selfie cifrada da Reforçada (etapa 7), o PDF final
 * com selo do documento concluído (contrato da etapa 3, seção 2, item 6) e as versões guardadas do PDF (contrato da
 * etapa 5, seção 2, item 4). O escopo sai da ENTIDADE (documento, versão ou configuração): a chave de um escritório
 * nunca é montada com o id de outro. Categoria isolada por escritório em disco (`uploads/pastas/assinatura/<tenantId>/`).
 */
final class ChavesDeAssinaturaEletronica
{
    private function __construct()
    {
    }

    /** O PDF guardado do documento; nulo quando ele não tem. */
    public static function arquivo(DocumentoParaAssinar $documento): ?ChaveDeArquivo
    {
        return self::chave($documento->getTenant(), $documento->getArquivoEnviadoChave());
    }

    /** A logo própria do documento; nula nas outras logos. */
    public static function logo(DocumentoParaAssinar $documento): ?ChaveDeArquivo
    {
        return self::chave($documento->getTenant(), $documento->getLogoChave());
    }

    /** A logo própria padrão do escritório; nula quando o padrão não é logo própria. */
    public static function logoPadrao(ConfiguracaoDaAssinatura $configuracao): ?ChaveDeArquivo
    {
        return self::chave($configuracao->getTenant(), $configuracao->getLogoPadraoChave());
    }

    /** PDF novo de um documento: o nome é cunhado pelo storage. */
    public static function novoArquivo(DocumentoParaAssinar $documento, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($documento->getTenant()), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $extensao);
    }

    /** Logo própria nova de um documento. */
    public static function novaLogo(DocumentoParaAssinar $documento, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($documento->getTenant()), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $extensao);
    }

    /** Logo padrão nova do escritório (sempre uma cópia: apagar a do documento nunca apaga a padrão). */
    public static function novaLogoPadrao(ConfiguracaoDaAssinatura $configuracao, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($configuracao->getTenant()), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $extensao);
    }

    /** A rubrica (PNG) de quem assinou; nula de quem ainda não assinou. O escritório é o do documento do signatário. */
    public static function rubrica(Signatario $signatario): ?ChaveDeArquivo
    {
        return self::chave($signatario->getDocumento()->getTenant(), $signatario->getRubricaChave());
    }

    /** Rubrica nova de um signatário, gravada na assinatura (a tela manda PNG). */
    public static function novaRubrica(Signatario $signatario, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($signatario->getDocumento()->getTenant()), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $extensao);
    }

    /** A selfie cifrada de quem assinou Reforçada (etapa 7); nula sem selfie. O escritório é o do documento. */
    public static function selfie(Signatario $signatario): ?ChaveDeArquivo
    {
        return self::chave($signatario->getDocumento()->getTenant(), $signatario->getSelfieChave());
    }

    /**
     * Selfie nova de um signatário: o arquivo cifrado pelo {@see \App\AssinaturaEletronica\Service\CofreDaSelfie}
     * (gravado com a extensão `cifrado`).
     */
    public static function novaSelfie(Signatario $signatario, string $extensao): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($signatario->getDocumento()->getTenant()), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $extensao);
    }

    /** O PDF final com selo do documento concluído (etapa 3); nulo enquanto não foi guardado. */
    public static function pdfFinal(DocumentoParaAssinar $documento): ?ChaveDeArquivo
    {
        return self::chave($documento->getTenant(), $documento->getArquivoFinalChave());
    }

    /** PDF final novo de um documento concluído (etapa 3): sempre PDF, o nome é cunhado pelo storage. */
    public static function novoPdfFinal(DocumentoParaAssinar $documento): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($documento->getTenant()), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, 'pdf');
    }

    /** Uma versão guardada do PDF (etapa 5). O escritório é o do documento da versão. */
    public static function versao(VersaoDoPdf $versao): ChaveDeArquivo
    {
        return self::chave($versao->getDocumento()->getTenant(), $versao->getArquivoChave())
            ?? throw new \LogicException('Versão do PDF sem chave de arquivo.');
    }

    /**
     * Versão nova do PDF de um documento (etapa 5): sempre PDF, o nome é cunhado pelo storage. Grava-se o arquivo
     * antes de criar a {@see VersaoDoPdf}, que recebe o nome da chave.
     */
    public static function novaVersao(DocumentoParaAssinar $documento): NovoArquivo
    {
        return new NovoArquivo(self::escopoDe($documento->getTenant()), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, 'pdf');
    }

    private static function chave(Tenant $tenant, ?string $nome): ?ChaveDeArquivo
    {
        if ($nome === null || $nome === '') {
            return null;
        }

        return new ChaveDeArquivo(self::escopoDe($tenant), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $nome);
    }

    private static function escopoDe(Tenant $tenant): EscopoDeArquivo
    {
        return EscopoDeArquivo::deTenant((int) $tenant->getId());
    }
}
