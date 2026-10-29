<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\Armazenamento\ChavesDeChatIa;
use App\ChatIa\DTO\AnexoOutput;
use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\Exception\SemAceiteNoChatException;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\ExtratorDeTextoDocx;
use App\ChatIa\Service\MontadorDoPedidoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Psr\Clock\ClockInterface;

/**
 * Anexa um arquivo (PDF, imagem ou DOCX) à conversa, ainda SEM pergunta. Regras (spec chat-pagina-inteira, decisão 6):
 *
 * - o aceite do escritório precisa cobrir a leitura de documentos ({@see RegraDaLeituraDeDocumentos::aceiteCobre}), 409;
 * - o tipo vem do CONTEÚDO, nunca da extensão nem do tipo declarado: PDF e imagem pela assinatura, DOCX porque o
 *   {@see ExtratorDeTextoDocx} consegue abri-lo e ler o texto (também valida zip bomb);
 * - limites: PDF 10 MB, imagem 3 MB, DOCX 10 MB; no máximo 3 anexos soltos por conversa e 20 MB somados;
 * - o anexo é de quem o enviou e só serve na conversa para a qual foi enviado.
 *
 * O arquivo vai para o armazenamento por chave; o registro e o arquivo são confirmados juntos (falha no COMMIT apaga o
 * arquivo novo).
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 * @throws \App\ChatIa\Exception\ConversaDeChatNaoEncontradaException
 * @throws SemAceiteNoChatException
 * @throws AnexoDoChatInvalidoException
 */
final class AnexarArquivoAoChatUseCase
{
    private const EXTENSOES = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        MontadorDoPedidoDoChat::TIPO_DOCX => 'docx',
    ];

    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly RegraDaLeituraDeDocumentos $regra,
        private readonly AnexoDeChatRepository $anexos,
        private readonly ExtratorDeTextoDocx $docx,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $conversaId, string $nomeOriginal, string $bytes): AnexoOutput
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $conversa = $this->acesso->conversaDoDono($conversaId, $eu, $tenant);
        if (!$this->regra->aceiteCobre($tenant)) {
            throw new SemAceiteNoChatException('O escritório precisa renovar o aceite da I.A (leitura de documentos) para anexar arquivos.');
        }

        $tipo = $this->tipoDe($bytes);
        $this->conferirLimites($this->anexos->soltosDaConversa($conversa, $tenant, $eu), $tipo, \strlen($bytes));

        $armazenado = $this->armazenamento->gravar(ChavesDeChatIa::novoAnexo($conversa, self::EXTENSOES[$tipo]), FonteDeConteudo::deTexto($bytes));
        $anexo = new AnexoDeChat($tenant, $eu, $conversa, $armazenado->chave->nome, self::nomeLimpo($nomeOriginal, self::EXTENSOES[$tipo]), $tipo, $armazenado->tamanhoBytes, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        $this->anexos->salvar($anexo);
        $this->transacao->confirmar(static fn (): array => [$armazenado->chave], 'chat_ia_anexo_enviar');

        return AnexoOutput::de($anexo);
    }

    /** O tipo pelo conteúdo; o que não é PDF, imagem aceita nem DOCX legível é recusado. */
    private function tipoDe(string $bytes): string
    {
        if ($bytes === '') {
            throw new AnexoDoChatInvalidoException('O arquivo está vazio.');
        }
        $tipo = RegraDaLeituraDeDocumentos::tipoPeloConteudo($bytes);
        if ($tipo === null && str_starts_with($bytes, "PK\x03\x04")) {
            $this->docx->extrair($bytes);
            $tipo = MontadorDoPedidoDoChat::TIPO_DOCX;
        }

        return $tipo ?? throw new AnexoDoChatInvalidoException('Só PDF, imagem (PNG, JPEG, GIF ou WEBP) e DOCX podem ser anexados.');
    }

    /**
     * @param list<AnexoDeChat> $soltos
     */
    private function conferirLimites(array $soltos, string $tipo, int $tamanho): void
    {
        $teto = str_starts_with($tipo, 'image/') ? RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_DA_IMAGEM : RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_DO_PDF;
        if ($tamanho > $teto) {
            throw new AnexoDoChatInvalidoException(sprintf('O arquivo passa de %d MB.', intdiv($teto, RegraDaLeituraDeDocumentos::MEGABYTE)));
        }
        if (\count($soltos) >= RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE) {
            throw new AnexoDoChatInvalidoException(sprintf('Anexe no máximo %d arquivos por mensagem.', RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE));
        }
        $total = $tamanho + array_sum(array_map(static fn (AnexoDeChat $a): int => $a->getTamanho(), $soltos));
        if ($total > RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_TOTAL) {
            throw new AnexoDoChatInvalidoException(sprintf('Os arquivos da mensagem passam de %d MB juntos.', intdiv(RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_TOTAL, RegraDaLeituraDeDocumentos::MEGABYTE)));
        }
    }

    /** O nome que a pessoa vê: sem pasta, sem caractere de controle, até 255 caracteres. */
    private static function nomeLimpo(string $nome, string $extensao): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $nome));
        $nome = mb_substr($nome, 0, 255);

        return $nome === '' ? 'arquivo.' . $extensao : $nome;
    }
}
