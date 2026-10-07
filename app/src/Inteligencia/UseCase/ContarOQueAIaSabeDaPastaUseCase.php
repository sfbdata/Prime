<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\FonteDeContagensDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\DTO\ContagensDaPasta;
use App\Inteligencia\DTO\OQueAIaSabeOutput;
use App\Pasta\Entity\Pasta;

/**
 * Grade "O que a IA sabe desta pasta" (IA-006, desenho dc L861-864). Quem: quem pode ver a pasta e
 * usar a IA (guarda do controller do painel). O quê: quatro números desta pasta, deste escritório.
 * Por quê: mostrar, antes do clique, o volume do que os agentes vão ler.
 *
 * IA-006b: o número é o que a IA RECEBE, não o que a pasta tem. Cada seção do contexto tem teto no
 * {@see MontadorDeContextoDaPasta}; a grade usa as MESMAS constantes (nunca uma cópia), mostra
 * `min(total, teto)` e, quando o teto corta, diz quais itens entram e de quantos. A frase segue a
 * ordem real de leitura da {@see \App\Inteligencia\Contexto\FonteDeDadosDaPasta}:
 *
 *   - documentos: `carregadoEm` decrescente → "os N mais recentes";
 *   - metas: abertas primeiro (prazo mais próximo antes), depois as concluídas mais recentes;
 *   - prazos de metas abertas: são as primeiras da ordem das metas (prazo crescente, sem prazo por
 *     último no PostgreSQL), então o teto que as corta é o das metas → "os N mais próximos";
 *   - anotações: anotações (aba Dados) e observações (aba Detalhes) são duas seções com tetos
 *     próprios, cada uma lida da mais recente → "as mais recentes de cada aba".
 *
 * A Pasta chega já conferida pelo chamador (tenant + `canAccessResource`); o tenant vai explícito
 * para a fonte mesmo assim.
 */
final class ContarOQueAIaSabeDaPastaUseCase
{
    public function __construct(
        private readonly FonteDeContagensDaPasta $contagens,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): OQueAIaSabeOutput
    {
        return self::doQueAIaRecebe($this->contagens->contar($tenant, $pasta));
    }

    /**
     * Aplica os tetos do montador aos totais brutos. Público e estático para o teste que amarra a
     * grade ao montador de verdade (mesma entrada, mesmo número).
     */
    public static function doQueAIaRecebe(ContagensDaPasta $c): OQueAIaSabeOutput
    {
        $documentos = min($c->documentos, MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS);
        $metas = min($c->metas, MontadorDeContextoDaPasta::LIMITE_METAS);
        $prazos = min($c->metasAbertasComPrazo, MontadorDeContextoDaPasta::LIMITE_METAS);
        $anotacoes = min($c->anotacoes, MontadorDeContextoDaPasta::LIMITE_ANOTACOES)
            + min($c->observacoes, MontadorDeContextoDaPasta::LIMITE_OBSERVACOES);
        $totalDeAnotacoes = $c->anotacoes + $c->observacoes;

        return new OQueAIaSabeOutput([
            self::item('documentos', $documentos, $c->documentos, 'documentos', 'lê os %d mais recentes de %d'),
            self::item('tarefas', $metas, $c->metas, 'tarefas', 'lê %d de %d, abertas primeiro'),
            self::item('prazos', $prazos, $c->metasAbertasComPrazo, 'prazos de metas abertas', 'lê os %d mais próximos de %d'),
            self::item('anotacoes', $anotacoes, $totalDeAnotacoes, 'anotações', 'lê %d de %d, as mais recentes de cada aba'),
        ]);
    }

    /**
     * @return array{chave: string, valor: int, total: int, rotulo: string, corte: ?string}
     */
    private static function item(string $chave, int $valor, int $total, string $rotulo, string $frase): array
    {
        return [
            'chave' => $chave,
            'valor' => $valor,
            'total' => $total,
            'rotulo' => $rotulo,
            'corte' => $total > $valor ? sprintf($frase, $valor, $total) : null,
        ];
    }
}
