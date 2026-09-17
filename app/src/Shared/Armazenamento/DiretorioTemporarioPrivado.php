<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\FalhaNoTemporario;

/**
 * O diretório onde um temporário com conteúdo de cliente espera: privado deste processo e fora do
 * volume persistente (D29, DT-7).
 *
 * A política nasceu na ponte de upload (E2.4A) e mora aqui desde a E2.6A, para valer igual para todo
 * temporário que carrega documento de cliente — o upload à espera da gravação e a cópia gravável do
 * compressor. Se o processo morrer no meio, o que sobrar não pode ficar legível por outros usuários
 * da máquina, e não fica no backup nem no alcance da purga.
 *
 * "Fora do volume persistente" é **premissa de implantação**, não checagem: vale porque o
 * `sys_get_temp_dir()` é o `/tmp` da camada gravável do container (não há `sys_temp_dir` no php.ini
 * nem `TMPDIR` no ambiente, e o compose monta só o volume de uploads). Apontar o temporário do PHP
 * para dentro de `public/uploads` quebraria a premissa sem que nada aqui reclamasse.
 *
 * ## "Privado" é conferido, não suposto
 *
 *  - o nome leva o uid efetivo: um `docker exec -u 0` cria o do root, não toma o do FPM;
 *  - o caminho é um diretório de verdade — nem link, nem arquivo —, do uid efetivo, atravessável
 *    por nós e sem nada para grupo e outros. Um diretório exposto é recusado, **não** corrigido em
 *    silêncio: alguém o abriu, e o erro tem de aparecer. A única exceção é o diretório que ESTE
 *    processo acabou de criar, onde o `chmod` neutraliza o umask;
 *  - o temporário tem de nascer dentro dele: o `tempnam()` cai em silêncio no temporário do sistema
 *    quando não consegue escrever onde pediram. O que caiu fora é apagado antes de lançar.
 *
 * Não é serviço do container (excluído em `services.yaml`): é um valor, criado por
 * {@see doProcesso()} ou, em teste, por {@see existente()}.
 */
final class DiretorioTemporarioPrivado
{
    private const S_IFMT  = 0o170000;
    private const S_IFDIR = 0o040000;

    private function __construct(
        private readonly string $caminho,
        private readonly bool $criarSeAusente,
    ) {
    }

    /**
     * `<temporário do sistema>/jusprime-<finalidade>-<uid efetivo>`, criado com `0700` se ainda não
     * existir.
     *
     * @throws \InvalidArgumentException quando a finalidade não é um nome simples
     */
    public static function doProcesso(string $finalidade): self
    {
        if (preg_match('/^[a-z][a-z0-9]*$/', $finalidade) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Finalidade de diretório temporário inválida: "%s" (use letras minúsculas e dígitos).',
                $finalidade,
            ));
        }

        return new self(sys_get_temp_dir() . '/jusprime-' . $finalidade . '-' . self::uidEfetivo(), true);
    }

    /** Um diretório que já existe. A mesma prova de privacidade vale para ele — é assim que a recusa é testada. */
    public static function existente(string $caminho): self
    {
        return new self(rtrim($caminho, '/'), false);
    }

    /**
     * O caminho, depois de criado (se for o do processo) e provado privado.
     *
     * @throws FalhaNoTemporario se não puder ser criado ou não for privado deste processo
     */
    public function caminho(): string
    {
        // A corrida entre workers é normal: o segundo `is_dir` distingue "outro criou" de erro.
        if ($this->criarSeAusente && !is_dir($this->caminho)) {
            if (@mkdir($this->caminho, 0o700)) {
                // O `mkdir` aplica o umask: com um umask que tira bits do DONO, o diretório nasce
                // sem `rwx` para nós mesmos, passa na prova de privacidade e envenena o processo
                // (todo `tempnam` cairia fora). O `chmod` corrige só o que acabamos de criar —
                // diretório PREEXISTENTE exposto continua sendo recusado, nunca corrigido.
                @chmod($this->caminho, 0o700);
            } elseif (!is_dir($this->caminho)) {
                throw new FalhaNoTemporario(
                    sprintf('Não foi possível preparar o diretório temporário %s.', $this->caminho),
                );
            }
        }

        clearstatcache(true, $this->caminho);
        $info = is_link($this->caminho) ? false : @stat($this->caminho);

        if ($info === false
            || ($info['mode'] & self::S_IFMT) !== self::S_IFDIR
            || $info['uid'] !== self::uidEfetivo()
            || ($info['mode'] & 0o077) !== 0
            // Sem `r-x` para o dono nem dá para olhar dentro: diretório com umask estragado (`0300`,
            // `0000`) passava na prova de "ninguém mais vê" e envenenava o processo em silêncio.
            // A escrita não entra aqui de propósito: um `0500` é recusado adiante, quando o
            // `tempnam` cai fora — e é esse guarda que prova que o temporário nasceu no lugar.
            || ($info['mode'] & 0o500) !== 0o500
        ) {
            throw new FalhaNoTemporario(sprintf(
                'O diretório temporário %s não é privado deste processo (tipo, dono ou permissão errados).',
                $this->caminho,
            ));
        }

        return $this->caminho;
    }

    /**
     * Um arquivo vazio, possuído, que nasceu comprovadamente aqui dentro (modo `0600`, do `tempnam`).
     *
     * @throws FalhaNoTemporario se o diretório não for privado ou o arquivo nascer fora dele
     */
    public function novoArquivo(string $prefixo): ArquivoTemporarioPossuido
    {
        $diretorio  = $this->caminho();
        $temporario = ArquivoTemporarioPossuido::criarEm($diretorio, $prefixo);

        try {
            if (\dirname($temporario->caminho()) !== $diretorio) {
                throw new FalhaNoTemporario(sprintf(
                    'O temporário não nasceu em %s (o tempnam caiu em outro diretório).',
                    $diretorio,
                ));
            }

            // Reconfere: entre a prova e o `tempnam` o diretório pode ter sumido (um limpador de
            // /tmp) e renascido EXPOSTO — o `criarEm()` o recria com 0755. O nome bateria, e o
            // arquivo do cliente ficaria num diretório legível por qualquer um.
            $this->caminho();
        } catch (\Throwable $e) {
            $temporario->liberar();

            throw $e;
        }

        return $temporario;
    }

    private static function uidEfetivo(): int
    {
        return \function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    }
}
