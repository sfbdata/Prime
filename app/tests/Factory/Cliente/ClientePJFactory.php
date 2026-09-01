<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cliente;

use App\Cliente\Entity\ClientePJ;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Factory de Cliente pessoa jurídica — irmã da {@see ClientePFFactory}, criada porque o credor de uma
 * Carteira de Cobrança é PJ em 3 de 3 carteiras de produção (associações de moradores), e é do
 * **nome fantasia** dele que sai o prefixo do nome da pasta judicial
 * (`App\Cobranca\Service\ComporNomeDaPastaJudicial`).
 *
 * `cnpj` e `representanteCpf` nascem com 14 caracteres porque é o tamanho exato das colunas — a
 * máscara cheia do CNPJ (`##.###.###/####-##`) tem 18 e estouraria no flush.
 *
 * @extends PersistentProxyObjectFactory<ClientePJ>
 */
final class ClientePJFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return ClientePJ::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'cep' => self::faker()->numerify('#####-###'),
            'endereco' => self::faker()->streetAddress(),
            'cidade' => self::faker()->city(),
            'estado' => 'DF',
            'tenant' => TenantFactory::new(),
            'cnpj' => self::faker()->unique()->numerify('##############'),
            'razaoSocial' => self::faker()->company(),
            'nomeFantasia' => self::faker()->company(),
            'enderecSede' => self::faker()->streetAddress(),
            'representanteLegal' => self::faker()->name(),
            'representanteCpf' => self::faker()->numerify('###.###.###-##'),
            'representanteRg' => self::faker()->numerify('##.###.###-#'),
            'representanteCargo' => 'SÍNDICO',
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
