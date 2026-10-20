<?php

declare(strict_types=1);

namespace App\Cliente\Enum;

/**
 * O rótulo de um e-mail do cliente no "Avisar cliente" do Push (spec push-avisar-cliente §1; desenho
 * `ROT`, dc L.5252). `Cadastro` é só do e-mail que veio da ficha do cliente (`Cliente.email`): não é
 * escolha do formulário.
 */
enum RotuloDoEmail: string
{
    case Pessoal = 'pessoal';
    case Trabalho = 'trabalho';
    case Familiar = 'familiar';
    case Outro = 'outro';
    case Cadastro = 'cadastro';

    /**
     * Os rótulos que o formulário oferece, na ordem dos chips do desenho.
     *
     * @return list<self>
     */
    public static function doFormulario(): array
    {
        return [self::Pessoal, self::Trabalho, self::Familiar, self::Outro];
    }

    /** O rótulo pedido pelo formulário; null se não for um dos quatro chips. */
    public static function doPedido(string $valor): ?self
    {
        $rotulo = self::tryFrom($valor);

        return $rotulo !== null && $rotulo !== self::Cadastro ? $rotulo : null;
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::Pessoal => 'Pessoal',
            self::Trabalho => 'Trabalho',
            self::Familiar => 'Familiar',
            self::Outro => 'Outro',
            self::Cadastro => 'Cadastro',
        };
    }
}
