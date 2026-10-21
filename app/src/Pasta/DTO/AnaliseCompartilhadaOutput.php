<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDePonto;

/**
 * Um cartão "Análise IA" da página pública ([PC] 79-85): resumo, quando e os pontos com o tipo (que
 * dá a cor da bolinha). Só sai de análise concluída e sem o cadeado "interna do escritório"; quem
 * filtra é o `ConteudoDoPushCompartilhado`. Sai a versão exibida: a edição do advogado, se houver (P4h).
 */
final readonly class AnaliseCompartilhadaOutput
{
    /**
     * @param list<array{tipo: string, texto: string}> $pontos
     */
    private function __construct(
        public string $resumo,
        public string $quando,
        public array $pontos,
    ) {
    }

    public static function fromEntity(AnaliseDeInteligencia $analise): self
    {
        $pontos = [];
        foreach ($analise->getPontosExibidos() as $ponto) {
            $texto = trim($ponto['texto']);
            if ($texto !== '') {
                $pontos[] = ['tipo' => TipoDePonto::deString($ponto['tipo'])->value, 'texto' => $texto];
            }
        }

        return new self(
            resumo: (string) $analise->getResumoExibido(),
            quando: ($analise->getConcluidaEm() ?? $analise->getCriadaEm())->format('d/m/Y H:i'),
            pontos: $pontos,
        );
    }
}
