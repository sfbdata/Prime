<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\DTO\ArtigoDoBlogOutput;
use App\Blog\DTO\SalvarArtigoDoBlogInput;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogRecusado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Salva um artigo do blog (botão "Salvar rascunho" do editor; spec painel-artigos-blogs, seções 1 e 3).
 *
 * Quem dispara: o Master, no editor de /master/blog/artigos/{id} (o controller exige ROLE_SUPER_ADMIN).
 * Objetivo: guardar o texto sem ir ao ar. Rascunho salva incompleto; as regras do zod só valem ao agendar/publicar.
 * Pré-condições: o Input já passou pelo Assert (formato do slug, tamanhos das colunas).
 * Fluxo principal: carrega o artigo (ou cria um rascunho novo); recusa slug de outro artigo; sanitiza o HTML pela lista
 * branca; gera o corpoMarkdown; calcula a leitura (200 palavras por minuto) quando vier vazia; grava, no escopo de
 * plataforma (auditoria sem escritório).
 * Fluxos alternativos: id inexistente (404); slug repetido (422); trocar o slug de um artigo que já foi ao ar sem
 * confirmar (422); capa (`imagemChave`) que não é uma imagem deste artigo (422).
 * Capa: `imagemChave` guarda o ID da {@see ImagemDoArtigo} (texto), não a chave do armazenamento. É dele que o pacote do
 * build (P4) tira `./imagens/{id}.{ext}` para o {@see \App\Blog\Service\ExportadorDeArtigo}, igual às imagens do corpo.
 * Limpeza (seção 4): imagem do artigo que não está no corpo, na capa nem na versão publicada (D7) sai junto, no mesmo flush; o arquivo sai só
 * DEPOIS do COMMIT ({@see RemocaoAposTransacao}), e falha física vira log, não erro. Imagem enviada há menos de
 * {@see self::CARENCIA_DA_IMAGEM_NOVA} fica (envio em andamento). `<img>` de imagem que não é deste artigo sai do HTML.
 * Pós-condições: a situação NÃO muda (D7). Num artigo publicado ou despublicado, texto novo fica como
 * "alterações não publicadas" até Publicar de novo; salvar sem mudança nenhuma não marca nada.
 */
final class SalvarArtigoDoBlogUseCase
{
    private const PALAVRAS_POR_MINUTO = 200;

    /**
     * Imagem enviada há menos disto não é limpa, mesmo sem uso: o Salvar pode sair enquanto a capa ou uma imagem do
     * corpo ainda sobe, ou de outra aba aberta com o HTML antigo. Sem a carência, a limpeza apagaria a imagem recém-
     * enviada e a tela ficaria apontando para o vazio. Ela sai num salvar posterior, se continuar sem uso.
     */
    public const CARENCIA_DA_IMAGEM_NOVA = '-1 hour';

    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly ImagemDoArtigoRepository $imagens,
        private readonly HtmlDoArtigoParaMarkdown $conversor,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(SalvarArtigoDoBlogInput $input): ArtigoDoBlogOutput
    {
        $artigo = $input->id === null ? $this->novoRascunho($input) : $this->carregar($input->id);
        $this->exigirSlugLivre($artigo, $input);
        $imagens = $artigo->getId() === null ? [] : $this->imagens->doArtigo($artigo);

        $antes = $artigo->getId() === null ? null : $artigo->assinaturaDoConteudo();
        $this->aplicar($artigo, $input, $imagens);
        if ($antes !== null && $antes !== $artigo->assinaturaDoConteudo()) {
            $artigo->registrarAlteracao($input->ator, $this->relogio->now());
        }

        // As chaves antes do flush: depois dele a imagem removida some do UnitOfWork.
        $semUso = $this->semUso($artigo, $imagens);
        $chaves = array_map(ChavesDoBlog::imagem(...), $semUso);
        foreach ($semUso as $imagem) {
            $this->imagens->remover($imagem);
        }
        $this->escopoDePlataforma->executar(fn () => $this->artigos->salvar($artigo, flush: true));
        $this->remocao->remover($chaves, 'blog_imagem_sem_uso');

        return ArtigoDoBlogOutput::fromEntity($artigo);
    }

    private function novoRascunho(SalvarArtigoDoBlogInput $input): ArtigoDoBlog
    {
        return new ArtigoDoBlog($input->slug, BlogsDoArtigo::from($input->blogs), $input->ator, $this->relogio->now());
    }

