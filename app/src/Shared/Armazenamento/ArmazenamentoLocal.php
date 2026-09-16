<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * O backend de disco — o único que existe na E2, e o que continua servindo os mesmos arquivos,
 * nos mesmos caminhos (INV-1).
 *
 * Convive de propósito com `ArquivoStorageService`, que **não foi tocado** e continua atendendo
 * `ArquivoStorageInterface` para os consumidores que ainda não migraram — 33 no início da E2; depois
 * da E2.3 sobram os que gravam e excluem por caminho, que migram na E2.4 e na E2.5. É o shim de D2:
 * os dois escrevem no mesmo disco, pelo mesmo layout, enquanto os consumidores migram fatia a
 * fatia. Esta classe
 * **não** implementa a interface antiga — duas implementações da mesma interface quebrariam o
 * autowiring por interface e derrubariam todos de uma vez.
 *
 * ## O que ele faz melhor que o antigo, sem mudar nenhum caminho
 *
 *  - **escrita atômica**: grava num temporário no MESMO diretório e faz `rename()` para o lugar.
 *    O antigo usa `file_put_contents` direto e sem checar retorno (`ArquivoStorageService.php:28`) —
 *    disco cheio no meio deixava arquivo parcial, e em silêncio;
 *  - **erro não some**: toda falha de I/O vira `FalhaDeArmazenamento`;
 *  - **metadados no retorno**: tamanho e MIME medidos DEPOIS da escrita, o que remove o motivo de
 *    alguém tocar na origem depois de gravar (a causa do 500 do Kanban).
 *
 * ## Também é o materializador do disco (E2.3)
 *
 * Aqui `paraLeitura()` é cópia zero: o arquivo já está em disco, então o caminho emprestado é o
 * dele. Mora nesta classe, e não numa vizinha, para que o `ResolvedorDeCaminhoLocal` continue
 * sendo detalhe privado do backend (D11) — ninguém fora daqui converte chave em caminho.
 */
