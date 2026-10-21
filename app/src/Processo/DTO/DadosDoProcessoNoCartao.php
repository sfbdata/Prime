<?php

declare(strict_types=1);

namespace App\Processo\DTO;

/**
 * O cartão do processo como o desenho o monta (`pjeVals`): os 11 campos com o valor efetivo (manual
 * prevalece sobre o base), o selo "manual", o resumo, as marcas e a linha de fonte. Só dado real:
 * sem valor, o campo é nulo e a tela diz "Não informado".
 */
final readonly class DadosDoProcessoNoCartao
{
    /**
     * @param list<array{chave: string, rotulo: string, tipo: string, placeholder: string, manual: bool, texto: ?string, itens: list<string>, flag: ?bool, formulario: string|bool|null}> $campos
     * @param list<array{rotulo: string, valor: ?string}> $resumo
     * @param list<array{rotulo: string, icone: string}> $marcas
     */
    public function __construct(
        public array $campos,
        public array $resumo,
        public array $marcas,
        public string $fonteTexto,
        public string $fonteIcone,
        public bool $temIntegracao,
    ) {
    }
}
