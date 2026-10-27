<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\TrabalhoExterno\Service\ApresentadorDeTermo;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoTermo;

/**
 * O termo MAIS RECENTE do currículo (o estado do currículo é o dele; `termoDe`, desenho 06 de 25/10, l.1126). Resumo
 * sem CPF nem RG: o termo completo só sai como HTML ({@see GerarDocumentoDoTermoUseCase}). Quem não pode ver o termo
 * recebe `termo: null`, como se não houvesse. Consulta: não grava nada.
 */
final class ConsultarTermoDoCurriculoUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoTermo $politica,
        private readonly TermoDeVoluntariadoRepository $termos,
        private readonly ApresentadorDeTermo $apresentador,
    ) {
    }

    /** @return array{termo: array<string, mixed>|null} */
    public function executar(Contexto $ctx, string $codigoDoCurriculo): array
    {
        $cv = $this->localizador->localizar($ctx, $codigoDoCurriculo);
        $termo = $this->termos->maisRecenteDo($cv);

        return ['termo' => $termo !== null && $this->politica->podeVerCompleto($ctx, $termo) ? $this->apresentador->resumo($ctx, $termo) : null];
    }
}
