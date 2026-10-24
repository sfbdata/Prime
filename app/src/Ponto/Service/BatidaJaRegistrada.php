<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * O pedido de esquecimento só vale para a batida que falta (spec `ponto-esquecimento-nao-trava.md`, revisão de
 * 23/10): com uma batida do mesmo tipo no mesmo dia neste escritório, o pedido é recusado, na criação e na edição.
 * Fecha o desvio da interjornada (bater a saída às 22:00 e depois pedir o esquecimento de "saída às 22:05", que,
 * aprovado, viraria a última saída e só daria aviso, R4).
 */
final class BatidaJaRegistrada
{
    /** A recusa do pedido: o tipo da batida, como a pessoa lê. */
    public const MENSAGEM = 'Já existe um registro de %s neste dia.';

    private const ROTULOS = [
        RegistroPonto::TIPO_ENTRADA => 'entrada',
        RegistroPonto::TIPO_REPOUSO => 'repouso',
        RegistroPonto::TIPO_RETORNO => 'retorno',
        RegistroPonto::TIPO_SAIDA => 'saída',
    ];

    public function __construct(
        private readonly RegistroPontoRepository $registros,
    ) {
    }

    /** A recusa do pedido de esquecimento de `$tipo` em `$dia`, ou null quando a batida falta de fato. */
    public function mensagem(User $user, Tenant $tenant, string $tipo, \DateTimeInterface $dia): ?string
    {
        $batidas = $this->registros->batidasDoDiaNoEscritorio($user, $tenant, \DateTimeImmutable::createFromInterface($dia));
        foreach ($batidas as $batida) {
            if ($batida->getTipo() === $tipo) {
                return sprintf(self::MENSAGEM, self::ROTULOS[$tipo] ?? $tipo);
            }
        }

        return null;
    }
}
