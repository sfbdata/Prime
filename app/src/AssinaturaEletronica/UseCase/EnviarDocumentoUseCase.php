<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\ChaveDoSelo;
use App\AssinaturaEletronica\Service\CofreDaSelfie;
use App\AssinaturaEletronica\Service\ConferenciaDoEnvio;
use App\AssinaturaEletronica\Service\ConteudoCanonicoDoDocumento;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * "Gerar links e enviar" (contrato da etapa 2, seções 4.1 e 6): com a linha travada e relida, confere o documento e os
 * signatários, congela o conteúdo (hash canônico), grava o sha256 do token de cada signatário e registra o envio.
 * Depois do COMMIT, o convite por e-mail: com "em ordem" só ao primeiro; sem, a todos. O link do convite é remontado e
 * conferido com o `token_hash` recém-gravado; sem link, o convite não sai (`email_nao_enviado`).
 *
 * Etapa 3 (contrato da etapa 3, seção 2, item 4): depois do hash, o código de validação do selo. Colisão com o código
 * de outro documento (de qualquer escritório) tenta de novo com a tentativa seguinte, até {@see TENTATIVAS_DO_CODIGO};
 * o índice único da coluna garante. Sem o segredo do selo, o envio recusa antes da transação.
 *
 * Etapa 5 (E5-2): gov.br e ICP só com o validador de cadeia configurado.
 *
 * Trilha (desenho L374; decisão de 10/11/2026): o envio registra "Documento criado" logo antes de "Enviado para
 * assinatura". A gravação que o JS faz antes (`envio=1`) não registra "Rascunho salvo"; se a conferência do envio
 * recusar, este caso registra "Rascunho salvo" no lugar. Os "Rascunho salvo" do próprio usuário ficam: a trilha só
 * acrescenta.
 */
final readonly class EnviarDocumentoUseCase
{
    public const TENTATIVAS_DO_CODIGO = 5;

    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private ConteudoCanonicoDoDocumento $conteudo,
        private ChaveDaAssinatura $chave,
        private ChaveDoSelo $selo,
        private CofreDaSelfie $cofre,
        private TrilhaDoDocumento $trilha,
        private CorreioDaAssinatura $correio,
        private ApresentacaoDoDocumento $apresentacao,
        private ClockInterface $relogio,
        private ValidadorDeCadeia $cadeia,
    ) {
    }

    /**
     * @return array<string, mixed> o `Detalhe` do documento enviado
     *
     * @throws DocumentoNaoEncontrado
     * @throws AcaoRecusada
     */
    public function executar(User $ator, Tenant $tenant, int $id): array
    {
        $this->chave->exigirConfigurada();
        $this->selo->exigirConfigurada();
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $recusa = null;
        $this->documentos->emTransacao(function () use ($documento, $tenant, $ator, $agora, &$recusa): void {
            $recusa = $this->enviarNaTransacao($documento, $tenant, $ator, $agora);
        });
        if ($recusa instanceof AcaoRecusada) {
            throw $recusa;
        }

        $this->correio->entregar(array_map(
            fn (Signatario $s): CartaDaAssinatura => CartaDaAssinatura::convite($s, $this->apresentacao->linkDoSignatario($s), $ator),
            self::convidados($documento),
        ));

        return $this->apresentacao->detalhe($documento, $this->eventos->doDocumento($documento));
    }

    /**
     * Dentro da transação: trava e relê, confere e envia. A recusa da conferência volta (não é lançada) para o
     * "Rascunho salvo" dela ser gravado no COMMIT; o chamador a lança depois.
     */
    private function enviarNaTransacao(DocumentoParaAssinar $documento, Tenant $tenant, User $ator, \DateTimeImmutable $agora): ?AcaoRecusada
    {
        if (!$this->documentos->travarERecarregar($documento, $tenant)) {
            throw new DocumentoNaoEncontrado();
        }
        $this->documentos->recarregarSignatarios($documento);
        $documento->exigirEnviavel();
        try {
            ConferenciaDoEnvio::exigir($documento, $this->cofre->configurado(), $this->cadeia->configurado());
        } catch (AcaoRecusada $e) {
            // A gravação feita pelo envio não registrou "Rascunho salvo": registra aqui, para o documento novo não
            // ficar com a trilha vazia.
            $this->trilha->acrescentar($documento, TipoDeEvento::RascunhoSalvo, [], $ator);

            return $e;
        }

        $hash = $this->conteudo->hash($documento);
        $documento->enviar($hash, $agora);
        $this->gerarCodigo($documento, $hash);
        foreach ($documento->getSignatarios() as $signatario) {
            $signatario->definirTokenHash(ChaveDaAssinatura::hashDoToken($this->chave->token($signatario)));
        }
        $this->trilha->acrescentar($documento, TipoDeEvento::DocumentoCriado, [], $ator);   // desenho L374
        $this->trilha->acrescentar($documento, TipoDeEvento::DocumentoEnviado, ['hash' => $hash], $ator);

        return null;
    }

    /** O código de validação do selo: a primeira tentativa que nenhum documento usa. */
    private function gerarCodigo(DocumentoParaAssinar $documento, string $hash): void
    {
        $id = $documento->getId() ?? throw new \LogicException('Documento sem id não recebe código de validação.');

        for ($tentativa = 0; $tentativa < self::TENTATIVAS_DO_CODIGO; ++$tentativa) {
            $codigo = $this->selo->codigo($id, $hash, $tentativa);
            if (!$this->documentos->codigoEmUso($codigo)) {
                $documento->definirCodigo($codigo);

                return;
            }
        }

        throw new \RuntimeException(sprintf('Nenhum código de validação livre em %d tentativas.', self::TENTATIVAS_DO_CODIGO));
    }

    /** @return list<Signatario> */
    private static function convidados(DocumentoParaAssinar $documento): array
    {
        $signatarios = $documento->getSignatarios();

        return $documento->isEmOrdem() ? \array_slice($signatarios, 0, 1) : $signatarios;
    }
}
