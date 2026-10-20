<?php

declare(strict_types=1);

namespace App\Processo\DTO;

/**
 * O bloco "Situação atual" do painel do Push (desenho 1.2.3, dc L.2602-2625), já resolvido: a
 * tela não decide nada. Campos e textos do retorno de `previsaoProc` (dc L.5110-5116).
 *
 * `statusMod` e `confMod` escolhem a cor do selo (dc L.5110 `statusSt`, L.5113 `confSt`).
 * `chave`, `previstoParaConferir` e `textoDoUltimo` não vão para a tela: servem à taxa de acertos
 * (`MontarPainelDoPushUseCase`), que compara a previsão anterior com o que aconteceu.
 */
final readonly class SituacaoAtualOutput
{
    public const STATUS_ENCERRADO  = 'encerrado';
    public const STATUS_TERMINANDO = 'terminando';
    public const STATUS_ANDAMENTO  = 'andamento';

    public function __construct(
        public string $situacao,
        public string $status,
        public string $statusMod,
        public string $ultimo,
        public string $proximo,
        public string $quem,
        public string $prazo,
        public string $previsao,
        public string $dica,
        public string $posterior,
        public string $conf,
        public string $confMod,
        public string $base,
        public ?string $nota,
        public string $chave,
        public string $previstoParaConferir,
        public string $textoDoUltimo,
        public string $aprendizado = '',
    ) {
    }

    /** O rodapé com a taxa de acertos (dc L.5115), que só o UseCase conhece. */
    public function comAprendizado(string $aprendizado): self
    {
        $campos = get_object_vars($this);
        $campos['aprendizado'] = $aprendizado;

        return new self(...$campos);
    }
}
