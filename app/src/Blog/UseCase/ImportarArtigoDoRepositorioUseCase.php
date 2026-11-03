<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\DTO\ImportacaoDoArtigoOutput;
use App\Blog\DTO\ImportarArtigoDoRepositorioInput;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Exception\ArtigoDoRepositorioRecusado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Service\ArquivoDoArtigo;
use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Blog\Service\MarkdownDoArtigoParaHtml;
use App\Blog\Service\ValidadorDoArtigo;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Importa um artigo do repositório dos sites para o banco (spec painel-artigos-blogs, "Decisão de base" e fatia P2).
 *
 * Quem dispara: o comando `blog:importar-do-repositorio`, uma vez, na implantação (spec, seção 10, item 5).
 * Objetivo: o banco passar a ser a fonte dos artigos que hoje estão em `src/content/artigos/` dos blogs.
 * Pré-condições: o conteúdo é um `.md` com frontmatter; `blogs` é o blog de origem (ou `ambos`, se o comando achou o
 * mesmo arquivo nos dois blogs).
 * Fluxo principal: lê o frontmatter; se o slug já existe no banco, pula (rodar de novo não duplica); converte o corpo
 * para o HTML do editor ({@see MarkdownDoArtigoParaHtml}), passa pelo sanitizador do domínio e gera o `corpoMarkdown`
 * pelo conversor de sempre; preenche todos os campos; define a situação; confere as regras do blog e grava no escopo de
 * plataforma (auditoria sem escritório).
 * Situação: `rascunho: true` vira rascunho (com a data guardada, se ela for futura); sem rascunho, a data que já
 * chegou vira publicado (com `atualizadoEm`, se houver) e a data futura vira agendado. Datas à meia-noite de Brasília.
 * Fluxos alternativos: arquivo fora do formato, imagem no corpo ou regra do blog quebrada: recusa com a mensagem
 * ({@see ArtigoDoRepositorioRecusado}), nada é gravado. Com `simular`, faz tudo menos gravar.
 * Pós-condições: a capa (`imagem`) não é importada (as imagens são a P3): vira um aviso no resultado.
 */
final class ImportarArtigoDoRepositorioUseCase
{
    private const PALAVRAS_POR_MINUTO = 200;

    /** Campos do frontmatter que o BlueJus guarda; o resto vira aviso. */
    private const CAMPOS_CONHECIDOS = ['titulo', 'tituloCurto', 'tituloSeo', 'descricao', 'linhaFina', 'resumo', 'slug', 'categoria',
        'marcadores', 'autor', 'revisor', 'publicadoEm', 'atualizadoEm', 'leitura', 'imagem', 'imagemAlt', 'referencias',
        'relacionados', 'destaque', 'rascunho'];

    /** Regras que valem até para o rascunho: sem elas a gravação quebraria na coluna. */
    private const REGRAS_DO_RASCUNHO = ['slug', 'tituloSeo', 'descricao', 'marcadores'];

    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly MarkdownDoArtigoParaHtml $markdownParaHtml,
        private readonly HtmlDoArtigoParaMarkdown $htmlParaMarkdown,
        private readonly ValidadorDoArtigo $validador,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(ImportarArtigoDoRepositorioInput $input): ImportacaoDoArtigoOutput
    {
        $arquivo = ArquivoDoArtigo::ler($input->conteudo);
        $slug = $arquivo->slug();
        if ($this->artigos->existeSlug($slug)) {
            return ImportacaoDoArtigoOutput::pulado($slug);
        }

        $agora = $this->relogio->now();
        $artigo = new ArtigoDoBlog($slug, $input->blogs, null, $agora);
        $avisos = $this->preencher($artigo, $arquivo);
        $this->definirSituacao($artigo, $arquivo->frontmatter, $agora);
        $this->exigirRegras($artigo, $input);

        if (!$input->simular) {
            $this->escopoDePlataforma->executar(fn () => $this->artigos->salvar($artigo, flush: true));
        }

        return ImportacaoDoArtigoOutput::importado($slug, $input->blogs, $artigo->getSituacao(), $avisos);
    }

