<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\CopiaComCarimbo;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\CarimboVisivel;
use App\AssinaturaEletronica\Service\CarimboVisivelAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * A "Cópia com carimbo visível" do Detalhe (etapa 5, bloco 5B; `presCarimbo`, dc:384): o PDF assinado mais recente do
 * documento com o carimbo das assinaturas eletrônicas feitas pelo BlueJus e o código de validação. A cópia é montada na
 * hora e nunca guardada: nada é gravado no banco, na trilha nem no armazenamento.
 *
 * O documento é lido pelo escritório logado (mesmo na Lixeira, como o Detalhe); o de outro escritório, o inexistente, o
 * que não tem PDF assinado guardado e o que perdeu o arquivo dão o mesmo {@see DocumentoNaoEncontrado}.
 */
final readonly class ObterCopiaComCarimboUseCase
{
    /** O nome do download no desenho: `d.nome + ' - com carimbo.pdf'` (dc:384). */
    public const SUFIXO_DO_NOME = ' - com carimbo.pdf';

    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private VersaoDoPdfRepository $versoes,
        private ArmazenamentoDeArquivos $armazenamento,
        private CarimboVisivel $carimbo,
    ) {
    }

    /** @throws DocumentoNaoEncontrado */
    public function executar(Tenant $tenant, int $id): CopiaComCarimbo
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $versao = $this->ultimaAssinada($documento) ?? throw new DocumentoNaoEncontrado();

        $resultado = $this->carimbo->carimbar(
            $this->conteudo($versao),
            CarimboVisivelAssinatura::dosSignatarios($documento->getSignatarios()),
            // Só documento enviado tem assinatura BlueJus, e todo enviado tem código: sem assinatura, o carimbo
            // recusa antes de usar o código.
            (string) $documento->getCodigo(),
        );

        return new CopiaComCarimbo($documento->getNome() . self::SUFIXO_DO_NOME, $resultado->pdf, self::motivo($resultado->recusa));
    }

    /**
     * Só o motivo da recusa, sem o " Use o original." que o {@see CarimboVisivel} acrescenta: quem mostra o aviso (o
     * botão do Detalhe, bloco 5A) acrescenta o final, como o `presCarimbo` do desenho (dc:384). O motivo em si vai como
     * o carimbo o escreveu.
     */
    private static function motivo(?string $recusa): ?string
    {
        if ($recusa === null || !str_ends_with($recusa, CarimboVisivel::USE_O_ORIGINAL)) {
            return $recusa;
        }

        return substr($recusa, 0, -\strlen(CarimboVisivel::USE_O_ORIGINAL));
    }

    /**
     * O `d.pres.atual || d.pres.chave` do desenho (dc:383-384, 390): o último PDF devolvido por um signatário gov.br ou
     * ICP ou, sem ele, o original que já chegou assinado. O PDF para assinar (E5-1) é gerado pelo BlueJus e fica de
     * fora: sozinho, ele não é um "documento já assinado".
     */
    private function ultimaAssinada(DocumentoParaAssinar $documento): ?VersaoDoPdf
    {
        $assinadas = array_filter(
            $this->versoes->doDocumento($documento),
            static fn (VersaoDoPdf $versao): bool => $versao->getOrigem() !== OrigemDaVersao::ParaAssinar,
        );

        return array_pop($assinadas);
    }

    /**
     * Os bytes da versão, até um byte além do teto do motor (20 MB): acima dele o carimbo recusa como estrutura não
     * reconhecida, sem a versão inteira em memória. Arquivo ausente é 404 (D10); pane do armazenamento sobe.
     */
    private function conteudo(VersaoDoPdf $versao): string
    {
        try {
            $recurso = $this->armazenamento->abrir(ChavesDeAssinaturaEletronica::versao($versao));
        } catch (ArquivoNaoEncontrado) {
            throw new DocumentoNaoEncontrado();
        }

        try {
            $conteudo = stream_get_contents($recurso, LimitesDaConferencia::PDF_MAXIMO_EM_BYTES + 1);
        } finally {
            fclose($recurso);
        }

        return $conteudo !== false ? $conteudo : throw new FalhaDeArmazenamento('Não foi possível ler a versão do PDF para o carimbo.');
    }
}
