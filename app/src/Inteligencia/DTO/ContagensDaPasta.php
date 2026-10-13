<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * Totais BRUTOS da pasta, como estão no banco (pasta + tenant explícitos), antes de qualquer
 * limite do contexto. Quem decide quanto disso a IA de fato recebe é o
 * {@see \App\Inteligencia\UseCase\ContarOQueAIaSabeDaPastaUseCase}, com os limites do
 * {@see \App\Inteligencia\Contexto\MontadorDeContextoDaPasta}.
 *
 *   - `documentos`: documentos fora da lixeira;
 *   - `metas`: metas da pasta, qualquer situação;
 *   - `metasAbertasComPrazo`: metas não concluídas que têm prazo;
 *   - `anotacoes`: anotações internas (aba Dados);
 *   - `observacoes`: observações da aba Detalhes.
 */
final readonly class ContagensDaPasta
{
    public function __construct(
        public int $documentos,
        public int $metas,
        public int $metasAbertasComPrazo,
        public int $anotacoes,
        public int $observacoes,
    ) {
    }
}
