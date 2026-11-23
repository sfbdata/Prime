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

    /** @var array<string, string> texto da constante => chave no catálogo `ponto_servidor` (idioma-ponto-php B2) */
    public const CHAVE_DO_TEXTO = [
        self::TITULO_FORA => 'ponto_servidor.batida.cerca.titulo_fora',
        self::TITULO_INCERTA => 'ponto_servidor.batida.cerca.titulo_incerta',
        self::DETALHE_FORA_COM_SEDE => 'ponto_servidor.batida.cerca.detalhe_fora_com_sede',
        self::DETALHE_FORA_SEM_SEDE => 'ponto_servidor.batida.cerca.detalhe_fora_sem_sede',
        self::DETALHE_INCERTA => 'ponto_servidor.batida.cerca.detalhe_incerta',
        self::SAIDA_FORA => 'ponto_servidor.batida.cerca.saida_fora',
        self::SAIDA_INCERTA => 'ponto_servidor.batida.cerca.saida_incerta',
    ];

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

    /** A chave no catálogo de uma das constantes de texto; qualquer outro texto é erro de programação. */
    public static function chaveDe(string $texto): string
    {
        return self::CHAVE_DO_TEXTO[$texto] ?? throw new \LogicException(sprintf('Texto da cerca sem chave no catálogo: "%s".', $texto));
    }

    /**
     * O que a pessoa lê quando a batida não entra pela cerca, em português. Com o trabalho externo permitido no
     * escritório, o detalhe termina com a saída por ele.
     *
     * @return array{titulo: string, detalhe: string, trabalhoExterno: bool}
     */
    public function recusa(bool $trabalhoExterno): array
    {
        $recusa = $this->recusaComChave($trabalhoExterno);

        return [
            'titulo' => $recusa['titulo']->portugues,
            'detalhe' => $recusa['detalhe']->portugues . ($recusa['saida']->portugues ?? ''),
            'trabalhoExterno' => $trabalhoExterno,
        ];
    }

    /**
     * A mesma recusa de {@see self::recusa()}, com a chave de cada parte para o controller traduzir: o detalhe e, à
     * parte, a saída pelo trabalho externo (que o português cola ao detalhe, com o espaço da frente). Os metros vão
     * como número para o catálogo (`{distancia, number, integer}`): o pt_BR escreve "1.234", como o `metros()`, e o en
     * escreve "1,234", em vez de um "1.234 m" que em inglês se lê como pouco mais de um metro.
     *
     * @return array{titulo: TextoDoPonto, detalhe: TextoDoPonto, saida: ?TextoDoPonto}
     */
    public function recusaComChave(bool $trabalhoExterno): array
    {
        if ($this->situacao === self::DENTRO) {
            throw new \LogicException('A batida dentro do raio não tem recusa da cerca.');
        }
        if ($this->situacao === self::INCERTA) {
            return [
                'titulo' => self::texto(self::TITULO_INCERTA),
                'detalhe' => new TextoDoPonto(self::chaveDe(self::DETALHE_INCERTA), ['precisao' => (int) round($this->precisao)], sprintf(self::DETALHE_INCERTA, self::metros($this->precisao))),
                'saida' => $trabalhoExterno ? self::texto(self::SAIDA_INCERTA) : null,
            ];
        }

        return [
            'titulo' => self::texto(self::TITULO_FORA),
            'detalhe' => $this->sede === null || $this->distancia === null || $this->raio === null
                ? self::texto(self::DETALHE_FORA_SEM_SEDE)
                : new TextoDoPonto(
                    self::chaveDe(self::DETALHE_FORA_COM_SEDE),
                    ['sede' => (string) $this->sede->getNome(), 'distancia' => (int) round($this->distancia), 'raio' => $this->raio],
                    sprintf(self::DETALHE_FORA_COM_SEDE, (string) $this->sede->getNome(), self::metros($this->distancia), self::metros($this->raio)),
                ),
            'saida' => $trabalhoExterno ? self::texto(self::SAIDA_FORA) : null,
        ];
    }

    /** @param array<string, string> $parametros */
    private static function texto(string $texto, array $parametros = []): TextoDoPonto
    {
        return TextoDoPonto::de(self::chaveDe($texto), $texto, $parametros);
    }

    /** Metros arredondados, com o milhar separado por ponto (como o `toLocaleString('pt-BR')` da tela). */
    private static function metros(float|int $valor): string
    {
        return number_format(round($valor), 0, ',', '.');
    }
}
