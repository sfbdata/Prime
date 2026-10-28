<?php

declare(strict_types=1);

namespace App\Tenant\Armazenamento;

use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * As chaves de arquivo do escritório (a logo). O escopo sai da ENTIDADE: a chave de um escritório nunca é montada com o
 * id de outro.
 */
final class ChavesDeTenant
{
    private function __construct()
    {
    }

    /** A logo guardada hoje no escritório, ou null quando não há. */
    public static function logo(Tenant $tenant): ?ChaveDeArquivo
    {
        $nome = $tenant->getLogoChave();
        if ($nome === null || $nome === '') {
            return null;
        }

        return new ChaveDeArquivo(EscopoDeArquivo::deTenant((int) $tenant->getId()), CategoriaDeArquivo::TENANT_LOGO, $nome);
    }

    public static function novaLogo(Tenant $tenant, string $extensao): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::deTenant((int) $tenant->getId()), CategoriaDeArquivo::TENANT_LOGO, $extensao);
    }
}
