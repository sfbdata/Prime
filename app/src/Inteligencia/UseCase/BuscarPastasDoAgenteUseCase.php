<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\BuscaDePastasDoAgenteOutput;
use App\Inteligencia\DTO\FiltroDoAgenteInput;
use App\Inteligencia\DTO\PastaDoAgenteOutput;
use App\Inteligencia\Exception\FiltroDoAgenteInvalidoException;
use App\Inteligencia\Service\ConsultaDePastasDoAgente;
use App\Pasta\Entity\Pasta;

/**
 * `buscar_pastas` do Agente de Pastas (Fase 1, só leitura).
 *
 * Quem: um usuário com o módulo Expediente, pela janela do agente (ou, depois, pelo planejador).
 * O quê: achar as pastas do escritório que casam com um filtro, para mostrar ou para planejar uma
 * alteração em lote. Regra central: enxergar exatamente o que a Lista do Expediente enxerga
 * ({@see ConsultaDePastasDoAgente}) e devolver o mesmo texto que ela mostra — o identificador vem do
 * {@see ResolvedorIdentificadorDaPasta}, primado na página inteira numa consulta, como a Lista faz.
 * Uma página de 50, na ordem do NUP, com o total do filtro.
 */
final class BuscarPastasDoAgenteUseCase
{
    public const POR_PAGINA = 50;

    public function __construct(
        private readonly ConsultaDePastasDoAgente $consulta,
        private readonly ResolvedorIdentificadorDaPasta $identificadores,
    ) {
    }

    /** @throws FiltroDoAgenteInvalidoException */
    public function executar(FiltroDoAgenteInput $filtro, Tenant $tenant): BuscaDePastasDoAgenteOutput
    {
        $pastas = $this->consulta->buscar($filtro, $tenant, self::POR_PAGINA);
        $this->identificadores->primeParaPastas($pastas);

        return new BuscaDePastasDoAgenteOutput(
            pastas: array_map(
                fn (Pasta $pasta): PastaDoAgenteOutput => PastaDoAgenteOutput::fromEntity($pasta, $this->identificadores->para($pasta)),
                $pastas,
            ),
            total: $this->consulta->contar($filtro, $tenant),
            pagina: $filtro->pagina,
            porPagina: self::POR_PAGINA,
        );
    }
}