final readonly class ArmazenamentoLocal implements ArmazenamentoDeArquivos, MaterializadorDeArquivo
{
    /** Tentativas de cunhagem antes de desistir. Com 128 bits, colidir uma vez já é anedota. */
    private const TENTATIVAS_DE_CUNHAGEM = 5;

    public function __construct(
        private ResolvedorDeCaminhoLocal $resolvedor,
    ) {
    }

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        $chave = $destino instanceof NovoArquivo
            ? $this->cunharChaveLivre($destino)
            : $destino;

        $caminho = $this->resolvedor->caminhoDe($chave);

        $this->escreverAtomicamente($caminho, $fonte);

        clearstatcache(true, $caminho);
        $tamanho = @filesize($caminho);
        if ($tamanho === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Gravou mas não conseguiu medir o arquivo %s.', $chave->comoTexto()),
            );
        }

        return new ArquivoArmazenado($chave, $tamanho, $this->mimeDe($caminho));
    }

    public function abrir(ChaveDeArquivo $chave): mixed
    {
        $caminho = $this->resolvedor->caminhoDe($chave);

        if (!is_file($caminho)) {
            throw ArquivoNaoEncontrado::para($chave);
        }

        $recurso = @fopen($caminho, 'rb');
        if ($recurso === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Arquivo existe mas não pôde ser aberto: %s', $chave->comoTexto()),
            );
        }

        return $recurso;
    }

    public function ler(ChaveDeArquivo $chave): string
    {
        $caminho = $this->resolvedor->caminhoDe($chave);

        if (!is_file($caminho)) {
            throw ArquivoNaoEncontrado::para($chave);
        }

        $conteudo = @file_get_contents($caminho);
        if ($conteudo === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Arquivo existe mas não pôde ser lido: %s', $chave->comoTexto()),
            );
        }

        return $conteudo;
    }

    /**
     * Ausência devolve false; **impossibilidade de saber lança**.
     *
     * A diferença importa: um diretório sem permissão faria `file_exists()` devolver false, e uma
     * rotina de limpeza leria isso como "o arquivo sumiu". Diretório que simplesmente não existe
     * ainda é ausência normal — nada foi gravado ali.
     *
     * Duas sutilezas que custaram defeito reproduzido em revisão:
     *
     *  - usa `is_file()`, não `file_exists()`. Uma chave endereça ARQUIVO; um diretório naquele
     *    caminho é ausência de arquivo. Com `file_exists()`, `existe()` dizia true enquanto
     *    `metadados()` dizia null e `excluir()` não removia nada — três métodos discordando, e o
     *    padrão `if (existe()) { metadados()->tamanhoBytes; }` virava TypeError fatal;
     *  - a checagem de legibilidade percorre **toda a cadeia** de ancestrais, não só o pai. Se o
     *    avô perde `+x`, o próprio `is_dir()` do pai já falha, a guarda do pai nunca dispara e o
     *    método voltava false em silêncio. Não é hipótese: `public/uploads/*` nascendo com o uid
     *    errado é incidente recorrente registrado no CLAUDE.md, e `public/uploads/cobrancas` é o
     *    avô das duas categorias com isolamento físico.
     */
    public function existe(ChaveDeArquivo $chave): bool
    {
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache(true, $caminho);

        if (is_file($caminho)) {
            return true;
        }

        $this->exigirCadeiaLegivel($caminho, $chave);

        return false;
    }

    public function excluir(ChaveDeArquivo $chave): void
    {
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            return; // idempotente: apagar o que não existe não é erro
        }

        if (@unlink($caminho)) {
            return;
        }

        clearstatcache(true, $caminho);
        if (is_file($caminho)) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível remover %s.', $chave->comoTexto()),
            );
        }
    }

    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            return null;
        }

        $tamanho = @filesize($caminho);
        $mtime   = @filemtime($caminho);

        if ($tamanho === false || $mtime === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível ler os metadados de %s.', $chave->comoTexto()),
            );
        }

        return new MetadadosDeArquivo(
            tamanhoBytes: $tamanho,
            mimeType: $this->mimeDe($caminho),
            atualizadoEm: (new \DateTimeImmutable())->setTimestamp($mtime),
            checksum: null, // D6: cálculo fica para a E3
        );
    }

    /**
     * Cópia zero: devolve, emprestado, o caminho do próprio arquivo persistido.
     *
     * A ordem das checagens é o D10. Antes de afirmar "não existe", prova que dava para olhar —
     * com o diretório ilegível o `is_file()` também devolve false, e sem a prova isso viraria 404
     * na rota. E um arquivo presente mas ilegível é pane, não ausência.
     */
    public function paraLeitura(ChaveDeArquivo $chave): ArquivoEmprestado
    {
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            $this->exigirCadeiaLegivel($caminho, $chave);

            throw ArquivoNaoEncontrado::para($chave);
        }

        if (!is_readable($caminho)) {
            throw new FalhaDeArmazenamento(
                sprintf('Arquivo existe mas não é legível: %s', $chave->comoTexto()),
            );
        }

        return new ArquivoEmprestado($caminho);
    }

    private function cunharChaveLivre(NovoArquivo $novo): ChaveDeArquivo
    {
        for ($tentativa = 0; $tentativa < self::TENTATIVAS_DE_CUNHAGEM; $tentativa++) {
            $chave = $novo->cunharChave();

            if (!file_exists($this->resolvedor->caminhoDe($chave))) {
                return $chave;
            }
        }

        throw new FalhaDeArmazenamento(
            'Não foi possível cunhar um nome livre para o arquivo novo após '
            . self::TENTATIVAS_DE_CUNHAGEM . ' tentativas.',
        );
    }

    /**
     * Grava num temporário do MESMO diretório e move para o lugar.
     *
     * O temporário precisa ser vizinho do destino: `rename()` só é atômico dentro do mesmo
     * sistema de arquivos, e é justamente por cruzar `/tmp` e o volume de uploads que o
     * `moverParaArmazenamento()` de hoje precisa do fallback de EXDEV
     * (`ArquivoStorageService.php:40-50`).
     *
     * Quando a fonte é um arquivo local que pode ser consumido, o caminho rápido é um `rename()`
     * direto da origem: atômico e sem reler o conteúdo, que é o que permite mover arquivo grande
     * (o download do Drive) sem carregar nada em memória.
     */
    private function escreverAtomicamente(string $destino, FonteDeConteudo $fonte): void
    {
        $this->garantirDiretorio(\dirname($destino));

        $origem = $fonte->caminhoLocalOuNull();

        if ($origem !== null && $fonte->consomeOrigem() && @rename($origem, $destino)) {
            $this->normalizarModo($destino);

            return;
        }

        $temporario = $destino . '.parcial-' . bin2hex(random_bytes(8));

        $saida = @fopen($temporario, 'wb');
        if ($saida === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível abrir o destino temporário %s.', $temporario),
            );
        }

        try {
            $fonte->escreverEm($saida);

            if (!fflush($saida)) {
                throw new FalhaDeArmazenamento('Não foi possível esvaziar o buffer de escrita.');
            }
        } catch (\Throwable $e) {
            fclose($saida);
            @unlink($temporario);

            throw $e;
        }

        fclose($saida);

        if (!@rename($temporario, $destino)) {
            @unlink($temporario);

            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível publicar o arquivo em %s.', $destino),
            );
        }

        $this->normalizarModo($destino);

        // Só agora: a origem só some depois de o destino estar publicado. É a mesma prioridade da
        // E1 — órfão recuperável é aceitável, perda de conteúdo não.
        if ($origem !== null && $fonte->consomeOrigem()) {
            @unlink($origem);
        }
    }

    /**
     * Mesmo modo nos dois ramos de publicação.
     *
     * Sem isto o resultado depende de qual ramo rodou: `rename()` preserva o modo da origem (um
     * `tempnam()` nasce `0600`), enquanto `fopen('wb')` cria com `0666 & ~umask` (tipicamente
     * `0644`). E qual ramo roda depende do sistema de arquivos — origem e destino no mesmo mount
     * usam o caminho rápido, em mounts diferentes cai no lento por EXDEV. Arquivo de upload que
     * nasce `0600` é invisível para o nginx e para o worker.
     *
     * `0666 & ~umask` é exatamente o que `UploadedFile::move()` aplica hoje
     * (`vendor/symfony/http-foundation/File/UploadedFile.php`), então isto preserva o
     * comportamento atual em vez de inventar outro.
     */
    private function normalizarModo(string $caminho): void
    {
        @chmod($caminho, 0o666 & ~umask());
    }

    /**
     * Percorre os ancestrais de cima para baixo e lança se algum existir sem ser atravessável.
     *
     * De cima para baixo porque é a única ordem em que a resposta é confiável: só dá para
     * afirmar que um diretório "não existe" se o pai dele puder ser lido. Verificar apenas o pai
     * imediato deixa passar exatamente o caso em que a permissão quebrou mais acima.
     */
    private function exigirCadeiaLegivel(string $caminho, ChaveDeArquivo $chave): void
    {
        $partes  = explode('/', ltrim(\dirname($caminho), '/'));
        $prefixo = '';

        foreach ($partes as $parte) {
            $prefixo .= '/' . $parte;
            clearstatcache(true, $prefixo);

            if (!is_dir($prefixo)) {
                return; // a partir daqui nada existe, e o pai já se provou atravessável
            }

            if (!is_readable($prefixo) || !is_executable($prefixo)) {
                throw new FalhaDeArmazenamento(sprintf(
                    'Não foi possível determinar a existência de %s: o diretório %s não é legível. '
                    . 'Responder "não existe" aqui faria uma rotina de limpeza apagar registro válido.',
                    $chave->comoTexto(),
                    $prefixo,
                ));
            }
        }
    }

    private function garantirDiretorio(string $diretorio): void
    {
        if (is_dir($diretorio)) {
            return;
        }

        // A corrida importa: dois processos podem criar o mesmo diretório. O segundo `is_dir`
        // distingue "perdi a corrida" (tudo bem) de "não consegui criar" (erro de verdade).
        if (!@mkdir($diretorio, 0o755, true) && !is_dir($diretorio)) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível criar o diretório %s.', $diretorio),
            );
        }
    }

    private function mimeDe(string $caminho): string
    {
        $mime = @mime_content_type($caminho);

        return $mime !== false && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
