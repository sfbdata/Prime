<?php

declare(strict_types=1);

namespace App\Tests\Agenda;

use App\Agenda\Contract\EnviadorDePush;
use App\Agenda\Entity\InscricaoDePush;

/** Envio de push de mentira para os testes: guarda o que seria enviado e devolve as inscrições "vencidas". */
final class EnviadorDePushFalso implements EnviadorDePush
{
    /** @var list<array{inscricao: InscricaoDePush, conteudo: array<string, string>}> */
    public array $enviados = [];

    /** @param list<string> $endpointsVencidos */
    public function __construct(
        private readonly bool $ligado = true,
        private readonly array $endpointsVencidos = [],
    ) {
    }

    public function ligado(): bool
    {
        return $this->ligado;
    }

    public function chavePublica(): ?string
    {
        return $this->ligado ? 'CHAVE-PUBLICA-DE-TESTE' : null;
    }

    public function enviar(array $envios): array
    {
        array_push($this->enviados, ...$envios);

        return array_values(array_unique(array_filter(
            array_map(static fn (array $e): InscricaoDePush => $e['inscricao'], $envios),
            fn (InscricaoDePush $i): bool => in_array($i->getEndpoint(), $this->endpointsVencidos, true),
        ), \SORT_REGULAR));
    }
}
