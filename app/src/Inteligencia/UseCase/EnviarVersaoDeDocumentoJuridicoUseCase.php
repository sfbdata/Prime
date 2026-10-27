<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Inteligencia\Armazenamento\ChavesDeInteligencia;
use App\Inteligencia\Entity\VersaoDeDocumentoJuridico;
use App\Inteligencia\Enum\DocumentoJuridico;
use App\Inteligencia\Exception\DocumentoJuridicoRecusadoException;
use App\Inteligencia\Exception\QuestaoJuridicaNaoEncontradaException;
use App\Inteligencia\Juridico\CatalogoDeQuestoesJuridicas;
use App\Inteligencia\Repository\VersaoDeDocumentoJuridicoRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Http\FonteDeUploadHttp;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O Master envia a versão ajustada de um documento jurídico (Política de Privacidade, Termos de Uso, rascunho da
 * política da IA ou texto do aceite), a partir de uma questão ou solto. O sistema guarda o arquivo (chave global,
 * nome cunhado pelo storage) e o histórico: quem enviou, quando, o nome original, o documento e a questão.
 *
 * Aceita .docx, .pdf, .md, .txt e .odt até 20 MB. A extensão do nome tem de estar na lista E o tipo lido do
 * CONTEÚDO tem de bater com ela (um .pdf que é HTML por dentro é recusado). Recusa = {@see DocumentoJuridicoRecusadoException}
 * (422), antes de gravar qualquer coisa. Enviar de novo cria outra versão; nenhuma é apagada.
 *
 * O arquivo é gravado antes do COMMIT; se a transação comprovadamente não confirmar, ele sai
 * ({@see TransacaoComArquivoNovo}). A auditoria fica no escopo de plataforma (sem escritório).
 */
final class EnviarVersaoDeDocumentoJuridicoUseCase
{
    public const TAMANHO_MAXIMO = 20 * 1024 * 1024;

    /** Extensão aceita => tipos que o conteúdo pode ter (o que o libmagic do servidor responde). */
    public const TIPOS_POR_EXTENSAO = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'txt' => ['text/plain'],
    ];

    public function __construct(
        private readonly CatalogoDeQuestoesJuridicas $catalogo,
        private readonly VersaoDeDocumentoJuridicoRepository $versoes,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(DocumentoJuridico $documento, ?string $questao, UploadedFile $arquivo, User $master): VersaoDeDocumentoJuridico
    {
        GuardaDoMasterDaIa::exigir($master);
        $this->exigirQuestaoQuePedeODocumento($questao, $documento);
        [$extensao, $mime] = $this->exigirArquivoAceito($arquivo);
        $nomeOriginal = self::nomeOriginal($arquivo);

        $armazenado = FonteDeUploadHttp::de($arquivo)->gravarEm($this->armazenamento, ChavesDeInteligencia::novaVersaoDeDocumentoJuridico($extensao));
        $versao = new VersaoDeDocumentoJuridico($documento, $questao, $nomeOriginal, $armazenado->chave->nome, $mime, $armazenado->tamanhoBytes, $master, $this->relogio->now());
        $this->versoes->adicionar($versao);
        // Documento da plataforma: a linha de auditoria fica sem escritório.
        $this->escopoDePlataforma->executar(fn () => $this->transacao->confirmar(static fn (): array => [$armazenado->chave], 'inteligencia_documento_juridico'));

        return $versao;
    }

    private function exigirQuestaoQuePedeODocumento(?string $chave, DocumentoJuridico $documento): void
    {
        if ($chave === null) {
            return;
        }
        $questao = $this->catalogo->porChave($chave) ?? throw new QuestaoJuridicaNaoEncontradaException($chave);
        if (!$questao->pedeODocumento($documento)) {
            throw new DocumentoJuridicoRecusadoException('Esta questão não pede este documento.');
        }
    }

    /** @return array{string, string} a extensão aceita e o tipo lido do conteúdo */
    private function exigirArquivoAceito(UploadedFile $arquivo): array
    {
        if (!$arquivo->isValid()) {
            throw new DocumentoJuridicoRecusadoException('O envio do arquivo falhou. Tente de novo (até 20 MB).');
        }
        $tamanho = (int) $arquivo->getSize();
        if ($tamanho > self::TAMANHO_MAXIMO) {
            throw new DocumentoJuridicoRecusadoException('O arquivo passa de 20 MB.');
        }
        if ($tamanho === 0) {
            throw new DocumentoJuridicoRecusadoException('O arquivo está vazio.');
        }
        $extensao = strtolower(pathinfo($arquivo->getClientOriginalName(), \PATHINFO_EXTENSION));
        $mime = strtolower((string) $arquivo->getMimeType());
        if (!in_array($mime, self::TIPOS_POR_EXTENSAO[$extensao] ?? [], true)) {
            throw new DocumentoJuridicoRecusadoException('Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.');
        }

        return [$extensao, $mime];
    }

    /** O nome que o Master deu ao arquivo, sem caminho nem caractere de controle, com até 255 caracteres. */
    private static function nomeOriginal(UploadedFile $arquivo): string
    {
        $nome = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $arquivo->getClientOriginalName())));

        return mb_substr(trim($nome) !== '' ? trim($nome) : 'documento', 0, 255);
    }
}
