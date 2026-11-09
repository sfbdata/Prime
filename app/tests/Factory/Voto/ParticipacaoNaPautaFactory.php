<?php

declare(strict_types=1);

namespace App\Tests\Factory\Voto;

use App\Voto\Entity\ParticipacaoNaPauta;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

use function Zenstruck\Foundry\memoize;

/**
 * A unidade votou na pauta (sem a opção, seção 2 do contrato). Passe `unidade` e `pauta` da MESMA assembleia: o
 * construtor recusa as de assembleias diferentes. Para simular o voto inteiro, some também no contador da opção
 * (OpcaoDaPautaRepository::somarVoto() ou OpcaoDaPautaFactory::comVotos()).
 *
 * @extends PersistentProxyObjectFactory<ParticipacaoNaPauta>
 */
final class ParticipacaoNaPautaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return ParticipacaoNaPauta::class;
    }

    protected function defaults(): array|callable
    {
        // Uma assembleia só para a unidade e a pauta (memoizada): duas fábricas criariam duas assembleias.
        $assembleia = memoize(static fn () => AssembleiaFactory::new()->publicada()->create()->_real());

        return [
            'unidade' => UnidadeDaAssembleiaFactory::new(['assembleia' => $assembleia]),
            'pauta' => PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->aberta(),
            'registradaEm' => null,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
