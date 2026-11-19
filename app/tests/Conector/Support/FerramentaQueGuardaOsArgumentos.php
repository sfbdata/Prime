<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Ferramenta\FerramentaDoConector;

/**
 * Embrulha uma ferramenta de verdade e guarda os argumentos que o despacho entregou a ela, para o teste conferir o
 * formato (sem `\stdClass` em nenhum nível) antes de a ferramenta responder. Os campos de `$semTipo` perdem o tipo no
 * esquema, para o SDK deixar passar um objeto neles, como passaria num campo de objeto.
 */
final class FerramentaQueGuardaOsArgumentos implements FerramentaDoConector
{
    /** @var array<string, mixed>|null */
    public ?array $recebidos = null;

    /** @param list<string> $semTipo */
    public function __construct(private readonly FerramentaDoConector $ferramenta, private readonly array $semTipo = [])
    {
    }

    public function nome(): string
    {
        return $this->ferramenta->nome();
    }

    public function titulo(): string
    {
        return $this->ferramenta->titulo();
    }

    public function descricao(): string
    {
        return $this->ferramenta->descricao();
    }

    public function esquemaDeEntrada(): array
    {
        $esquema = $this->ferramenta->esquemaDeEntrada();
        foreach ($this->semTipo as $campo) {
            $esquema['properties'][$campo] = ['description' => 'Sem tipo no teste.'];
        }

        return $esquema;
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $this->recebidos = $argumentos;

        return $this->ferramenta->executar($argumentos, $chamada);
    }
}
