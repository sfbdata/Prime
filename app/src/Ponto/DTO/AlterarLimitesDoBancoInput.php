<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Pedido de mudar o limite e/ou o prazo do banco de horas do escritório (E17, P73): os campos "Limite do banco (horas)"
 * e "Prazo de compensação (meses)" da aba Configurações. Cada campo grava ao sair dele, então o pedido costuma trazer
 * só um; o que não vem fica como está. O escritório é o da sessão de quem pede, nunca um id que venha no pedido.
 */
final class AlterarLimitesDoBancoInput
{
    public const LIMITE = 'limiteHoras';
    public const PRAZO = 'prazoMeses';

    public function __construct(
        public readonly User $quem,
        public readonly Tenant $tenant,
        public readonly ?int $limiteHoras,
        public readonly ?int $prazoMeses,
    ) {
    }

    /**
     * Corpo JSON `{limiteHoras?: int, prazoMeses?: int}`. Null quando não vem nenhum dos dois, ou quando um que veio
     * não é número inteiro (vazio, texto, número com casas): a faixa de cada um é o caso de uso que confere.
     *
     * @param array<mixed> $corpo
     */
    public static function doJson(User $quem, Tenant $tenant, array $corpo): ?self
    {
        $temLimite = array_key_exists(self::LIMITE, $corpo);
        $temPrazo = array_key_exists(self::PRAZO, $corpo);
        if (!$temLimite && !$temPrazo) {
            return null;
        }
        $limite = $temLimite ? $corpo[self::LIMITE] : null;
        $prazo = $temPrazo ? $corpo[self::PRAZO] : null;
        if (($temLimite && !is_int($limite)) || ($temPrazo && !is_int($prazo))) {
            return null;
        }

        return new self($quem, $tenant, $limite, $prazo);
    }
}
