<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\NovoArquivo;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A ponte entre o upload HTTP e o armazenamento por chave (E2.4A, spec §3.5).
 *
 * ```
 * UploadedFile → FonteDeUploadHttp → FonteDeConteudo + NovoArquivo → ArmazenamentoDeArquivos::gravar()
 * ```
 *
 * `UploadedFile` é da borda Symfony e não entra no núcleo (INV-8). Esta classe é o único lugar que
 * o converte, e é ela que responde por INV-10.
 *
 * ## Uso
 *
 * ```php
 * $upload     = FonteDeUploadHttp::de($arquivo);
 * $armazenado = $upload->gravarEm($this->armazenamento, ChavesDeCliente::novoDocumento($cliente, $upload->extensao));
 * $doc->setCaminhoArquivo($armazenado->chave->nome);
 * ```
 *
 * A extensão sai daqui porque só a borda sabe adivinhá-la do conteúdo; o escopo e a categoria saem
 * da fábrica do domínio, porque só o domínio sabe de quem é o arquivo.
 *
 * ## INV-10 — por que a movimentação é o próprio `UploadedFile::move()`
 *
 * `move()` faz três coisas que um `rename()` cru não faz: `isValid()` com exceção tipada por
 * `UPLOAD_ERR_*`, `move_uploaded_file()` (a prova de que o arquivo veio mesmo de um upload HTTP)
 * e `chmod(0666 & ~umask())`. Reimplementar isso aqui seria copiar código do framework e, pior,
 * perder o modo de teste: a flag `$test` do `UploadedFile` é privada, e é ela que faz o
 * `KernelBrowser` funcionar sem `move_uploaded_file()`. Por isso o destino do `move()` é um
 * **temporário nosso**, e só depois o storage publica.
 *
 * ## A ordem que elimina o bug do Kanban e do ServiceDesk
 *
 * Tudo o que se lê do `UploadedFile` sai em {@see de()}, **antes** de qualquer movimentação. Depois
 * do `move()` o objeto aponta para um caminho que não existe mais, e `getSize()`/`getMimeType()`
 * estouram "stat failed" — era isso que derrubava o anexo do Kanban e o do chamado. Tamanho e MIME
 * medidos depois da escrita vêm em {@see ArquivoArmazenado}.
 *
 * ## Ownership do temporário (D9)
 *
 * O temporário é um {@see ArquivoTemporarioPossuido}, liberado em `finally`. A fonte é
 * `consumirOrigem: true`: gravando, o storage leva o arquivo embora e o `liberar()` não acha nada;
 * falhando, o `liberar()` apaga só a cópia — nunca o arquivo persistido, que mora em outro caminho.
 *
 * O temporário mora num diretório **privado** dentro de `sys_get_temp_dir()` — o mesmo sistema de
 * arquivos em que o PHP guarda o upload, então o `move()` é um `rename()` barato. Depois do `move()`
 * o PHP deixa de considerar o arquivo um upload e não o apaga mais no fim da requisição; se o
 * processo morrer entre o `move()` e a gravação, o atestado ou documento de cliente que sobrar não
 * pode ficar legível por outros usuários da máquina (DT-7).
 *
 * "Privado" é conferido, não suposto — e a conferência é a do núcleo, {@see DiretorioTemporarioPrivado}
 * (D29, desde a E2.6A): a mesma política vale para a cópia gravável do compressor. Qualquer desvio
 * lança em vez de seguir com o arquivo exposto.
 */
final class FonteDeUploadHttp
{
    private const FINALIDADE_DO_DIRETORIO_PRIVADO = 'upload';

    private bool $consumida = false;

    private function __construct(
        private readonly UploadedFile $arquivo,
        public readonly string $extensao,
        private readonly ?string $diretorioTemporario,
    ) {
    }

    /**
     * Confere o upload e lê o que precisa ser lido antes de mover.
     *
     * A extensão é `guessExtension() ?? 'bin'` — exatamente o que `ArquivoStorageService::salvar()`
     * usava. É adivinhada do **conteúdo**, nunca do nome enviado pelo cliente. `NovoArquivo` ainda
     * a saneia (D8).
     *
     * @param string|null $diretorioTemporario onde o upload espera a gravação; o padrão é o
     *                                         diretório privado — informar só em teste
     *
     * @throws FileException a mesma exceção tipada por `UPLOAD_ERR_*` que `UploadedFile::move()`
     *                       lança; nada é lido nem movido antes dela
     */
    public static function de(UploadedFile $arquivo, ?string $diretorioTemporario = null): self
    {
        if (!$arquivo->isValid()) {
            // Para upload inválido, `move()` só escolhe e lança a exceção tipada — não chega a
            // criar diretório nem a mover nada. Delegar garante a MESMA exceção do framework,
            // sem copiar a tabela de códigos para cá.
            $arquivo->move(sys_get_temp_dir());

            throw new FileException($arquivo->getErrorMessage());
        }

        return new self($arquivo, $arquivo->guessExtension() ?? 'bin', $diretorioTemporario);
    }

    /**
     * Move o upload para um temporário possuído e o entrega ao storage. **Só pode ser chamado uma
     * vez**: depois dele o `UploadedFile` já foi consumido.
     *
     * @throws FileException                                             se o `move()` recusar o arquivo
     * @throws \App\Shared\Armazenamento\Exception\FalhaDeArmazenamento se o storage não publicar
     */
    public function gravarEm(ArmazenamentoDeArquivos $armazenamento, NovoArquivo $destino): ArquivoArmazenado
    {
        if ($this->consumida) {
            throw new \LogicException('Este upload já foi gravado; o arquivo de origem não existe mais.');
        }

        $diretorio = $this->diretorioTemporario !== null
            ? DiretorioTemporarioPrivado::existente($this->diretorioTemporario)
            : DiretorioTemporarioPrivado::doProcesso(self::FINALIDADE_DO_DIRETORIO_PRIVADO);
        $temporario = $diretorio->novoArquivo('upload-');

        // Daqui em diante o `move()` pode ter levado o upload embora, mesmo que algo falhe depois.
        $this->consumida = true;

        try {
            $this->arquivo->move(\dirname($temporario->caminho()), basename($temporario->caminho()));

            return $armazenamento->gravar(
                $destino,
                FonteDeConteudo::deArquivoLocal($temporario->caminho(), consumirOrigem: true),
            );
        } finally {
            $temporario->liberar();
        }
    }
}
