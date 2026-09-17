<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\DTO\ExportarPecaTextoOutput;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use App\Pasta\Service\ReferenciasDePecaHtml;
use PhpOffice\PhpWord\Shared\Html;
use Psr\Log\LoggerInterface;

/**
 * Exporta uma peça escrita no editor para DOCX, ODT, PDF ou TXT.
 *
 * ## Peça ausente ≠ storage indisponível (D13)
 *
 * O HTML é lido do armazenamento por chave. Sem arquivo na chave, `ler()` lança
 * {@see ArquivoNaoEncontrado} e o controller responde 404. Pane do storage (diretório ou arquivo
 * ilegível, backend fora) é `FalhaDeArmazenamento` e propaga como erro — nunca vira 404. Antes da
 * E2.4B, o `file_get_contents` devolvia false com um warning e o export saía VAZIO, com 200.
 *
 * ## Imagem só por chave + escritório, num diretório só deste export (E2.6C, D32)
 *
 * Até a E2.6B o export apenas trocava o prefixo `uploads/pastas/` por um caminho de disco e
 * entregava o HTML às bibliotecas. Tudo o que não casava o prefixo seguia intacto — e a revisão
 * PROVOU, com teste local, o que isso valia:
 *
 *  - `<img src="http://169.254.169.254/...">` fazia o PhpWord BUSCAR a URL (`Html::parseImage` →
 *    `file_get_contents`) e embutir a resposta no DOCX/ODT: SSRF a partir de conteúdo que qualquer
 *    usuário com permissão de editar a peça escreve;
 *  - `<img src="/uploads/pastas/%2e%2e/<outro>/x.png">` embutia a imagem de OUTRO escritório — o
 *    PhpWord decodifica o `%2e%2e` depois de qualquer reescrita nossa; com `../` literal, o mesmo
 *    valia para o PDF, porque o `chroot` do Dompdf era `public/` inteiro, onde moram todos.
 *
 * Limite medido do parser, que vale para os três formatos desde a E2.6C: um nó de TEXTO acima de
 * ~10 MB é truncado pelo libxml em silêncio (peça de 12 MB sem tags saiu com 10.000.000 bytes). Para
 * DOCX/ODT isso já valia antes — o `sanitizarParaXhtml` usa o mesmo parser.
 *
 * Agora cada `<img>` passa por uma allowlist ({@see ReferenciasDePecaHtml::nomeDeImagemDoEscritorio()}),
 * a imagem é resolvida por `ChavesDePasta::imagemDoEditor($escritório do documento, $nome)` e
 * MATERIALIZADA numa {@see AreaTemporariaPrivada} desta execução; o `chroot` do Dompdf passa a ser
 * essa área e os protocolos ficam só em `file://`. O que não resolve é PULADO (D32): a peça exporta
 * sem aquela imagem em vez de vazar arquivo ou derrubar a requisição.
 */
final class ExportarPecaTextoUseCase
{
    /** O diretório do processo onde as áreas de export nascem: `jusprime-export-<uid>`. */
    private const FINALIDADE_DA_AREA = 'export';

    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly ReferenciasDePecaHtml $referencias,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ArquivoNaoEncontrado quando o arquivo da peça não existe
     */
    public function executar(PastaDocumento $doc, string $formato): ExportarPecaTextoOutput
    {
        if (!\in_array($formato, ['docx', 'odt', 'pdf', 'txt'], true)) {
            throw new \InvalidArgumentException("Formato não suportado: {$formato}");
        }

        $html = $this->armazenamento->ler(ChavesDePasta::documento($doc));

        if ($formato === 'txt') {
            return $this->gerarTxt($doc, $html); // texto puro não lê imagem nenhuma
        }

        // A área é desta execução: as imagens saem daqui, e o `finally` a leva junto.
        $area = AreaTemporariaPrivada::criar(self::FINALIDADE_DA_AREA);

        try {
            $htmlExport = $this->materializarImagens($html, $doc, $area);

            return match ($formato) {
                'docx'  => $this->gerarDocx($doc, $htmlExport, $area),
                'odt'   => $this->gerarOdt($doc, $htmlExport, $area),
                default => $this->gerarPdf($doc, $htmlExport, $area),
            };
        } finally {
            $area->liberar();
        }
    }

