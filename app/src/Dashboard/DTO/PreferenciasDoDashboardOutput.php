<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard as Catalogo;

/**
 * O estilo do Dashboard de UM usuário num escritório, já completo (o que não foi gravado vem do
 * padrão). É o que o template usa para abrir a página já no estilo da pessoa — as classes vão no
 * `.db-page`, sem esperar JavaScript e sem "piscar" — e o que o endpoint devolve depois de gravar.
 *
 * O valor gravado é conferido de novo na leitura: se um dia uma opção sair do catálogo, a linha
 * antiga cai no padrão em vez de quebrar a tela.
 */
final class PreferenciasDoDashboardOutput
{
    /**
     * @param list<string> $colunasOcultas
     * @param list<string> $colunasExtras        métricas do "Adicionar coluna", na ordem da tabela
     * @param bool         $iaAvancadoEscolhido  o usuário já gravou o Modo avançado (ligado ou
     *                                           desligado)? Sem escolha gravada, ligar pede a
     *                                           confirmação "Ativar Modo Avançado?" (README do
     *                                           desenho: "Na primeira vez abre a confirmação")
     */
    public function __construct(
        public readonly string $densidade,
        public readonly string $animacoes,
        public readonly string $setas,
        public readonly array $colunasOcultas,
        public readonly string $sons,
        public readonly array $colunasExtras = [],
        public readonly string $iaAvancado = Catalogo::IA_AVANCADO_DESLIGADO,
        public readonly bool $iaAvancadoEscolhido = false,
    ) {
    }

    public static function padrao(): self
    {
        return self::deValores([]);
    }

    /**
     * @param array<string, mixed> $gravados chave do catálogo => valor do banco
     */
    public static function deValores(array $gravados): self
    {
        $valor = static function (string $chave) use ($gravados): mixed {
            if (!array_key_exists($chave, $gravados)) {
                return Catalogo::padrao($chave);
            }

            try {
                return Catalogo::validar($chave, $gravados[$chave]);
            } catch (PreferenciaInvalidaException) {
                return Catalogo::padrao($chave);
            }
        };

        return new self(
            $valor(Catalogo::DENSIDADE),
            $valor(Catalogo::ANIMACOES),
            $valor(Catalogo::SETAS),
            $valor(Catalogo::COLUNAS_OCULTAS),
            $valor(Catalogo::SONS),
            $valor(Catalogo::COLUNAS_EXTRAS),
            $valor(Catalogo::IA_AVANCADO),
            self::gravadoValido($gravados, Catalogo::IA_AVANCADO),
        );
    }

    /**
     * A chave tem linha no banco com valor que o catálogo ainda aceita? Linha antiga com valor
     * que saiu da lista conta como "sem escolha", igual ao padrão.
     *
     * @param array<string, mixed> $gravados
     */
    private static function gravadoValido(array $gravados, string $chave): bool
    {
        if (!array_key_exists($chave, $gravados)) {
            return false;
        }

        try {
            Catalogo::validar($chave, $gravados[$chave]);
        } catch (PreferenciaInvalidaException) {
            return false;
        }

        return true;
    }

    public function iaAvancadoLigado(): bool
    {
        return $this->iaAvancado === Catalogo::IA_AVANCADO_LIGADO;
    }

    /**
     * Formato do `data-preferencias` e da resposta do endpoint: chave do catálogo => valor.
     *
     * @return array<string, string|list<string>>
     */
    public function paraArray(): array
    {
        return [
            Catalogo::DENSIDADE       => $this->densidade,
            Catalogo::ANIMACOES       => $this->animacoes,
            Catalogo::SETAS           => $this->setas,
            Catalogo::COLUNAS_OCULTAS => $this->colunasOcultas,
            Catalogo::SONS            => $this->sons,
            Catalogo::COLUNAS_EXTRAS  => $this->colunasExtras,
            Catalogo::IA_AVANCADO     => $this->iaAvancado,
        ];
    }

    /**
     * Classes do `.db-page`. O padrão não leva classe nenhuma: a tela padrão continua sendo
     * exatamente a de antes do menu. O dashboard-preferencias.js monta as mesmas classes.
     * As colunas extras não viram classe: elas mudam o HTML da tabela (o servidor calcula a
     * coluna), por isso a tela recarrega o fragmento quando a lista muda.
     * O Modo avançado ligado vira `db-page--ia-avancado`: mostra o bloco avançado do painel
     * BlueJus Intelligence e alarga o painel para 600px (dashboard-inteligencia.css).
     */
    public function classesCss(): string
    {
        $classes = [];

        if ($this->densidade === Catalogo::DENSIDADE_CONFORTAVEL) {
            $classes[] = 'db-page--confortavel';
        }
        if ($this->animacoes === Catalogo::ANIMACOES_REDUZIDAS) {
            $classes[] = 'db-page--sem-anim';
        }
        if ($this->setas === Catalogo::SETAS_DESLIGADAS) {
            $classes[] = 'db-page--sem-setas';
        }
        if ($this->sons === Catalogo::SONS_DESLIGADOS) {
            $classes[] = 'db-page--sem-som';
        }
        if ($this->iaAvancadoLigado()) {
            $classes[] = 'db-page--ia-avancado';
        }
        foreach ($this->colunasOcultas as $coluna) {
            $classes[] = 'db-oculta--' . $coluna;
        }

        return implode(' ', $classes);
    }
}
