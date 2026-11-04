<?php

declare(strict_types=1);

namespace App\Blog\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A pasta dos pedidos de geração dos blogs (spec painel-artigos-blogs, seção 6.1): o único ponto do domínio Blog que
 * toca o disco direto. Não é arquivo do usuário nem passa pelo armazenamento: é o volume `publicacao_blogs`, que o
 * worker e o scheduler dividem com o contêiner `blog_builder` (em dev e teste, `var/publicacao-blogs`).
 *
 * O BlueJus escreve o pedido (montado em `.{id}.montando/` e renomeado para `{id}/` no fim, para o builder nunca ver um
 * pacote pela metade) e lê o que o builder escreve na pasta do pedido: `iniciado` e `resultado.json`. Depois do
 * resultado, a pasta do pedido é apagada.
 *
 * Os caminhos relativos vêm do montador (pasta do blog, slug validado pela regex do artigo, id e extensão da imagem):
 * mesmo assim, `..`, caminho absoluto e barra invertida são recusados.
 */
final class PastaDosPedidos
{
    public function __construct(
        #[Autowire('%publicacao_blogs_dir%')]
        private readonly string $raiz,
    ) {
    }

    /** Começa a montagem do pedido do zero (sobra de uma montagem que caiu no meio é apagada). */
    public function comecarMontagem(int $id): void
    {
        $this->apagarArvore($this->montando($id));
        $this->criarPasta($this->montando($id));
    }

    /** Pasta vazia no pacote (as `artigos/{blog}/imagens` existem sempre, mesmo sem artigo ou imagem). */
    public function criarPastaNaMontagem(int $id, string $relativo): void
    {
        $this->criarPasta(\dirname($this->destinoNaMontagem($id, $relativo . '/.')));
    }

    public function gravarNaMontagem(int $id, string $relativo, string $conteudo): void
    {
        $destino = $this->destinoNaMontagem($id, $relativo);
        if (file_put_contents($destino, $conteudo) !== \strlen($conteudo)) {
            throw new \RuntimeException(sprintf('Não foi possível gravar "%s" no pedido %d.', $relativo, $id));
        }
    }

    /** @param resource $origem o stream que o armazenamento abriu; quem abriu fecha */
    public function copiarParaMontagem(int $id, string $relativo, mixed $origem): void
    {
        $destino = fopen($this->destinoNaMontagem($id, $relativo), 'wb');
        if ($destino === false) {
            throw new \RuntimeException(sprintf('Não foi possível criar "%s" no pedido %d.', $relativo, $id));
        }
        try {
            if (stream_copy_to_stream($origem, $destino) === false) {
                throw new \RuntimeException(sprintf('Não foi possível copiar "%s" no pedido %d.', $relativo, $id));
            }
        } finally {
            fclose($destino);
        }
    }

    /** Fim da montagem: `.{id}.montando/` vira `{id}/`, de uma vez, e o builder passa a enxergar o pedido. */
    public function concluirMontagem(int $id): void
    {
        if (file_exists($this->doPedido($id))) {
            throw new \RuntimeException(sprintf('A pasta do pedido %d já existe.', $id));
        }
        if (!rename($this->montando($id), $this->doPedido($id))) {
            throw new \RuntimeException(sprintf('Não foi possível concluir a montagem do pedido %d.', $id));
        }
    }

    public function descartarMontagem(int $id): void
    {
        $this->apagarArvore($this->montando($id));
    }

    public function existe(int $id): bool
    {
        return is_dir($this->doPedido($id));
    }

    /** O conteúdo de `iniciado` (a data ISO que o builder escreveu), ou nulo se o builder ainda não começou. */
    public function iniciado(int $id): ?string
    {
        $arquivo = $this->doPedido($id) . '/iniciado';
        if (!is_file($arquivo)) {
            return null;
        }

        return trim((string) file_get_contents($arquivo));
    }