    /**
     * Troca cada `<img>` por uma imagem MATERIALIZADA na área — ou remove a tag.
     *
     * Trabalha sobre o DOM, não sobre a string: o que decide é o valor do atributo `src` inteiro,
     * não um trecho encontrado no meio do HTML. Sem `<img>` no documento, nada é tocado.
     */
    private function materializarImagens(string $html, PastaDocumento $doc, AreaTemporariaPrivada $area): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        $dom = $this->comoDom($html);
        if ($dom === null) {
            // Sem DOM não há como decidir imagem nenhuma. Não devolver o HTML cru aqui seria perder
            // a peça; o que impede o estrago é o `chroot`/protocolo do Dompdf e, no DOCX, o fato de
            // este ramo só acontecer com HTML que o parser recusa por inteiro.
            $this->logger->warning('Não foi possível interpretar o HTML da peça para tratar as imagens.', [
                'documento' => $doc->getId(),
            ]);

            return $html;
        }

        $tenant   = $doc->getTenant();
        $tenantId = $tenant?->getId();

        // Cópia da lista: remover nó de uma NodeList viva pula o vizinho.
        /** @var list<\DOMElement> $imagens */
        $imagens = iterator_to_array($dom->getElementsByTagName('img'));

        $body = $dom->getElementsByTagName('body')->item(0);

        foreach ($imagens as $img) {
            // O que o parser jogou para FORA do corpo não entra no export (a serialização é do
            // corpo). Materializar seria trabalho perdido, e sumir calado esconde do usuário que a
            // peça tinha conteúdo malformado.
            if ($body !== null && !$this->dentroDe($img, $body)) {
                $this->logger->warning('Trecho da peça ficou fora do corpo do documento e não entra no export.', [
                    'documento' => $doc->getId(),
                ]);

                continue;
            }

            $src     = $img->getAttribute('src');
            $nome    = $this->referencias->nomeDeImagemDoEscritorio($src, $tenantId);
            $caminho = $nome === null || $tenant === null ? null : $this->materializar($tenant, $nome, $area);

            if ($caminho === null) {
                $this->logger->warning('Imagem da peça não foi embutida no export.', [
                    'documento' => $doc->getId(),
                    'motivo'    => match (true) {
                        $nome === null   => 'referência fora do escritório ou fora do padrão',
                        $tenant === null => 'documento sem escritório',
                        default          => 'arquivo não encontrado ou não materializável',
                    },
                ]);
                $img->parentNode?->removeChild($img);

                continue;
            }

            $img->setAttribute('src', $caminho);
        }

        // ATENÇÃO: corpo VAZIO é resultado legítimo — é o que sobra de uma peça que era só a imagem
        // recusada. Um fallback "se não sobrou nada, usa o HTML original" devolvia a peça inteira,
        // com o `src` malicioso, para a biblioteca: foi assim que o SSRF continuou aberto até a
        // revisão da 6C medir. Só a ausência de `<body>` (DOM impossível) volta ao original.
        $corpo = $this->corpoComoHtml($dom);

