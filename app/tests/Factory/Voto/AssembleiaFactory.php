<?php

declare(strict_types=1);

namespace App\Tests\Factory\Voto;

use App\Tests\Factory\Tenant\TenantFactory;
use App\Voto\Entity\Assembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Assembleia de condomínio em rascunho, peso "uma unidade, um voto". Cenário que precisa de consistência de escritório
 * passa o `tenant`; pauta, opção, unidade e participação herdam o escritório da assembleia.
 *
 * @extends PersistentProxyObjectFactory<Assembleia>
 */
final class AssembleiaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Assembleia::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'titulo' => 'Assembleia Geral Ordinária ' . self::faker()->numberBetween(2020, 2030),
            'peso' => PesoDoVoto::Unidade,
            'criadaPorNome' => self::faker()->name(),
        ];
    }

    public function porFracao(): static
    {
        return $this->with(['peso' => PesoDoVoto::Fracao]);
    }

    /**
     * Já publicada, sem passar pelas exigências de publicar() (pauta com opções, unidade apta). Para exercitar a
     * transição de verdade, crie pautas e unidades e chame publicar().
     */
    public function publicada(): static
    {
        return $this->with(['estado' => EstadoDaAssembleia::Publicada, 'publicadaEm' => new \DateTimeImmutable('-1 hour')]);
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce());
    }
}
