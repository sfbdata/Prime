<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Exception\AcaoRecusada;

/**
 * A localização que o navegador deu no "Assinar" Reforçado (contrato da etapa 7, seções 5.2 e 6): latitude de -90 a 90,
 * longitude de -180 a 180 e a precisão em metros (opcional), guardadas como texto decimal com as casas das colunas da
 * M1 (8, 8 e 2). Só números do JSON valem: texto, booleano ou faltando recusa.
 */
final readonly class LocalizacaoDoSignatario
{
    public const PRECISAO_MAXIMA = 99999999.99;

    private function __construct(
        public string $latitude,
        public string $longitude,
        public ?string $precisao,
    ) {
    }

    /**
     * @param array<mixed> $json `{latitude, longitude, precisao}`
     *
     * @throws AcaoRecusada
     */
    public static function deJson(array $json): self
    {
        $latitude = self::numero($json['latitude'] ?? null, -90, 90);
        $longitude = self::numero($json['longitude'] ?? null, -180, 180);
        $precisao = ($json['precisao'] ?? null) === null ? null : self::numero($json['precisao'], 0, self::PRECISAO_MAXIMA);

        return new self(sprintf('%.8F', $latitude), sprintf('%.8F', $longitude), $precisao === null ? null : sprintf('%.2F', $precisao));
    }

    /** O texto da tela e da trilha: "latitude, longitude" com 5 casas e ponto (desenho, linha 376). */
    public function texto(): string
    {
        return self::textoDe($this->latitude, $this->longitude);
    }

    /** O mesmo texto a partir do que está gravado (coluna ou `dados` da trilha). */
    public static function textoDe(string $latitude, string $longitude): string
    {
        return sprintf('%.5F, %.5F', (float) $latitude, (float) $longitude);
    }

    /** @throws AcaoRecusada */
    private static function numero(mixed $valor, float $minimo, float $maximo): float
    {
        if ((!\is_int($valor) && !\is_float($valor)) || !is_finite((float) $valor) || $valor < $minimo || $valor > $maximo) {
            throw new AcaoRecusada('assinatura_eletronica.erro.localizacao_invalida');
        }

        return (float) $valor;
    }
}
