<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Inteligencia\DTO\AnaliseOutput;
use App\Pasta\Service\TextoDoEnvioAoCliente;

/**
 * O cartão "Avisar cliente" e o modal "Enviar ao cliente" de UMA pasta (spec push-avisar-cliente §2 e
 * §3; desenho `mailVals` e `mailIAVals`, dc L.5241-5293), com os textos já resolvidos. `automatico`
 * são as chaves do "Envio automático" do cliente (spec push-envio-automatico §2).
 */
final readonly class AvisarClienteOutput
{
    public const HISTORICO_VISIVEL = 6;

    /**
     * @param list<EmailDoClienteOutput>               $emails
     * @param list<array{nome: string, email: string}> $controladoria
     * @param list<EnvioAoClienteOutput>               $historico     os 6 mais recentes
     * @param array<int, EnvioDaAnaliseOutput>         $enviosPorAnalise
     */
    public function __construct(
        public bool $temCliente,
        public ?string $nomeDoCliente,
        public string $numeroDoProcesso,
        public array $emails,
        public array $controladoria,
        public array $historico,
        public int $totalHistorico,
        public array $enviosPorAnalise,
        public ChavesDoEnvioAutomatico $automatico = new ChavesDoEnvioAutomatico(),
    ) {
    }

    /**
     * As três linhas do bloco "Envio automático" (spec push-envio-automatico §2; dc N:5270 `autos`).
     * Sem e-mail nenhum cadastrado, esmaecidas e com o prefixo da dica (como o `n` do desenho).
     *
     * @return list<ItemDoEnvioAutomaticoOutput>
     */
    public function envioAutomatico(): array
    {
        return ItemDoEnvioAutomaticoOutput::lista($this->automatico, $this->total() === 0);
    }

    public function total(): int
    {
        return \count($this->emails);
    }

    public function recebendo(): int
    {
        return \count(array_filter($this->emails, static fn (EmailDoClienteOutput $e): bool => $e->recebendo()));
    }

    /** "Maria da" no vazio (dc L.5260 `cliente`). */
    public function cliente(): string
    {
        $nomes = TextoDoEnvioAoCliente::doisPrimeirosNomes($this->nomeDoCliente);

        return $nomes !== '' ? $nomes : 'o cliente';
    }

    /** Título do chip contador (dc L.5260 `contDica`). */
    public function contadorDica(): string
    {
        $n = $this->total();
        if ($n === 0) {
            return 'Nenhum e-mail cadastrado';
        }

        return $n . ($n === 1 ? ' e-mail cadastrado' : ' e-mails cadastrados') . ', ' . $this->recebendo() . ' recebendo avisos';
    }

    public function temControladoria(): bool
    {
        return $this->controladoria !== [];
    }

    public function nomesDaControladoria(): string
    {
        return implode(', ', array_column($this->controladoria, 'nome'));
    }

    /** "Ver histórico de envios (N)" (dc L.5272 `histTxt`; a tela troca para "Ocultar"). */
    public function historicoTxt(bool $aberto): string
    {
        return ($aberto ? 'Ocultar' : 'Ver') . ' histórico de envios (' . $this->totalHistorico . ')';
    }

    /** Subtítulo do modal (dc L.5272 `envSub`). */
    public function subDoModal(): string
    {
        return 'Análise da BlueJus IA · ' . $this->numeroDoProcesso;
    }

    public function assuntoPadrao(): string
    {
        return TextoDoEnvioAoCliente::assunto($this->numeroDoProcesso);
    }

    public function corpoPadrao(AnaliseOutput $analise): string
    {
        $pontos = array_map(static fn (array $p): array => ['tipo' => $p['tipo'], 'texto' => $p['texto']], $analise->pontos);

        return TextoDoEnvioAoCliente::corpo($this->nomeDoCliente, $this->numeroDoProcesso, (string) $analise->resumo, $pontos);
    }

    public function envioDaAnalise(int $analiseId): ?EnvioDaAnaliseOutput
    {
        return $this->enviosPorAnalise[$analiseId] ?? null;
    }

    /** Dica do botão da análise (dc L.5245 `envDica`). */
    public function dicaDoEnvio(): string
    {
        $n = $this->recebendo();

        return $n > 0
            ? 'Revisar e enviar por e-mail para ' . $n . ($n === 1 ? ' destinatário' : ' destinatários')
            : 'Cadastre o e-mail do cliente para enviar';
    }
}
