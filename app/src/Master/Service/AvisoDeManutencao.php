<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Repository\ConfiguracaoGlobalRepository;
use Psr\Clock\ClockInterface;

/**
 * O aviso de manutenção programada (grupo "Manutenção programada" das configurações globais): o texto que o Master
 * escreveu vale para todos os usuários logados até a data e hora "Mostrar o aviso até", lida no fuso configurado.
 * Texto em branco: nenhum aviso. Sem data: o aviso fica até o Master apagar o texto.
 */
final class AvisoDeManutencao
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $repositorio,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function texto(): ?string
    {
        $valores = array_merge(CatalogoDeConfiguracoes::padroes(), $this->repositorio->atual()?->getValores() ?? []);
        $texto = $valores['manut'];
        if ($texto === '') {
            return null;
        }

        if ($valores['manutAte'] !== '') {
            $limite = \DateTimeImmutable::createFromFormat('!' . CatalogoDeConfiguracoes::FORMATO_DATA_HORA, $valores['manutAte'], new \DateTimeZone($valores['fuso']));
            if ($limite !== false && $this->relogio->now() > $limite) {
                return null;
            }
        }

        return $texto;
    }
}
