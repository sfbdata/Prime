<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Sede;
use Symfony\Component\HttpFoundation\Response;

/**
 * Onde a batida ficou na cerca (spec ponto-desenho-0811 §5 E12), dito por {@see CercaDoPonto}: a situação, a sede que
 * decide (a aceita, na DENTRO; a mais próxima, nas outras; nenhuma, sem sede válida), a distância até ela, o raio e a
 * precisão da leitura. Fora e incerta também dizem a recusa que a pessoa lê, no POST /ponto/batida.
 */
final readonly class PosicaoNaCerca
{
    public const DENTRO = 'dentro';
    public const INCERTA = 'incerta';
    public const FORA = 'fora';

    public const TITULO_FORA = 'Você está fora do raio permitido';
    public const TITULO_INCERTA = 'GPS impreciso';
    public const DETALHE_FORA_COM_SEDE = 'A sede mais próxima é %s, a %s m. O raio permitido é de %s m.';
    public const DETALHE_FORA_SEM_SEDE = 'O registro exige estar dentro do raio de uma sede do escritório.';
    public const DETALHE_INCERTA = 'A precisão do GPS (%s m) não basta para confirmar que você está no raio da sede. Atualize a localização e tente de novo.';
    public const SAIDA_FORA = ' Aproxime-se ou informe o trabalho externo.';
    public const SAIDA_INCERTA = ' Se está fora da sede, informe o trabalho externo.';

    private function __construct(
        public string $situacao,
        public ?Sede $sede,
        public ?float $distancia,
        public ?int $raio,
        public float $precisao,
    ) {
    }

    public static function dentro(Sede $sede, float $distancia, int $raio, float $precisao): self
    {
        return new self(self::DENTRO, $sede, $distancia, $raio, $precisao);
    }

    public static function incerta(Sede $sede, float $distancia, int $raio, float $precisao): self
    {
        return new self(self::INCERTA, $sede, $distancia, $raio, $precisao);
    }

    public static function fora(?Sede $maisProxima, ?float $distancia, ?int $raio, float $precisao): self
    {
        return new self(self::FORA, $maisProxima, $distancia, $raio, $precisao);
    }

    public function estaDentro(): bool
    {
        return $this->situacao === self::DENTRO;
    }

    /** O status da recusa: 403 fora do raio, 422 com o GPS impreciso (a pessoa pode tentar de novo). */
    public function statusDaRecusa(): int
    {
        return $this->situacao === self::INCERTA ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_FORBIDDEN;
    }

    /**
     * O que a pessoa lê quando a batida não entra pela cerca. Com o trabalho externo permitido no escritório, o detalhe
     * termina com a saída por ele.
     *
     * @return array{titulo: string, detalhe: string, trabalhoExterno: bool}
     */
    public function recusa(bool $trabalhoExterno): array
    {
        if ($this->situacao === self::DENTRO) {
            throw new \LogicException('A batida dentro do raio não tem recusa da cerca.');
        }
        if ($this->situacao === self::INCERTA) {
            $titulo = self::TITULO_INCERTA;
            $detalhe = sprintf(self::DETALHE_INCERTA, self::metros($this->precisao)) . ($trabalhoExterno ? self::SAIDA_INCERTA : '');
        } else {
            $titulo = self::TITULO_FORA;
            $detalhe = ($this->sede === null || $this->distancia === null || $this->raio === null
                ? self::DETALHE_FORA_SEM_SEDE
                : sprintf(self::DETALHE_FORA_COM_SEDE, (string) $this->sede->getNome(), self::metros($this->distancia), self::metros($this->raio)))
                . ($trabalhoExterno ? self::SAIDA_FORA : '');
        }

        return ['titulo' => $titulo, 'detalhe' => $detalhe, 'trabalhoExterno' => $trabalhoExterno];
    }

    /** Metros arredondados, com o milhar separado por ponto (como o `toLocaleString('pt-BR')` da tela). */
    private static function metros(float|int $valor): string
    {
        return number_format(round($valor), 0, ',', '.');
    }
}
