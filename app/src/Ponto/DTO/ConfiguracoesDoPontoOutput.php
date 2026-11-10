<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O conteúdo da aba Configurações do Ponto para quem pode configurar (E7, P67). `ehMaster` mostra a chave "Global"
 * de cada função e troca o texto de ajuda (desenho, `cfgTxt` L798).
 */
final class ConfiguracoesDoPontoOutput
{
    /**
     * @param list<FuncaoDoPontoOutput> $funcoes
     */
    public function __construct(
        public readonly bool $ehMaster,
        public readonly array $funcoes,
    ) {
    }
}
