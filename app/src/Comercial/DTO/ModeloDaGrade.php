<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Entity\ModeloDeCampanha;

/**
 * Um botão da grade "1 · MODELO" (dc L.542) com o que a tela edita: o formato do `MODELOS` do dc com os nomes desta
 * frente (`nome` = `r`, `icone` = `ic`). Os próprios têm `chave` = `cu<id>` (como o `'cu' + Date.now()` do dc) e o
 * `id` do banco.
 *
 * @phpstan-import-type Modelo from \App\Comercial\Campanha\ModelosDeCampanha
 *
 * @phpstan-type ModeloEmJson array<string, mixed>
 */
final class ModeloDaGrade
{
    /**
     * @param Modelo $modelo
     *
     * @return ModeloEmJson
     */
    public static function deFixo(array $modelo): array
    {
        return self::completo($modelo + ['id' => null, 'proprio' => false]);
    }

    /** @return ModeloEmJson */
    public static function deEntidade(ModeloDeCampanha $m): array
    {
        $base = [
            'chave' => 'cu' . $m->getId(), 'id' => $m->getId(), 'proprio' => true, 'nome' => $m->getNome(),
            'icone' => $m->getIcone(), 'modo' => $m->getModo(), 'assunto' => $m->getAssunto(), 'selo' => $m->getSelo(),
            'titulo' => $m->getTitulo(),
        ];
        if ($m->getModo() === 'html') {
            return self::completo($base + ['html' => $m->getConteudo()]);
        }
        $conteudo = json_decode($m->getConteudo(), true);
        $conteudo = \is_array($conteudo) ? $conteudo : [];

        return self::completo($base + ($m->getModo() === 'blocos' ? ['blocos' => array_values($conteudo)] : $conteudo));
    }

    /** O conteúdo como `comercial_modelo.conteudo` guarda: o HTML puro, ou o JSON do simples e dos blocos. */
    public static function conteudoParaGravar(MensagemDaCampanha $m, string $htmlSanitizado = ''): string
    {
        return $m->modo === 'html' ? $htmlSanitizado : json_encode($m->conteudo(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $modelo
     *
     * @return ModeloEmJson
     */
    private static function completo(array $modelo): array
    {
        return $modelo + ['corpo' => '', 'itens' => [], 'cta' => '', 'link' => '', 'blocos' => [], 'html' => ''];
    }
}
