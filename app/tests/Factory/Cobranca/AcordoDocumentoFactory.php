<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Enum\CategoriaDocumentoAcordo;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `acordo` (com o mesmo
 * tenant) explicitamente — os defaults geram tenants independentes.
 *
 * @extends PersistentProxyObjectFactory<AcordoDocumento>
 */
final class AcordoDocumentoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return AcordoDocumento::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'acordo' => AcordoFactory::new(),
            'titulo' => self::faker()->words(3, true),
            'categoria' => CategoriaDocumentoAcordo::Outro,
            'caminhoArquivo' => bin2hex(random_bytes(16)) . '.pdf',
            'nomeOriginal' => 'documento.pdf',
            'mimeType' => 'application/pdf',
            'tamanhoBytes' => 1024,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
