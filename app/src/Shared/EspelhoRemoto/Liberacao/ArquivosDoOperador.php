<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\Armazenamento\Sha256DeArquivo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O que a liberação grava para o operador, FORA do acervo (spec `leitura-pelo-r2-e-liberacao-de-disco.md`
 * §5.3 e §5.4): o manifesto do lote (`var/liberacao/<lote>.tsv`: chave remota, SHA-256, tamanho) e a
 * cópia de conferência que o operador pede com `--baixar-para`. Nenhum dos dois é arquivo do sistema:
 * não passam pelo armazenamento nem pelo espelho, e nunca sobrescrevem um arquivo que já existe.
 */
final class ArquivosDoOperador
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/liberacao')]
        private readonly string $diretorioDosManifestos,
    ) {
    }

    /**
     * @param list<CandidatoALiberar> $liberados
     *
     * @return string o caminho do manifesto
     */
    public function gravarManifesto(string $lote, array $liberados): string
    {
        if (!is_dir($this->diretorioDosManifestos) && !@mkdir($this->diretorioDosManifestos, 0o750, true) && !is_dir($this->diretorioDosManifestos)) {
            throw new \RuntimeException('Não foi possível criar o diretório dos manifestos.');
        }

        $caminho = $this->diretorioDosManifestos . '/' . $lote . '.tsv';
        $saida   = @fopen($caminho, 'xb');
        if ($saida === false) {
            throw new \RuntimeException(sprintf('O manifesto %s já existe ou não pôde ser criado.', $caminho));
        }
        try {
            fwrite($saida, "chave_remota\tsha256\ttamanho_bytes\n");
            foreach ($liberados as $c) {
                fwrite($saida, sprintf("%s\t%s\t%d\n", $c->chaveRemota, $c->sha256, $c->tamanhoBytes));
            }
        } finally {
            fclose($saida);
        }

        return $caminho;
    }

    /**
     * Grava em `<pasta>/<nome>` o que `$escrever` produz e mede o que ficou. Arquivo já existente: recusa.
     *
     * @param \Closure(resource): void $escrever
     *
     * @return array{caminho: string, sha256: string, tamanhoBytes: int}
     */
    public function baixarPara(string $pasta, string $nome, \Closure $escrever): array
    {
        if (!is_dir($pasta)) {
            throw new \RuntimeException('A pasta de --baixar-para não existe.');
        }

        $caminho = rtrim($pasta, '/') . '/' . basename($nome);
        $saida   = @fopen($caminho, 'xb');
        if ($saida === false) {
            throw new \RuntimeException('Já existe um arquivo com esse nome na pasta de --baixar-para, ou ela não aceita gravação.');
        }
        try {
            $escrever($saida);
        } catch (\Throwable $e) {
            fclose($saida);
            @unlink($caminho); // o parcial que esta chamada criou: o operador não fica com meio arquivo

            throw $e;
        }
        fclose($saida);
        clearstatcache(true, $caminho);

        return ['caminho' => $caminho, 'sha256' => Sha256DeArquivo::deArquivoLocal($caminho), 'tamanhoBytes' => (int) filesize($caminho)];
    }
}
