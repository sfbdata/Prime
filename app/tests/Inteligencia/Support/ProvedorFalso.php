<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Inteligencia\DTO\ChamadaDeFerramenta;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\RespostaDeLinguagem;
use App\Inteligencia\DTO\UsoDaTransmissao;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorDeLinguagemEmFluxo;

/**
 * O ÚNICO provedor com resposta programada — e mora em tests/ de propósito (regra de ouro da spec).
 * Fila de respostas/falhas, registro dos pedidos recebidos e um modo "não configurado" que imita
 * o `ProvedorNaoConfigurado` sem trocar o alias do container.
 *
 * Os anexos do pedido (PR F) ficam no pedido registrado, para o teste conferir o que iria ao provedor; o falso não
 * os lê.
 *
 * Também é o {@see ProvedorDeLinguagemEmFluxo} da suíte (Chat I.A em página inteira): `transmitirTrechos()` programa os
 * trechos e o consumo de uma resposta em fluxo; `transmitirTrechosEFalhar()` rende os trechos e então lança. Os pedidos
 * do fluxo também ficam em `$pedidos`. `transmitirComFerramenta()` programa também as chamadas de ferramenta (tool use).
 *
 * Igual ao `FakeGoogleDriveClientFactory` do Sync: entra pelo `when@test` do services.yaml.
 */
final class ProvedorFalso implements ProvedorDeLinguagem, ProvedorDeLinguagemEmFluxo
{
    public const NOME = 'falso';
    public const MODELO = 'falso-1';

    /** @var list<PedidoDeLinguagem> */
    public array $pedidos = [];

    /** @var list<RespostaDeLinguagem|\Throwable> */
    private array $fila = [];

    /** @var list<array{trechos: list<string>, uso: UsoDaTransmissao, falha: ?\Throwable}> */
    private array $filaDeFluxo = [];

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

    public function falharTransitoriamente(string $motivo = 'timeout do provedor', ?int $esperarSegundos = null): void
    {
        $this->fila[] = new FalhaDoProvedorException($motivo, transitoria: true, esperarSegundos: $esperarSegundos);
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

    /**
     * Programa a próxima resposta em fluxo: os trechos, na ordem, e o consumo que o gerador devolve ao terminar.
     *
     * @param list<string> $trechos
     */
    public function transmitirTrechos(array $trechos, ?int $tokensEntrada = 120, ?int $tokensSaida = 40, bool $cortadaNoLimite = false, string $modelo = self::MODELO): void
    {
        $this->filaDeFluxo[] = ['trechos' => $trechos, 'uso' => new UsoDaTransmissao($modelo, $tokensEntrada, $tokensSaida, 15, $cortadaNoLimite), 'falha' => null];
    }

    /**
     * Programa uma resposta em fluxo que, além dos trechos de texto, pede chamadas de ferramenta (tool use). O fluxo
     * termina na chamada: não há `tool_result` nem segunda chamada.
     *
     * @param list<string>                $trechos
     * @param list<ChamadaDeFerramenta>   $chamadas
     */
    public function transmitirComFerramenta(array $trechos, array $chamadas, ?int $tokensEntrada = 120, ?int $tokensSaida = 40, string $modelo = self::MODELO): void
    {
        $this->filaDeFluxo[] = [
            'trechos' => $trechos,
            'uso' => new UsoDaTransmissao($modelo, $tokensEntrada, $tokensSaida, 15, false, $chamadas),
            'falha' => null,
        ];
    }

    /**
     * Programa um fluxo que rende os trechos e então lança a falha (o provedor caiu no meio da resposta).
     *
     * @param list<string> $trechos
     */
    public function transmitirTrechosEFalhar(array $trechos, ?\Throwable $falha = null): void
    {
        $this->filaDeFluxo[] = [
            'trechos' => $trechos,
            'uso' => new UsoDaTransmissao(self::MODELO),
            'falha' => $falha ?? new FalhaDoProvedorException('Anthropic: erro no fluxo (overloaded_error).', transitoria: true),
        ];
    }

    public function transmitir(PedidoDeLinguagem $pedido): \Generator
    {
        $this->pedidos[] = $pedido;

        if (!$this->configurado) {
            throw new ProvedorIndisponivelException('Provedor de IA não configurado nesta instalação.');
        }
        if ($this->filaDeFluxo === []) {
            throw new \LogicException('ProvedorFalso sem fluxo programado: o teste precisa chamar transmitirTrechos() antes.');
        }

        $proximo = array_shift($this->filaDeFluxo);
        foreach ($proximo['trechos'] as $trecho) {
            yield $trecho;
        }
        if ($proximo['falha'] !== null) {
            throw $proximo['falha'];
        }

        return $proximo['uso'];
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
        $this->filaDeFluxo = [];
        $this->configurado = true;
    }
}
