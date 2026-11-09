<?php

declare(strict_types=1);

namespace App\Tests\Factory\Voto;

use App\Voto\Entity\OpcaoDaPauta;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Opção de voto de uma pauta. O escritório vem da pauta. `comVotos()` grava o contador na criação (atalho de teste):
 * em produção ele só muda por OpcaoDaPautaRepository::somarVoto(), na transação do voto.
 *
 * @extends PersistentProxyObjectFactory<OpcaoDaPauta>
 */
final class OpcaoDaPautaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return OpcaoDaPauta::class;
    }

    protected function defaults(): array
    {
        return [
            'pauta' => PautaDaAssembleiaFactory::new(),
            'numero' => 1,
            'rotulo' => 'Sim',
            'abstencao' => false,
        ];
    }

    public function abstencao(): static
    {
        return $this->with(['numero' => 0, 'rotulo' => 'Abstenção', 'abstencao' => true]);
    }

    /** @param string $peso decimal com ponto */
    public function comVotos(int $unidades, string $peso): static
    {
        return $this->with(['totalUnidades' => $unidades, 'totalPeso' => $peso]);
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce());
    }
}
