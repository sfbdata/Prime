<?php

declare(strict_types=1);

namespace App\Tests\Factory\Voto;

use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Pauta aguardando. O escritório vem da assembleia (o construtor não aceita outro). Os estados `aberta()` e
 * `encerrada()` gravam direto, sem passar por abrir()/encerrar(): para exercitar a transição, chame o método.
 *
 * @extends PersistentProxyObjectFactory<PautaDaAssembleia>
 */
final class PautaDaAssembleiaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return PautaDaAssembleia::class;
    }

    protected function defaults(): array
    {
        return [
            'assembleia' => AssembleiaFactory::new(),
            'ordem' => 1,
            'titulo' => 'Aprovação das contas',
            'descricao' => null,
        ];
    }

    /** As opções do cenário condomínio: 1 Sim, 2 Não e a abstenção (número 0). */
    public function comOpcoesPadrao(): static
    {
        return $this->afterInstantiate(static function (PautaDaAssembleia $pauta): void {
            new OpcaoDaPauta($pauta, 1, 'Sim');
            new OpcaoDaPauta($pauta, 2, 'Não');
            new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        });
    }

    public function aberta(int $convocacao = 1, ?\DateTimeImmutable $prazoAte = null): static
    {
        return $this->with([
            'estado' => EstadoDaPauta::Aberta,
            'convocacao' => $convocacao,
            'quorumExigido' => 1 === $convocacao ? '50.00' : '0.00',
            'abertaEm' => new \DateTimeImmutable('-10 minutes'),
            'abertaPorNome' => 'Mesa',
            'prazoAte' => $prazoAte,
        ]);
    }

    public function encerrada(int $convocacao = 1): static
    {
        return $this->aberta($convocacao)->with([
            'estado' => EstadoDaPauta::Encerrada,
            'encerradaEm' => new \DateTimeImmutable('-1 minute'),
            'encerradaPorNome' => 'Mesa',
            'motivoEncerramento' => PautaDaAssembleia::MOTIVO_DA_MESA,
        ]);
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce());
    }
}
