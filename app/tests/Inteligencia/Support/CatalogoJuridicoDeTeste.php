<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Inteligencia\Juridico\CatalogoDeQuestoesJuridicas;

/**
 * Catálogo de questões jurídicas montado num JSON temporário, com conteúdo fixo: os testes não dependem do texto real
 * de `config/inteligencia/questoes_juridicas.json`, que o jurídico troca por PR.
 */
final class CatalogoJuridicoDeTeste
{
    /** @return list<array<string, mixed>> */
    public static function questoes(): array
    {
        return [
            self::questao('primeira', 'alto', ['politica_privacidade', 'aceite_ia']),
            self::questao('segunda', 'baixo', ['termos_uso']),
        ];
    }

    /**
     * @param list<string> $documentos
     *
     * @return array<string, mixed>
     */
    public static function questao(string $chave, string $grau = 'medio', array $documentos = ['termos_uso'], bool $oculta = false): array
    {
        return ($oculta ? ['oculta' => true] : []) + [
            'chave' => $chave,
            'titulo' => 'Questão ' . $chave,
            'problema' => "Primeiro parágrafo de {$chave}.\n\nSegundo parágrafo.",
            'risco_grau' => $grau,
            'risco' => 'Risco de ' . $chave,
            'opcoes' => ['Opção A', 'Opção B'],
            'sugestao' => 'Sugestão de ' . $chave,
            'fontes' => [['rotulo' => 'Fonte de ' . $chave, 'url' => 'https://exemplo.gov.br/' . $chave]],
            'documentos' => $documentos,
        ];
    }

    /** @param list<array<string, mixed>>|null $questoes */
    public static function criar(?array $questoes = null): CatalogoDeQuestoesJuridicas
    {
        return new CatalogoDeQuestoesJuridicas(self::gravar(json_encode($questoes ?? self::questoes(), \JSON_THROW_ON_ERROR)));
    }

    /** Grava o conteúdo num arquivo temporário (apagado no fim do processo) e devolve o caminho. */
    public static function gravar(string $conteudo): string
    {
        $caminho = sys_get_temp_dir() . '/questoes-juridicas-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($caminho, $conteudo);
        register_shutdown_function(static fn () => @unlink($caminho));

        return $caminho;
    }
}