        return $corpo ?? $html;
    }

    /**
     * O caminho da imagem dentro da área, ou null quando ela não existe para este escritório.
     *
     * Nome que não vira chave válida é `null` do mesmo jeito: a fábrica é quem diz o que é
     * endereçável, e uma chave inválida aqui significa `src` que não veio do editor.
     */
    private function materializar(Tenant $tenant, string $nome, AreaTemporariaPrivada $area): ?string
    {
        try {
            $chave = ChavesDePasta::imagemDoEditor($tenant, $nome);

            if (!$this->armazenamento->existe($chave)) {
                return null;
            }

            $conteudo = $this->armazenamento->ler($chave);

            // Presente não basta: o PhpWord LANÇA diante de conteúdo que não é imagem
            // (`InvalidImageException`), e uma imagem corrompida no storage tornava a peça
            // inexportável em DOCX/ODT para sempre. O editor só aceita JPEG e PNG, então o
            // cabeçalho responde — e o que não é imagem é pulado, como o ausente (D32d).
            if (@getimagesizefromstring($conteudo) === false) {
                return null;
            }

            return $area->gravar($conteudo, pathinfo($nome, \PATHINFO_EXTENSION));
        } catch (ChaveDeArquivoInvalida|ArquivoNaoEncontrado|FalhaNoTemporario) {
            // Inclui a extensão que a área recusa: "não consigo materializar" é imagem pulada
            // (D32d), nunca export derrubado.
            return null;
        }
    }

    private function dentroDe(\DOMNode $no, \DOMNode $ancestral): bool
    {
        for ($pai = $no->parentNode; $pai !== null; $pai = $pai->parentNode) {
            if ($pai === $ancestral) {
                return true;
            }
        }

        return false;
    }

    private function comoDom(string $html): ?\DOMDocument
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $ok  = $dom->loadHTML(
            '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $ok ? $dom : null;
    }

    private function corpoComoHtml(\DOMDocument $dom): ?string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return null;
        }

        $saida = '';
        foreach ($body->childNodes as $node) {
            $saida .= $dom->saveHTML($node);
        }

        return $saida; // string vazia é resposta, não ausência de resposta
    }

    private function sanitizarParaXhtml(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $body = $doc->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return $html;
        }

        $saida = '';
        foreach ($body->childNodes as $node) {
            $saida .= $doc->saveXML($node);
        }

        return $saida ?: $html;
    }

    private function gerarDocx(PastaDocumento $doc, string $html, AreaTemporariaPrivada $area): ExportarPecaTextoOutput
    {
        $tempDirAnterior = Settings::getTempDir();
        Settings::setTempDir($area->caminho());

        try {
            return $this->montarComPhpWord($doc, $html, 'Word2007', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        } finally {
            // `Settings` é estado global do PhpWord: sem devolver, ele fica apontando para uma área
            // já apagada — e o próximo uso, em outro lugar do processo, herda isso.
            Settings::setTempDir($tempDirAnterior);
        }
    }

    private function gerarOdt(PastaDocumento $doc, string $html, AreaTemporariaPrivada $area): ExportarPecaTextoOutput
    {
        $tempDirAnterior = Settings::getTempDir();
        Settings::setTempDir($area->caminho());

        try {
            return $this->montarComPhpWord($doc, $html, 'ODText', 'odt', 'application/vnd.oasis.opendocument.text');
        } finally {
            Settings::setTempDir($tempDirAnterior);
        }
    }

    /** DOCX e ODT são o mesmo caminho do PhpWord; muda só o escritor, a extensão e o MIME. */
    private function montarComPhpWord(PastaDocumento $doc, string $html, string $escritor, string $extensao, string $mimeType): ExportarPecaTextoOutput
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        Html::addHtml($section, $this->sanitizarParaXhtml($html), false, false);

        $writer = IOFactory::createWriter($phpWord, $escritor);
        ob_start();
        $writer->save('php://output');
        $conteudo = (string) ob_get_clean();

        return new ExportarPecaTextoOutput(
            conteudo: $conteudo,
            mimeType: $mimeType,
            nomeArquivo: $this->nomeSemExtensao($doc) . '.' . $extensao,
        );
    }

    private function gerarPdf(PastaDocumento $doc, string $html, AreaTemporariaPrivada $area): ExportarPecaTextoOutput
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        // O chroot é a área DESTE export: as únicas imagens alcançáveis são as que acabamos de
        // materializar por chave. Antes era `public/` inteiro — onde moram os arquivos de todos os
        // escritórios, e um `../` bastava para atravessar.
        //
        // Duas ressalvas medidas no vendor (`Options::validateLocalUri`): o Dompdf SEMPRE acrescenta
        // o próprio `rootDir` (o diretório dele no vendor) à lista, e compara prefixo com
        // `strpos(...) === 0`, sem fronteira de barra. Por isso o chroot é a segunda tranca, nunca a
        // primeira: quem decide o que entra é a allowlist do `src`, acima.
        $options->set('chroot', $area->caminho());
        $options->set('tempDir', $area->caminho());
        // Só `file://` fica permitido: some o `data:` (que o Dompdf aceita sem nenhuma regra) e
        // somem `http`/`https` — a allowlist do `src` já os recusa, isto é a segunda tranca.
        $options->setAllowedProtocols(['file://']);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new ExportarPecaTextoOutput(
            conteudo: (string) $dompdf->output(),
            mimeType: 'application/pdf',
            nomeArquivo: $this->nomeSemExtensao($doc) . '.pdf',
        );
    }

    private function gerarTxt(PastaDocumento $doc, string $html): ExportarPecaTextoOutput
    {
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return new ExportarPecaTextoOutput(
            conteudo: $texto,
            mimeType: 'text/plain',
            nomeArquivo: $this->nomeSemExtensao($doc) . '.txt',
        );
    }

    private function nomeSemExtensao(PastaDocumento $doc): string
    {
        return pathinfo($doc->getTitulo(), PATHINFO_FILENAME) ?: $doc->getTitulo();
    }
}