    /**
     * O `resultado.json` do builder, decodificado; nulo se ainda não existe. JSON que não é objeto vira lista vazia
     * (o chamador trata como resultado que não entende).
     *
     * @return array<string, mixed>|null
     */
    public function resultado(int $id): ?array
    {
        $arquivo = $this->doPedido($id) . '/resultado.json';
        if (!is_file($arquivo)) {
            return null;
        }
        // O log do builder pode trazer byte que não é UTF-8 (saída do npm): sem o scrub, o json_decode falharia.
        $dados = json_decode(mb_scrub((string) file_get_contents($arquivo), 'UTF-8'), true);

        return \is_array($dados) ? $dados : [];
    }

    /**
     * Os slugs do `pedido.json` de um pedido já montado (o handler que caiu depois do rename os grava); nulo se a pasta
     * ou o arquivo não existem ou não se entendem.
     *
     * @return array<string, list<string>>|null
     */
    public function slugsDoPedido(int $id): ?array
    {
        $arquivo = $this->doPedido($id) . '/pedido.json';
        if (!is_file($arquivo)) {
            return null;
        }
        $dados = json_decode((string) file_get_contents($arquivo), true);
        if (!\is_array($dados) || !\is_array($dados['artigos'] ?? null)) {
            return null;
        }
        $slugs = [];
        foreach ($dados['artigos'] as $pasta => $lista) {
            $slugs[(string) $pasta] = \is_array($lista) ? array_values(array_map('strval', $lista)) : [];
        }

        return $slugs;
    }

    /**
     * Apaga as montagens `.{id}.montando` paradas desde antes de `$antesDe` (o worker caiu no meio). Devolve quantas.
     */
    public function apagarMontagensAbandonadas(\DateTimeImmutable $antesDe): int
    {
        $apagadas = 0;
        foreach (is_dir($this->raiz) ? (scandir($this->raiz) ?: []) : [] as $nome) {
            $caminho = rtrim($this->raiz, '/') . '/' . $nome;
            if (preg_match('/^\.\d+\.montando$/', $nome) === 1 && !is_link($caminho) && (int) filemtime($caminho) < $antesDe->getTimestamp()) {
                $this->apagarArvore($caminho);
                ++$apagadas;
            }
        }

        return $apagadas;
    }

    /** Apaga a pasta do pedido (depois de ler o resultado, ou quando o builder não respondeu). */
    public function remover(int $id): void
    {
        $this->apagarArvore($this->doPedido($id));
    }

    private function doPedido(int $id): string
    {
        return rtrim($this->raiz, '/') . '/' . $id;
    }

    private function montando(int $id): string
    {
        return rtrim($this->raiz, '/') . '/.' . $id . '.montando';
    }

    private function destinoNaMontagem(int $id, string $relativo): string
    {
        if ($relativo === '' || str_starts_with($relativo, '/') || str_contains($relativo, '\\') || \in_array('..', explode('/', $relativo), true)) {
            throw new \InvalidArgumentException(sprintf('Caminho inválido no pacote: "%s".', $relativo));
        }
        $destino = $this->montando($id) . '/' . $relativo;
        $this->criarPasta(\dirname($destino));

        return $destino;
    }

    private function criarPasta(string $pasta): void
    {
        if (!is_dir($pasta) && !mkdir($pasta, 0o775, true) && !is_dir($pasta)) {
            throw new \RuntimeException(sprintf('Não foi possível criar a pasta "%s".', $pasta));
        }
    }

    /** Apaga a pasta e o que tem dentro; link simbólico é apagado, nunca seguido. */
    private function apagarArvore(string $caminho): void
    {
        if (is_link($caminho) || is_file($caminho)) {
            unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }
        foreach (scandir($caminho) ?: [] as $nome) {
            if ($nome !== '.' && $nome !== '..') {
                $this->apagarArvore($caminho . '/' . $nome);
            }
        }
        rmdir($caminho);
    }
}
