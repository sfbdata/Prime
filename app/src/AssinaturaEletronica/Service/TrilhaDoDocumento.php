<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * Acrescenta um evento à trilha do documento, encadeado ao último (contrato da etapa 1, seção 3.2). Roda dentro da
 * transação de quem chama: a linha do documento fica travada até o COMMIT.
 */
final readonly class TrilhaDoDocumento
{
    public function __construct(
        private EventoDaTrilhaRepository $eventos,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @param array<string, mixed> $dados os parâmetros do texto do evento
     */
    public function acrescentar(DocumentoParaAssinar $documento, TipoDeEvento $tipo, array $dados, ?User $usuario): EventoDaTrilha
    {
        $anterior = $this->eventos->ultimoHashTravando($documento);
        $evento = new EventoDaTrilha(
            $documento,
            $tipo,
            $dados,
            \DateTimeImmutable::createFromInterface($this->relogio->now()),
            $anterior,
            $usuario,
        );
        $this->eventos->salvar($evento);

        return $evento;
    }
}
