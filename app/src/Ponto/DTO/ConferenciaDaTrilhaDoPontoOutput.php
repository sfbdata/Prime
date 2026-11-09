<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O resultado da conferência da trilha de auditoria de um escritório (E9): íntegra, alterada (com a sequência do
 * primeiro evento que não confere) ou não selada (sem segredo configurado, ou com um trecho inicial gravado antes do
 * selo). O texto é o do cabeçalho da aba Auditoria.
 */
final class ConferenciaDaTrilhaDoPontoOutput
{
    public const INTEGRA = 'integra';
    public const ALTERADA = 'alterada';
    public const NAO_SELADA = 'nao_selada';

    /** Não selada porque o servidor não tem `PONTO_HMAC_SECRET`: nada pode ser conferido. */
    public const SEM_SEGREDO = 'sem_segredo';

    /** Não selada porque os primeiros eventos foram gravados antes do selo; os seguintes conferem. */
    public const TRECHO_INICIAL = 'trecho_inicial';

    private function __construct(
        public readonly string $situacao,
        public readonly ?int $sequenciaAlterada = null,
        public readonly ?string $motivoSemSelo = null,
        public readonly ?int $ultimaSemSelo = null,
    ) {
    }

    public static function integra(): self
    {
        return new self(self::INTEGRA);
    }

    public static function alterada(int $sequencia): self
    {
        return new self(self::ALTERADA, sequenciaAlterada: $sequencia);
    }

    public static function semSegredo(): self
    {
        return new self(self::NAO_SELADA, motivoSemSelo: self::SEM_SEGREDO);
    }

    public static function trechoInicial(int $ultimaSemSelo): self
    {
        return new self(self::NAO_SELADA, motivoSemSelo: self::TRECHO_INICIAL, ultimaSemSelo: $ultimaSemSelo);
    }

    public function texto(): string
    {
        return match (true) {
            $this->situacao === self::INTEGRA => 'Cadeia de eventos íntegra: cada evento é selado com o anterior. Registros originais nunca são sobrescritos.',
            $this->situacao === self::ALTERADA => sprintf('Atenção: a cadeia de eventos foi alterada. O primeiro evento que não confere é o nº %d.', $this->sequenciaAlterada),
            $this->motivoSemSelo === self::TRECHO_INICIAL => sprintf(
                'Os eventos até o nº %d foram gravados antes do selo e não podem ser conferidos. Do nº %d em diante, cada evento é selado com o anterior.',
                $this->ultimaSemSelo,
                (int) $this->ultimaSemSelo + 1,
            ),
            default => 'Cadeia de eventos sem selo: a chave do selo não está configurada no servidor, então os eventos não podem ser conferidos.',
        };
    }
}
