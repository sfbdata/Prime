<?php

declare(strict_types=1);

namespace App\Tests\Factory\Voto;

use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Service\TokenDaUnidade;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Unidade apta (situação vazia) do bloco A. O escritório vem da assembleia. O `tokenHash` sai da `semente` com o mesmo
 * segredo do kernel de teste (framework.secret = APP_SECRET), então o link funciona:
 * `UnidadeDaAssembleiaFactory::tokenDe($unidade)` dá o token de `/votar/{token}`.
 *
 * @extends PersistentProxyObjectFactory<UnidadeDaAssembleia>
 */
final class UnidadeDaAssembleiaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return UnidadeDaAssembleia::class;
    }

    /** O token do link pessoal da unidade, calculado como no sistema. */
    public static function tokenDe(UnidadeDaAssembleia $unidade): string
    {
        return self::servicoDoToken()->token($unidade->getSemente());
    }

    protected function defaults(): array
    {
        return [
            'assembleia' => AssembleiaFactory::new(),
            'identificacao' => self::faker()->unique()->numerify('####'),
            'bloco' => 'A',
            'semente' => bin2hex(random_bytes(16)),
            'tokenHash' => null,
            'situacao' => null,
            'fracao' => null,
            'responsavel' => self::faker()->name(),
            'email' => self::faker()->unique()->safeEmail(),
        ];
    }

    public function inapta(): static
    {
        return $this->with(['situacao' => 'inadimplente']);
    }

    protected function initialize(): static
    {
        return $this
            ->beforeInstantiate(static function (array $parametros): array {
                $parametros['tokenHash'] ??= self::servicoDoToken()->hash(self::servicoDoToken()->token((string) $parametros['semente']));

                return $parametros;
            })
            ->instantiateWith(Instantiator::withConstructor()->alwaysForce());
    }

    private static function servicoDoToken(): TokenDaUnidade
    {
        $segredo = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');

        return new TokenDaUnidade(\is_string($segredo) ? $segredo : '');
    }
}
