<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DocumentoDoCurriculo;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * "Ver currículo formatado / PDF" e "Ver e baixar" (spec VS 18.10.26 §4.5, decisão 12c): o
 * currículo salvo em HTML (a pré-visualização em iframe) ou em PDF. Quem vê o currículo vê o
 * documento: a Central inteira e o próprio dono. Consulta, aceita a prévia do Master.
 */
final class GerarDocumentoDoCurriculoUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly DocumentoDoCurriculo $documento,
    ) {
    }

    /** @return array{conteudo: string, nome: string} */
    public function executar(Contexto $ctx, string $codigo, bool $pdf): array
    {
        $cv = $this->localizador->localizar($ctx, $codigo);
        $dados = $this->documento->dadosDe($cv);
        $nome = 'curriculo-' . (new AsciiSlugger('pt_BR'))->slug($cv->texto('nome'))->lower()->toString();

        return $pdf
            ? ['conteudo' => $this->documento->pdf($dados, $cv), 'nome' => $nome . '.pdf']
            : ['conteudo' => $this->documento->html($dados, $cv), 'nome' => $nome . '.html'];
    }
}
