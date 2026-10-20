<?php

declare(strict_types=1);

namespace App\Pasta\Enum;

/**
 * O que aconteceu no "Histórico de envios" do Avisar cliente (desenho `hist`, dc L.5258): a Análise IA
 * enviada à mão, o envio automático (P4d) e o cadastro de um e-mail.
 */
enum TipoDeEnvioAoCliente: string
{
    case Ia = 'ia';
    case Auto = 'auto';
    case Cadastro = 'cadastro';

    public function icone(): string
    {
        return match ($this) {
            self::Ia => 'bi bi-stars',
            self::Auto => 'bi bi-arrow-repeat',
            self::Cadastro => 'bi bi-envelope-plus',
        };
    }

    /** O título da linha do histórico, com a descrição do envio automático. */
    public function titulo(?string $descricao): string
    {
        return match ($this) {
            self::Ia => 'Análise IA enviada',
            self::Auto => 'Envio automático: ' . ($descricao ?? 'movimentação'),
            self::Cadastro => 'E-mail cadastrado',
        };
    }
}
