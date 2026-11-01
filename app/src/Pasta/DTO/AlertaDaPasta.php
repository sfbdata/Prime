<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/** Um alerta da Controladoria da pasta (desenho 02 de 31/10/2026): nível, título, o fato e a providência sugerida. */
final readonly class AlertaDaPasta
{
    public const ALTA = 'alta';
    public const MEDIA = 'media';
    public const BAIXA = 'baixa';

    public function __construct(
        public string $nivel,
        public string $titulo,
        public string $detalhe,
        public string $sugestao,
        /**
         * Identifica o TIPO do alerta (metas, prazos, parada, contrato, unidade, titular, prescricao, cadastro): a rotina
         * de segunda só lança no Registro o que ainda não lançou. Só letras minúsculas: é o que a marca do cartão aceita.
         * Obrigatória: vazia, o alerta saía sem tipo no resumo do --simular e dividia a marca com os outros sem tipo.
         */
        public string $chave,
    ) {
    }

    /** @return array{nivel: string, titulo: string, detalhe: string, sugestao: string} */
    public function paraArray(): array
    {
        return ['nivel' => $this->nivel, 'titulo' => $this->titulo, 'detalhe' => $this->detalhe, 'sugestao' => $this->sugestao];
    }
}
