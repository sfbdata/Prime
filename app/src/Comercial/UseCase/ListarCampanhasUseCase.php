<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\CampanhaDaLista;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Repository\CampanhaDeEmailRepository;

/**
 * A lista de campanhas do bloco Monitoramento (dc L.282-286 e L.586): as 12 últimas, com o nome, a linha "modelo ·
 * público[ · frequência]" (a frequência como o dc a escreve), a data (a agendada, ou a da confirmação), o total e o
 * estado. As métricas são da fatia 3 (a tela diz "Métricas: aguarda servidor", como o dc).
 *
 * @phpstan-import-type Campanha from CampanhaDeEmailRepository
 */
final class ListarCampanhasUseCase
{
    public const QUANTAS = 12;
    /** Os 3 rótulos do dc (L.586) e os demais estados em texto. */
    public const ROTULOS = [
        CampanhaDeEmail::NA_FILA => 'Na fila', CampanhaDeEmail::AGENDADA => 'Agendada', CampanhaDeEmail::RECORRENTE => 'Recorrente',
        CampanhaDeEmail::ENVIANDO => 'Enviando', CampanhaDeEmail::ENVIADA => 'Enviada', CampanhaDeEmail::CANCELADA => 'Cancelada',
    ];

    public function __construct(private readonly CampanhaDeEmailRepository $campanhas)
    {
    }

    /** @return list<CampanhaDaLista> */
    public function executar(): array
    {
        return array_map(self::linha(...), $this->campanhas->ultimas(self::QUANTAS));
    }

    /** @param Campanha $c */
    private static function linha(array $c): CampanhaDaLista
    {
        $sub = $c['modelo_nome'] . ' · ' . (PublicosDaCampanha::ROTULOS[$c['publico']] ?? $c['publico'])
            . ($c['frequencia'] !== CampanhaDeEmail::UNICA ? ' · ' . $c['frequencia'] : '');
        $em = (new \DateTimeImmutable($c['agendada_para'] ?? $c['criada_em']))->format('d/m/Y H:i');

        return new CampanhaDaLista($c['id'], $c['nome'], $sub, $em, $c['total'], $c['estado'], self::ROTULOS[$c['estado']] ?? $c['estado']);
    }
}
