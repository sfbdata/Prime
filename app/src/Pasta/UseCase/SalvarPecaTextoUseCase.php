<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\TituloDePecaLongoDemaisException;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Cria uma peça escrita no editor: o HTML vira um arquivo novo e um `PastaDocumento`.
 *
 * Ordem (E2.4B): o documento recebe o escritório, o storage grava e cunha o nome, e só então o
 * documento é completado e persistido. Falha do storage (`FalhaDeArmazenamento`) propaga antes do
 * `persist` — nenhuma linha fica apontando para arquivo que não foi gravado. Antes, o
 * `file_put_contents` do storage antigo falhava calado em produção e a peça era salva mesmo assim.
 *
 * Título que não cabe na coluna é recusado ANTES de gravar: senão o banco recusaria no `flush`,
 * depois de o arquivo já existir, e ele ficaria órfão por uma falha que dava para prever.
 *
 * O MIME é fixo `text/html`, e não o medido: o libmagic mede `text/plain` para um fragmento HTML
 * curto, e editar, exportar e a listagem de peças decidem por `text/html`.
 */
final class SalvarPecaTextoUseCase
{
    private const MIME_DA_PECA = 'text/html';

    /** `titulo` e `nome_original` são VARCHAR(255). */
    private const LIMITE_DA_COLUNA = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArmazenamentoDeArquivos $armazenamento,
    ) {}

    public function executar(
        Pasta $pasta,
        ?PastaSecao $secao,
        string $conteudoHtml,
        string $titulo,
        string $categoria,
        Tenant $tenant,
    ): PastaDocumento {
        if ($secao !== null && $secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        if ($secao !== null && $secao->getPasta() !== $pasta) {
            throw new \InvalidArgumentException('Seção não pertence à pasta do documento.');
        }

        if (trim($titulo) === '') {
            throw new \InvalidArgumentException('O título da peça não pode ser vazio.');
        }

        $doc = new PastaDocumento();
        $doc->setTenant($tenant);
        $doc->setTitulo($titulo);
        $doc->setNomeOriginal($titulo . '.html');

        if (mb_strlen($doc->getTitulo()) > self::LIMITE_DA_COLUNA
            || mb_strlen($doc->getNomeOriginal()) > self::LIMITE_DA_COLUNA) {
            throw new TituloDePecaLongoDemaisException();
        }

        $armazenado = $this->armazenamento->gravar(
            ChavesDePasta::novoDocumento($doc, 'html'),
            FonteDeConteudo::deTexto($conteudoHtml),
        );

        $doc->setPasta($pasta);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo($armazenado->chave->nome);
        $doc->setMimeType(self::MIME_DA_PECA);
        $doc->setTamanhoBytes($armazenado->tamanhoBytes);
        $doc->setSecao($secao);

        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }
}
