<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\CarteiraDocumento;
use App\Cobranca\Enum\CategoriaDocumentoCarteira;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `carteira` (com o
 * mesmo tenant) explicitamente — os defaults geram tenants independentes.
 *
 * @extends PersistentProxyObjectFactory<CarteiraDocumento>
 */
final class CarteiraDocumentoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return CarteiraDocumento::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'carteira' => CarteiraFactory::new(),
            'titulo' => self::faker()->words(3, true),
            'categoria' => CategoriaDocumentoCarteira::Outro,
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
