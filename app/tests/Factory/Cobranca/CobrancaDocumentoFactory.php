<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Enum\CategoriaDocumentoCobranca;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso` (com o mesmo
 * tenant) explicitamente — os defaults geram tenants independentes. `secao` é opcional (documento
 * pode ficar solto no caso).
 *
 * @extends PersistentProxyObjectFactory<CobrancaDocumento>
 */
final class CobrancaDocumentoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return CobrancaDocumento::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'titulo' => self::faker()->words(3, true),
            'categoria' => CategoriaDocumentoCobranca::Outro,
            'caminhoArquivo' => bin2hex(random_bytes(16)) . '.pdf',
            'nomeOriginal' => 'documento.pdf',
            'mimeType' => 'application/pdf',
            'tamanhoBytes' => 1024,
            'ordem' => 0,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
