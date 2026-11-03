<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogRecusado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Http\FonteDeUploadHttp;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Envia uma imagem para o corpo ou para a capa de um artigo dos blogs (spec painel-artigos-blogs, seção 4).
 *
 * Quem dispara: o Master, pelo botão de imagem do TinyMCE ou pelo cartão "Imagem de compartilhamento" do editor (a
 * rota exige ROLE_SUPER_ADMIN e o CSRF `master_blog`).
 * Regras (422 no campo `imagem`, nada gravado): JPG, PNG ou WEBP pelo CONTEÚDO (o nome enviado não conta), até 5 MB,
 * com largura e altura legíveis e até {@see self::LADO_MAXIMO} px de lado (imagem maior derrubaria o build do Astro).
 * Sem compressão: o Astro otimiza no build.
 * Fluxo principal: grava o arquivo com nome cunhado pelo storage (chave global, {@see ChavesDoBlog}), cria a
 * {@see ImagemDoArtigo} com tipo, tamanho medido pelo storage, largura e altura, e confirma. O arquivo novo só sai se a
 * transação comprovadamente não confirmou ({@see TransacaoComArquivoNovo}). Auditoria no escopo de plataforma.
 * Pós-condição: a imagem fica ligada ao artigo, mas sem uso até o corpo ou a capa a citar; o próximo salvar que não a
 * citar a apaga ({@see SalvarArtigoDoBlogUseCase}).
 */
final class EnviarImagemDoArtigoUseCase
{
    public const TAMANHO_MAXIMO = 5 * 1024 * 1024;
    public const LADO_MAXIMO = 10000;

    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly ImagemDoArtigoRepository $imagens,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /**
     * @throws ArtigoDoBlogNaoEncontrado
     * @throws ArtigoDoBlogRecusado
     */
    public function executar(int $artigoId, UploadedFile $arquivo, ?string $alt): ImagemDoArtigo
    {
        $artigo = $this->artigos->porId($artigoId) ?? throw ArtigoDoBlogNaoEncontrado::comId($artigoId);
        $mime = self::exigirTipoETamanho($arquivo);
        [$largura, $altura] = self::exigirDimensoes($arquivo);
        $nomeOriginal = self::nomeOriginal($arquivo, ImagemDoArtigo::EXTENSOES_POR_MIME[$mime]);

        $armazenado = FonteDeUploadHttp::de($arquivo)->gravarEm($this->armazenamento, ChavesDoBlog::novaImagem(ImagemDoArtigo::EXTENSOES_POR_MIME[$mime]));
        $imagem = new ImagemDoArtigo($artigo, $armazenado->chave->nome, $nomeOriginal, $mime, $largura, $altura, $armazenado->tamanhoBytes, $this->relogio->now());
        $imagem->descrever($alt === null ? null : mb_substr($alt, 0, 255));
        $this->imagens->salvar($imagem);
        // Conteúdo da plataforma: a linha de auditoria fica sem escritório.
        $this->escopoDePlataforma->executar(fn () => $this->transacao->confirmar(static fn (): array => [$armazenado->chave], 'blog_imagem'));

        return $imagem;
    }

    /** @return string o tipo lido do conteúdo, um de {@see ImagemDoArtigo::EXTENSOES_POR_MIME} */
    private static function exigirTipoETamanho(UploadedFile $arquivo): string
    {
        if (!$arquivo->isValid()) {
            throw ArtigoDoBlogRecusado::imagem('O envio da imagem falhou. Tente de novo (até 5 MB).');
        }
        $tamanho = (int) $arquivo->getSize();
        if ($tamanho > self::TAMANHO_MAXIMO) {
            throw ArtigoDoBlogRecusado::imagem('A imagem passa de 5 MB.');
        }
        if ($tamanho === 0) {
            throw ArtigoDoBlogRecusado::imagem('A imagem está vazia.');
        }
        $mime = strtolower((string) $arquivo->getMimeType());
        if (!isset(ImagemDoArtigo::EXTENSOES_POR_MIME[$mime])) {
            throw ArtigoDoBlogRecusado::imagem('Envie uma imagem JPG, PNG ou WEBP.');
        }

        return $mime;
    }

    /** @return array{int, int} largura e altura, em pixels */
    private static function exigirDimensoes(UploadedFile $arquivo): array
    {
        $medidas = @getimagesize($arquivo->getPathname());
        $largura = \is_array($medidas) ? (int) $medidas[0] : 0;
        $altura = \is_array($medidas) ? (int) $medidas[1] : 0;
        if ($largura < 1 || $altura < 1) {
            throw ArtigoDoBlogRecusado::imagem('Não foi possível ler a largura e a altura da imagem. Envie outro arquivo.');
        }
        if ($largura > self::LADO_MAXIMO || $altura > self::LADO_MAXIMO) {
            throw ArtigoDoBlogRecusado::imagem(sprintf('A imagem passa de %d pixels de lado. Reduza e envie de novo.', self::LADO_MAXIMO));
        }

        return [$largura, $altura];
    }

    /** O nome do arquivo no computador do Master, sem o caminho; vazio vira `imagem.{ext}`. */
    private static function nomeOriginal(UploadedFile $arquivo, string $extensao): string
    {
        $nome = trim(basename(str_replace('\\', '/', $arquivo->getClientOriginalName())));

        return $nome === '' ? 'imagem.' . $extensao : mb_substr($nome, 0, 255);
    }
}
