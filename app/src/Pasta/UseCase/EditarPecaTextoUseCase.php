<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\TituloDePecaLongoDemaisException;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\FonteDeConteudo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regrava o HTML de uma peça existente, na MESMA chave, e atualiza tamanho e título.
 *
 * ## Arquivo sumido: falha, não recria (D14)
 *
 * Se o registro diz que a peça existe e o arquivo não está lá, isso é perda ou inconsistência do
 * acervo — e gravar por cima esconderia o fato, criando um arquivo novo como se nada tivesse
 * acontecido. Antes da E2.4B era exatamente o que acontecia: o `file_put_contents` recriava em
 * silêncio. Agora a presença é conferida antes e a ausência lança {@see ArquivoNaoEncontrado},
 * sem gravar nada e sem tocar na entidade.
 *
 * `existe()` também distingue ausência de pane: diretório ilegível lança `FalhaDeArmazenamento`,
 * que não é "arquivo sumido" e propaga como erro operacional (D12).
 *
 * **Janela residual:** os seis verbos do storage não têm "gravar só se existir". Se o arquivo
 * desaparecer entre o `existe()` e o `gravar()`, a gravação o recria. Só acontece se alguém apagar
 * o arquivo sem apagar a linha nesse intervalo.
 *
 * ## Título que não cabe: recusado antes de tocar no arquivo
 *
 * O banco recusaria no `flush` — mas aí o conteúdo novo já estaria publicado, o título e o
 * tamanho continuariam os antigos e o usuário receberia erro achando que nada foi salvo.
 *
 * ## A sobrescrita é atômica
 *
 * O backend grava num vizinho e publica com `rename()` — a peça nunca fica truncada no meio da
 * gravação (antes, o `file_put_contents` truncava no lugar). Os campos da entidade só mudam
 * depois de o conteúdo novo estar publicado.
 */
final class EditarPecaTextoUseCase
{
    /** `titulo` e `nome_original` são VARCHAR(255). */
    private const LIMITE_DA_COLUNA = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArmazenamentoDeArquivos $armazenamento,
    ) {}

    /**
     * @throws TituloDePecaLongoDemaisException quando o título novo não cabe na coluna
     * @throws ArquivoNaoEncontrado             quando o arquivo da peça não existe mais
     */
    public function executar(PastaDocumento $doc, string $conteudoHtml, ?string $titulo = null): void
    {
        $novoTitulo = $titulo !== null && trim($titulo) !== '' ? trim($titulo) : null;

        if ($novoTitulo !== null
            && (mb_strlen(mb_strtoupper($novoTitulo)) > self::LIMITE_DA_COLUNA
                || mb_strlen($novoTitulo . '.html') > self::LIMITE_DA_COLUNA)) {
            throw new TituloDePecaLongoDemaisException();
        }

        $chave = ChavesDePasta::documento($doc);

        if (!$this->armazenamento->existe($chave)) {
            throw ArquivoNaoEncontrado::para($chave);
        }

        $armazenado = $this->armazenamento->gravar($chave, FonteDeConteudo::deTexto($conteudoHtml));
        $doc->setTamanhoBytes($armazenado->tamanhoBytes);

        if ($novoTitulo !== null) {
            $doc->setTitulo($novoTitulo);
            $doc->setNomeOriginal($novoTitulo . '.html');
        }

        $this->em->flush();
    }
}