    /** @return list<string> avisos do que ficou de fora */
    private function preencher(ArtigoDoBlog $artigo, ArquivoDoArtigo $arquivo): array
    {
        $f = $arquivo->frontmatter;
        $html = $this->htmlParaMarkdown->sanitizar($this->markdownParaHtml->converter($arquivo->corpo));

        $artigo->editarTextos(self::texto($f, 'titulo'), self::texto($f, 'tituloCurto'), self::texto($f, 'tituloSeo'), self::texto($f, 'descricao'), self::texto($f, 'linhaFina'), self::texto($f, 'resumo'));
        $revisor = \is_array($f['revisor'] ?? null) ? $f['revisor'] : [];
        $leitura = self::texto($f, 'leitura');
        $artigo->classificar(
            self::texto($f, 'categoria'),
            self::texto($f, 'autor'),
            self::texto($revisor, 'nome'),
            self::texto($revisor, 'oab'),
            $leitura !== '' ? $leitura : $this->leitura($html),
            ($f['destaque'] ?? false) === true,
        );
        $artigo->definirReferencias(self::referencias($f['referencias'] ?? []));
        $artigo->definirRelacionados(self::textos($f['relacionados'] ?? []));
        $artigo->definirMarcadores(\is_array($f['marcadores'] ?? null) ? $f['marcadores'] : []);
        $artigo->definirCorpo($html, $this->htmlParaMarkdown->converter($html));

        $avisos = [];
        if (isset($f['imagem'])) {
            $avisos[] = 'A imagem de capa ficou de fora (as imagens ainda não são importadas): envie-a pelo editor.';
        }
        foreach (array_diff(array_keys($f), self::CAMPOS_CONHECIDOS) as $campo) {
            $avisos[] = sprintf('O campo "%s" do frontmatter não existe no BlueJus e ficou de fora.', $campo);
        }

        return $avisos;
    }

    /** @param array<string, mixed> $f */
    private function definirSituacao(ArtigoDoBlog $artigo, array $f, \DateTimeImmutable $agora): void
    {
        $publicadoEm = self::data($f, 'publicadoEm');
        if (($f['rascunho'] ?? false) === true) {
            if ($publicadoEm !== null && $publicadoEm > $agora) {
                // guarda a data futura para o Master agendar depois
                $artigo->agendar($publicadoEm, $agora);
                $artigo->cancelarAgendamento();
            }

            return;
        }
        if ($publicadoEm === null) {
            return; // a regra "Informe a data de publicação." recusa adiante
        }
        if ($publicadoEm > $agora) {
            $artigo->agendar($publicadoEm, $agora);

            return;
        }

        $artigo->publicar($publicadoEm);
        $atualizadoEm = self::data($f, 'atualizadoEm');
        if ($atualizadoEm !== null) {
            // republica com a data da última atualização (a entidade só muda `atualizadoEm` assim)
            $artigo->registrarAlteracao(null, $agora);
            $artigo->publicar($atualizadoEm);
        }
    }

    private function exigirRegras(ArtigoDoBlog $artigo, ImportarArtigoDoRepositorioInput $input): void
    {
        $slugs = array_values(array_unique([...$this->artigos->slugsExistentes(), ...$input->slugsDaImportacao]));
        $erros = $this->validador->validar($artigo, $slugs);
        if ($artigo->getSituacao() === SituacaoDoArtigo::Rascunho) {
            $erros = array_intersect_key($erros, array_flip(self::REGRAS_DO_RASCUNHO));
        }
        if ($erros !== []) {
            throw ArtigoDoRepositorioRecusado::naoPassaNasRegras($erros);
        }
    }

    /** "N min" pelo número de palavras, como no SalvarArtigoDoBlogUseCase, quando o arquivo não traz a leitura. */
    private function leitura(string $html): string
    {
        $texto = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $palavras = preg_match_all('/[\p{L}\p{N}]+(?:[\'\-.][\p{L}\p{N}]+)*/u', $texto);

        return max(1, (int) ceil(((int) $palavras) / self::PALAVRAS_POR_MINUTO)) . ' min';
    }

    // ---- leitura do frontmatter ----

    /** @param array<mixed> $dados */
    private static function texto(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? '';

        return \is_string($valor) || \is_int($valor) || \is_float($valor) ? trim((string) $valor) : '';
    }

    /** @return list<string> */
    private static function textos(mixed $lista): array
    {
        if (!\is_array($lista)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $v): string => trim((string) $v), array_filter($lista, 'is_string')));
    }

    /** @return list<array{texto: string, url: string, rotulo: string}> */
    private static function referencias(mixed $lista): array
    {
        if (!\is_array($lista)) {
            return [];
        }

        $saida = [];
        foreach ($lista as $referencia) {
            if (\is_array($referencia)) {
                $saida[] = ['texto' => self::texto($referencia, 'texto'), 'url' => self::texto($referencia, 'url'), 'rotulo' => self::texto($referencia, 'rotulo')];
            }
        }

        return $saida;
    }

    /**
     * A data do frontmatter (AAAA-MM-DD, com ou sem aspas) à meia-noite no fuso do sistema (Brasília).
     *
     * @param array<string, mixed> $f
     */
    private static function data(array $f, string $campo): ?\DateTimeImmutable
    {
        $valor = $f[$campo] ?? null;
        if ($valor === null || $valor === '') {
            return null;
        }
        // o YAML lê a data sem aspas como data em UTC: o dia é o que está escrito
        $dia = $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : (\is_string($valor) ? trim($valor) : '');
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $dia) : false;
        if ($data === false || $data->format('Y-m-d') !== $dia) {
            throw ArtigoDoRepositorioRecusado::dataInvalida($campo);
        }

        return $data;
    }
}
