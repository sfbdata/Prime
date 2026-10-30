<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

/**
 * O cartão da proposta como a tela o recebe (evento SSE `proposta` e `propostas` da conversa aberta):
 *
 * `{id, tipo, estado, expiraEm, pessoa?, campos?, pasta?, erros?, duplicada?, semelhantes?, resultado?}`
 *
 * - `campos` é a lista EXATA de `{chave, rotulo, valor}` que será gravada (o JSON que o servidor guardou, nunca o texto do
 *   modelo); `pasta` traz `{nomeCliente, nomeAcao, nup}`. Só existem enquanto a proposta está pendente: depois de decidida
 *   ou vencida ela já não guarda dado pessoal;
 * - `erros` mapeia `campos.<chave>` / `pasta.<campo>` para a mensagem (a chave vazia é erro geral);
 * - `duplicada` é o cadastro do escritório que já tem o CPF/CNPJ: `{id, nome, documento, podeUsarExistente}`;
 * - `semelhantes` é o aviso de pasta parecida: `{lista: [{id, nup, nomeCliente, nomeAcao}], total}`;
 * - `resultado` (confirmada): `{tipo, id}` do que foi criado.
 */
final readonly class PropostaOutput
{
    /**
     * @param list<array{chave: string, rotulo: string, valor: string}>|null              $campos
     * @param array{nomeCliente: string, nomeAcao: string, nup: string}|null              $pasta
     * @param array<string, string>                                                        $erros
     * @param array{id: int, nome: string, documento: string, podeUsarExistente: bool}|null $duplicada
     * @param array{lista: list<array{id: int, nup: string, nomeCliente: string, nomeAcao: string}>, total: int}|null $semelhantes
     * @param array{tipo: string, id: int}|null                                            $resultado
     */
    public function __construct(
        public int $id,
        public string $tipo,
        public string $estado,
        public string $expiraEm,
        public ?string $pessoa = null,
        public ?array $campos = null,
        public ?array $pasta = null,
        public array $erros = [],
        public ?array $duplicada = null,
        public ?array $semelhantes = null,
        public ?array $resultado = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        $saida = ['id' => $this->id, 'tipo' => $this->tipo, 'estado' => $this->estado, 'expiraEm' => $this->expiraEm];
        foreach (['pessoa' => $this->pessoa, 'campos' => $this->campos, 'pasta' => $this->pasta, 'duplicada' => $this->duplicada, 'semelhantes' => $this->semelhantes, 'resultado' => $this->resultado] as $chave => $valor) {
            if ($valor !== null) {
                $saida[$chave] = $valor;
            }
        }
        if ($this->erros !== []) {
            $saida['erros'] = $this->erros;
        }

        return $saida;
    }
}
