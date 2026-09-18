<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\FalhaNoTemporario;

/**
 * Um diretório temporário **por operação**, aleatório, dentro do {@see DiretorioTemporarioPrivado}
 * do processo (D29, D32).
 *
 * Existe porque duas coisas precisam de um lugar que seja **só daquela execução**:
 *
 *  - o export de peça materializa nele as imagens do documento e manda o Dompdf/PhpWord lerem dali —
 *    com o `chroot` apontado para cá, "ler qualquer arquivo sob `public/`" deixa de ser possível;
 *  - o Ghostscript recebe este caminho como `TMPDIR` e os temporários dele somem junto.
 *
 * O nome é aleatório de propósito: dois exports simultâneos não se enxergam, e ninguém adivinha o
 * caminho para plantar arquivo. A limpeza é do dono ({@see liberar()}, idempotente, chamado em
 * `finally`); o destrutor é rede de segurança para o caminho de exceção.
 *
 * Só apaga o que está dentro dela, e nunca segue link para fora.
 */
final class AreaTemporariaPrivada
{
    private bool $liberada = false;

    private function __construct(
        private readonly string $caminho,
    ) {
    }

    /**
     * @param string $finalidade nome simples, que vira o diretório do processo (`jusprime-<finalidade>-<uid>`)
     *
     * @throws FalhaNoTemporario quando o diretório do processo não é privado ou a área não pode ser criada
     */
    public static function criar(string $finalidade): self
    {
        $raiz    = DiretorioTemporarioPrivado::doProcesso($finalidade)->caminho();
        $caminho = $raiz . '/' . bin2hex(random_bytes(8));

        if (!@mkdir($caminho, 0o700)) {
            throw new FalhaNoTemporario(sprintf('Não foi possível criar a área temporária %s.', $caminho));
        }

        @chmod($caminho, 0o700); // o umask não decide a privacidade

        return new self($caminho);
    }

    public function caminho(): string
    {
        if ($this->liberada) {
            throw new FalhaNoTemporario('Esta área temporária já foi liberada.');
        }

        return $this->caminho;
    }

    /**
     * Grava um conteúdo num arquivo novo, de nome aleatório, dentro da área — e devolve o caminho.
     *
     * A extensão é preservada porque quem lê depois (PhpWord, Dompdf) decide o tipo também por ela;
     * só caracteres alfanuméricos entram, então ela não pode virar travessia nem nome de opção.
     *
     * @throws FalhaNoTemporario quando a extensão não serve ou o conteúdo não pode ser gravado
     */
    public function gravar(string $conteudo, string $extensao = ''): string
    {
        // O limite é o mesmo que `NovoArquivo` normaliza: uma extensão que o storage aceitou cunhar
        // não pode ser recusada aqui — seria pane onde devia haver, no máximo, imagem pulada.
        if ($extensao !== '' && preg_match('/^[A-Za-z0-9]{1,16}$/', $extensao) !== 1) {
            throw new FalhaNoTemporario(sprintf('Extensão inválida para arquivo temporário: "%s".', $extensao));
        }

        $caminho = $this->caminho() . '/' . bin2hex(random_bytes(8)) . ($extensao === '' ? '' : '.' . $extensao);

        if (@file_put_contents($caminho, $conteudo) !== \strlen($conteudo)) {
            @unlink($caminho);

            throw new FalhaNoTemporario(sprintf('Não foi possível gravar o temporário %s.', $caminho));
        }

        @chmod($caminho, 0o600);

        return $caminho;
    }

    /** Idempotente. Remove a área inteira — e só ela. */
    public function liberar(): void
    {
        if ($this->liberada) {
            return;
        }

        $this->liberada = true;
        self::apagar($this->caminho);
    }

    public function __destruct()
    {
        $this->liberar();
    }

    private static function apagar(string $diretorio): void
    {
        foreach (scandir($diretorio) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $caminho = $diretorio . '/' . $entrada;

            if (is_dir($caminho) && !is_link($caminho)) {
                self::apagar($caminho);

                continue;
            }

            @unlink($caminho);
        }

        @rmdir($diretorio);
    }
}
