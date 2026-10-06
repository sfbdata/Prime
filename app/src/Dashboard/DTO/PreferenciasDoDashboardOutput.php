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
     */
    public function __construct(
        public readonly string $densidade,
        public readonly string $animacoes,
        public readonly string $setas,
        public readonly array $colunasOcultas,
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
        );
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
        ];
    }

    /**
     * Classes do `.db-page`. O padrão não leva classe nenhuma: a tela padrão continua sendo
     * exatamente a de antes do menu. O dashboard-preferencias.js monta as mesmas classes.
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
        foreach ($this->colunasOcultas as $coluna) {
            $classes[] = 'db-oculta--' . $coluna;
        }

        return implode(' ', $classes);
    }
}
