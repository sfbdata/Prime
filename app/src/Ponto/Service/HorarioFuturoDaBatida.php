<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use Psr\Clock\ClockInterface;

/**
 * Regra única da batida em horário futuro: a data e a hora pedidas não podem ficar acima de agora.
 * A mesma data e hora de agora passam.
 *
 * A régua é a da gravação: a batida (`RegistroPonto`) nasce de `new \DateTime(data hora)`, no fuso
 * padrão do servidor, e a batida ao vivo e a tela usam o mesmo relógio. Ler no fuso da sede recusaria,
 * num escritório em Manaus, uma batida que no relógio do servidor já passou.
 *
 * Vale no lote do admin, no pedido de esquecimento do colaborador e na aprovação desse pedido.
 */
final class HorarioFuturoDaBatida
{
    public const MENSAGEM = 'Não é possível registrar batida em horário futuro.';

    /** @var array<string, string> texto da constante => chave no catálogo `ponto_servidor` (idioma-ponto-php B2) */
    public const CHAVE_DO_TEXTO = [
        self::MENSAGEM => 'ponto_servidor.batida.horario_futuro.mensagem',
    ];

    public function __construct(
        private readonly ClockInterface $relogio,
    ) {
    }

    /** A chave no catálogo de uma das constantes de texto; qualquer outro texto é erro de programação. */
    public static function chaveDe(string $texto): string
    {
        return self::CHAVE_DO_TEXTO[$texto] ?? throw new \LogicException(sprintf('Texto do horário futuro sem chave no catálogo: "%s".', $texto));
    }

    /**
     * O texto de uma das constantes, com a chave para o controller traduzir.
     *
     * @param array<string, string> $parametros
     */
    public static function texto(string $texto, array $parametros = []): TextoDoPonto
    {
        return TextoDoPonto::de(self::chaveDe($texto), $texto, $parametros);
    }

    public function estaNoFuturo(\DateTimeInterface $data, \DateTimeInterface $hora): bool
    {
        $batida = new \DateTimeImmutable(
            $data->format('Y-m-d') . ' ' . $hora->format('H:i:s'),
            new \DateTimeZone(date_default_timezone_get()),
        );

        return $batida > $this->relogio->now();
    }
}
