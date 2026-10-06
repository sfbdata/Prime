<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\RespostaDeLinguagem;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Service\ProvedorDeLinguagem;

/**
 * O ÚNICO provedor com resposta programada — e mora em tests/ de propósito (regra de ouro da spec).
 * Fila de respostas/falhas, registro dos pedidos recebidos e um modo "não configurado" que imita
 * o `ProvedorNaoConfigurado` sem trocar o alias do container.
 *
 * Igual ao `FakeGoogleDriveClientFactory` do Sync: entra pelo `when@test` do services.yaml.
 */
final class ProvedorFalso implements ProvedorDeLinguagem
{
    public const NOME = 'falso';
    public const MODELO = 'falso-1';

    /** @var list<PedidoDeLinguagem> */
    public array $pedidos = [];

    /** @var list<RespostaDeLinguagem|\Throwable> */
    private array $fila = [];

    private bool $configurado = true;

    public function nome(): string
    {
        return self::NOME;
    }

    public function estaConfigurado(): bool
    {
        return $this->configurado;
    }

    /** Imita a instalação sem provedor: `estaConfigurado()` false e `completar()` indisponível. */
    public function desconfigurar(): void
    {
        $this->configurado = false;
    }

    public function reconfigurar(): void
    {
        $this->configurado = true;
    }

    public function responderCom(
        string $texto,
        string $modelo = self::MODELO,
        ?int $tokensEntrada = 120,
        ?int $tokensSaida = 40,
        ?int $duracaoMs = 15,
    ): void {
        $this->fila[] = new RespostaDeLinguagem($texto, $modelo, $tokensEntrada, $tokensSaida, $duracaoMs);
    }

    /**
     * Resposta no formato que o prompt do Push pede.
     *
     * @param list<array{tipo: string, texto: string}> $pontos
     */
    public function responderComResumo(string $resumo, array $pontos = [], ?string $quem = null): void
    {
        $this->responderCom((string) json_encode(
            ['resumo' => $resumo, 'pontos' => $pontos, 'quem' => $quem],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    public function falharTransitoriamente(string $motivo = 'timeout do provedor'): void
    {
        $this->fila[] = new FalhaDoProvedorException($motivo, transitoria: true);
    }

    public function falharDefinitivamente(string $motivo = 'chave recusada (401)'): void
    {
        $this->fila[] = new FalhaDoProvedorException($motivo, transitoria: false);
    }

    public function completar(PedidoDeLinguagem $pedido): RespostaDeLinguagem
    {
        $this->pedidos[] = $pedido;

        if (!$this->configurado) {
            throw new ProvedorIndisponivelException('Provedor de IA não configurado nesta instalação.');
        }

        if ($this->fila === []) {
            throw new \LogicException('ProvedorFalso sem resposta programada: o teste precisa chamar responderCom()/falhar…() antes.');
        }

        $proxima = array_shift($this->fila);
        if ($proxima instanceof \Throwable) {
            throw $proxima;
        }

        return $proxima;
    }

    public function foiChamado(): bool
    {
        return $this->pedidos !== [];
    }

    public function ultimoPedido(): ?PedidoDeLinguagem
    {
        return $this->pedidos === [] ? null : $this->pedidos[array_key_last($this->pedidos)];
    }

    public function limpar(): void
    {
        $this->pedidos = [];
        $this->fila = [];
        $this->configurado = true;
    }
}
