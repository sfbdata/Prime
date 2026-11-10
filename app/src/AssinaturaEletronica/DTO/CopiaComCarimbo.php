<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

/**
 * A "Cópia com carimbo visível" pronta para a resposta, num de quatro desfechos:
 *
 * - carimbada: o nome do download ("{nome do documento} - com carimbo.pdf", dc:384) e os bytes carimbados;
 * - sem original: o documento não tem PDF assinado guardado, ou o arquivo dele sumiu (o "Original não encontrado"
 *   do desenho, dc:384);
 * - sem assinatura: nenhuma assinatura eletrônica do BlueJus para carimbar (o aviso sem " Use o original.", dc:384);
 * - recusada: o motivo da recusa do {@see \App\AssinaturaEletronica\Service\CarimboVisivel}, sem o " Use o original."
 *   que a tela acrescenta no aviso vermelho (dc:384).
 *
 * Fora a carimbada, `erro` é o texto do aviso.
 */
final readonly class CopiaComCarimbo
{
    private function __construct(
        public ?string $nome,
        public ?string $pdf,
        public ?string $erro,
        public bool $semOriginal,
        public bool $semAssinatura,
    ) {
    }

    public static function carimbada(string $nome, string $pdf): self
    {
        return new self($nome, $pdf, null, false, false);
    }

    public static function semOriginal(string $erro): self
    {
        return new self(null, null, $erro, true, false);
    }

    public static function semAssinatura(string $erro): self
    {
        return new self(null, null, $erro, false, true);
    }

    public static function recusada(string $motivo): self
    {
        return new self(null, null, $motivo, false, false);
    }
}