    private function carregar(int $id): ArtigoDoBlog
    {
        return $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
    }

    private function exigirSlugLivre(ArtigoDoBlog $artigo, SalvarArtigoDoBlogInput $input): void
    {
        if ($this->artigos->existeSlug($input->slug, $artigo->getId())) {
            throw ArtigoDoBlogRecusado::slugRepetido($input->slug);
        }

        $jaFoiAoAr = \in_array($artigo->getSituacao(), [SituacaoDoArtigo::Publicado, SituacaoDoArtigo::Despublicado], true);
        if ($jaFoiAoAr && $artigo->getSlug() !== $input->slug && !$input->confirmarTrocaDeSlug) {
            throw ArtigoDoBlogRecusado::trocaDeSlugSemConfirmacao();
        }
    }

    /** @param list<ImagemDoArtigo> $imagens as do artigo, antes de salvar */
    private function aplicar(ArtigoDoBlog $artigo, SalvarArtigoDoBlogInput $input, array $imagens): void
    {
        $capa = $this->capa($input->imagemChave, $imagens);
        $extensoes = [];
        foreach ($imagens as $imagem) {
            $extensoes[(int) $imagem->getId()] = $imagem->extensao();
        }
        $html = $this->conversor->sanitizar($input->corpoHtml, array_keys($extensoes));

        $artigo->trocarSlug($input->slug);
        $artigo->definirBlogs(BlogsDoArtigo::from($input->blogs));
        $artigo->editarTextos($input->titulo, $input->tituloCurto, $input->tituloSeo, $input->descricao, $input->linhaFina, $input->resumo);
        $artigo->classificar($input->categoria, $input->autor, $input->revisorNome, $input->revisorOab, $this->leitura($input, $html), $input->destaque);
        $artigo->definirImagem($capa, $input->imagemAlt);
        $artigo->definirReferencias($input->referencias);
        $artigo->definirRelacionados($input->relacionados);
        $artigo->definirMarcadores($input->marcadores);
        $artigo->definirCorpo($html, $this->conversor->converter($html, $extensoes));
    }

    /**
     * O id da capa, conferido contra as imagens DESTE artigo (outro artigo, id inventado ou a chave do armazenamento são
     * recusados: a imagem de outro artigo some quando ele a limpar).
     *
     * @param list<ImagemDoArtigo> $imagens
     */
    private function capa(?string $imagemChave, array $imagens): ?string
    {
        $id = trim((string) $imagemChave);
        if ($id === '') {
            return null;
        }
        foreach ($imagens as $imagem) {
            if ((string) $imagem->getId() === $id) {
                return $id;
            }
        }

        throw ArtigoDoBlogRecusado::capaDeOutroArtigo();
    }

    /**
     * Imagens do artigo que o corpo salvo e a capa não citam mais, fora as enviadas na carência (envio em andamento).
     *
     * @param list<ImagemDoArtigo> $imagens
     *
     * @return list<ImagemDoArtigo>
     */
    private function semUso(ArtigoDoBlog $artigo, array $imagens): array
    {
        // A versão no ar também usa imagens (D7): elas ficam até a próxima publicação ou o Despublicar.
        $usadas = [...$this->conversor->idsDasImagens($artigo->getCorpoHtml()), ...$artigo->idsDasImagensPublicadas()];
        if ($artigo->getImagemChave() !== null) {
            $usadas[] = (int) $artigo->getImagemChave();
        }

        $limite = $this->relogio->now()->modify(self::CARENCIA_DA_IMAGEM_NOVA);

        return array_values(array_filter(
            $imagens,
            static fn (ImagemDoArtigo $i): bool => !\in_array((int) $i->getId(), $usadas, true) && $i->getCriadaEm() < $limite,
        ));
    }

    /** "N min" pelo número de palavras do corpo, quando o Master não escreveu a leitura. */
    private function leitura(SalvarArtigoDoBlogInput $input, string $html): string
    {
        if (trim($input->leitura) !== '') {
            return $input->leitura;
        }

        $texto = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $palavras = preg_match_all('/[\p{L}\p{N}]+(?:[\'\-.][\p{L}\p{N}]+)*/u', $texto);

        return max(1, (int) ceil(((int) $palavras) / self::PALAVRAS_POR_MINUTO)) . ' min';
    }
}
