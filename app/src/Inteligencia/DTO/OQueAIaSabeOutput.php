<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * A grade "O que a IA sabe desta pasta" do drawer (desenho, dc L861-864 e L5691). Quatro dos
 * cinco números do desenho, cada um de uma contagem real no banco:
 *
 *   - `documentos`: documentos da pasta fora da lixeira;
 *   - `tarefas`: metas da pasta, qualquer situação;
 *   - `prazos`: metas ainda não concluídas que têm prazo;
 *   - `anotacoes`: anotações internas (aba Dados) + observações da aba Detalhes.
 *
 * O 5º do desenho ("fontes" = soma + 2) é heurística do protótipo e fica de fora.
 */
final readonly class OQueAIaSabeOutput
{
    public function __construct(
        public int $documentos,
        public int $tarefas,
        public int $prazos,
        public int $anotacoes,
    ) {
    }

    /**
     * Na ordem e com os rótulos do desenho (`[docs, 'documentos'], [metas, 'tarefas'], …`).
     *
     * @return list<array{chave: string, valor: int, rotulo: string}>
     */
    public function itens(): array
    {
        return [
            ['chave' => 'documentos', 'valor' => $this->documentos, 'rotulo' => 'documentos'],
            ['chave' => 'tarefas', 'valor' => $this->tarefas, 'rotulo' => 'tarefas'],
            ['chave' => 'prazos', 'valor' => $this->prazos, 'rotulo' => 'prazos'],
            ['chave' => 'anotacoes', 'valor' => $this->anotacoes, 'rotulo' => 'anotações'],
        ];
    }
}
