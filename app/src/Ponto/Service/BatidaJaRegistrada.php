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

    /** @var array<string, string> texto da constante => chave no catálogo `ponto_servidor` (idioma-ponto-php B2) */
    public const CHAVE_DO_TEXTO = [
        self::MENSAGEM => 'ponto_servidor.batida.ja_registrada.mensagem',
    ];

    /** @var array<string, string> rótulo em português do tipo => chave no catálogo */
    public const CHAVE_DO_ROTULO = [
        'entrada' => 'ponto_servidor.batida.ja_registrada.tipo_entrada',
        'repouso' => 'ponto_servidor.batida.ja_registrada.tipo_repouso',
        'retorno' => 'ponto_servidor.batida.ja_registrada.tipo_retorno',
        'saída' => 'ponto_servidor.batida.ja_registrada.tipo_saida',
    ];

    public function __construct(
        private readonly RegistroPontoRepository $registros,
    ) {
    }

    /** A chave no catálogo da frase (a constante MENSAGEM); qualquer outro texto é erro de programação. */
    public static function chaveDe(string $texto): string
    {
        return self::CHAVE_DO_TEXTO[$texto] ?? throw new \LogicException(sprintf('Texto da batida já registrada sem chave no catálogo: "%s".', $texto));
    }

    /** A recusa do pedido de esquecimento de `$tipo` em `$dia`, em português, ou null quando a batida falta de fato. */
    public function mensagem(User $user, Tenant $tenant, string $tipo, \DateTimeInterface $dia): ?string
    {
        return $this->mensagemComChave($user, $tenant, $tipo, $dia)?->portugues;
    }

    /** A mesma recusa de {@see self::mensagem()}, com a chave; o tipo (`{tipo}`) é um texto traduzível à parte. */
    public function mensagemComChave(User $user, Tenant $tenant, string $tipo, \DateTimeInterface $dia): ?TextoDoPonto
    {
        $batidas = $this->registros->batidasDoDiaNoEscritorio($user, $tenant, \DateTimeImmutable::createFromInterface($dia));
        foreach ($batidas as $batida) {
            if ($batida->getTipo() === $tipo) {
                $rotulo = self::ROTULOS[$tipo] ?? null;

                return TextoDoPonto::de(self::chaveDe(self::MENSAGEM), self::MENSAGEM, [
                    'tipo' => $rotulo === null ? $tipo : TextoDoPonto::de(self::CHAVE_DO_ROTULO[$rotulo], $rotulo),
                ]);
            }
        }

        return null;
    }
}
