<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\DTO\DesfechoDaAcaoPublica;
use App\AssinaturaEletronica\DTO\EntradaDaAssinatura;
use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\DTO\ReforcoDaAssinatura;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Exception\MuitasTentativas;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\CofreDaSelfie;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Assinar" da tela pública, níveis Simples (contrato da etapa 2, seções 3, 4.1 e 5.2) e Reforçada (etapa 7).
 *
 * As conferências do formulário vêm na ordem do desenho (H21: nome, CPF, CPF cadastrado, rubrica, código pedido,
 * código, aceite) e as que não dependem do banco recusam antes da transação, sem gastar tentativa. Com a linha travada
 * e relida: a vez do signatário, o bloqueio do teto diário, o código pedido, a validade e a conferência do código.
 * Código errado conta tentativa (5 queimam o código; 15 em 24 h bloqueiam até o fim da janela) e a recusa é lançada só
 * depois do COMMIT, para o erro ficar gravado. Código certo: a rubrica é gravada, a assinatura registrada e, se foi o
 * último, o documento concluído. Os e-mails (vez do próximo, com o Reply-To de quem enviou o documento, ou conclusão ao
 * remetente) saem depois do COMMIT; a vez sem link (segredo trocado depois do envio) não sai (`email_nao_enviado`).
 *
 * Reforçada (contrato da etapa 7, seções 4.2 e 9): sem a chave da selfie, recusa antes de tudo; a selfie (regravada em
 * JPEG) e a localização são conferidas depois do formato do código e antes do aceite; com o código certo, a selfie é
 * cifrada e gravada junto com a rubrica (as duas na lista de arquivos novos), a localização vai para as colunas e as
 * evidências (sha256 da selfie, versão da chave dela, latitude, longitude, precisão) para a trilha. Quem é Simples não
 * tem nada disso, mesmo que o corpo traga selfie ou localização.
 */
final readonly class AssinarDocumentoUseCase
{
    public const METODO = 'simples';
    public const TETO_DE_ERROS = 15;
    /** O arquivo da selfie no disco é o cifrado pelo cofre, nunca a imagem. */
    public const EXTENSAO_DA_SELFIE = 'cifrado';

    public function __construct(
        private SignatarioDoLink $link,
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private ChaveDaAssinatura $chave,
        private CofreDaSelfie $cofre,
        private TrilhaDoDocumento $trilha,
        private ArmazenamentoDeArquivos $armazenamento,
        private CorreioDaAssinatura $correio,
        private ApresentacaoDoDocumento $apresentacao,
        private PaginaDoSignatario $pagina,
        private TranslatorInterface $tradutor,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @return array<string, mixed> o `Publico` depois da assinatura
     *
     * @throws LinkInvalido
     * @throws AcaoRecusada
     * @throws MuitasTentativas
     */
    public function executar(#[\SensitiveParameter] string $token, EntradaDaAssinatura $entrada, EvidenciaDaRequisicao $evidencia): array
    {
        $signatario = $this->link->localizar($token);
        $this->chave->exigirConfigurada();
        if ($signatario->getNivel() === NivelDeAssinatura::Reforcada) {
            $this->cofre->exigirConfigurado();
        }
        [$png, $reforco] = self::conferirFormulario($signatario, $entrada);
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $novas = [];

        $desfecho = $this->documentos->emTransacaoComArquivoNovo(
            function () use ($signatario, $entrada, $png, $reforco, $evidencia, $agora, &$novas): DesfechoDaAcaoPublica {
                $this->link->travar($signatario);
                $recusa = $this->conferirCodigo($signatario, $entrada, $evidencia, $agora);
                if ($recusa !== null) {
                    return $recusa;
                }
                $rubrica = $this->gravarArquivos($signatario, $png, $reforco, $novas);

                return DesfechoDaAcaoPublica::feito($this->registrar($signatario, $entrada, $png, $rubrica, $reforco, $evidencia, $agora));
            },
            static function () use (&$novas): array {
                /** @var list<ChaveDeArquivo> $novas */
                return $novas;
            },
            'assinatura_eletronica_assinar',
        );
        $desfecho->lancarSeRecusado();
        $this->correio->entregar($desfecho->cartas);

        return $this->pagina->publico($signatario, $token, $agora);
    }

    /**
     * O que não depende do banco, na ordem do desenho. Devolve os bytes do PNG da rubrica e, na Reforçada, a selfie
     * regravada e a localização.
     *
     * @return array{string, ?ReforcoDaAssinatura}
     *
     * @throws AcaoRecusada
     */
    private static function conferirFormulario(Signatario $signatario, EntradaDaAssinatura $entrada): array
    {
        $entrada->exigirNome();
        $entrada->exigirCpf();
        $cadastrado = preg_replace('/\D/', '', (string) $signatario->getCpf());
        if ($cadastrado !== '' && $cadastrado !== $entrada->cpf) {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_cpf_cadastro');
        }
        $png = $entrada->rubricaPng();
        if ($entrada->codigo === '') {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_pedir_codigo');
        }
        if (!$entrada->codigoBemFormado()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.codigo_errado');
        }
        $reforco = $signatario->getNivel() === NivelDeAssinatura::Reforcada
            ? new ReforcoDaAssinatura($entrada->selfie(), $entrada->localizacao())
            : null;
        if (!$entrada->aceite) {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_aceite');
        }

        return [$png, $reforco];
    }

    /**
     * Com o código conferido: a rubrica e, na Reforçada, a selfie cifrada, cada uma posta na lista de arquivos novos
     * logo depois de gravada (falhou antes do COMMIT, sai do disco). As evidências da Reforçada vão para o signatário
     * antes da transição do documento. Devolve a chave da rubrica.
     *
     * @param list<ChaveDeArquivo> $novas
     */
    private function gravarArquivos(Signatario $signatario, string $png, ?ReforcoDaAssinatura $reforco, array &$novas): ChaveDeArquivo
    {
        $rubrica = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novaRubrica($signatario, 'png'), FonteDeConteudo::deTexto($png))->chave;
        $novas[] = $rubrica;
        if ($reforco !== null) {
            $cifrado = $this->cofre->cifrar($reforco->selfie->jpeg, $signatario);
            $selfie = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novaSelfie($signatario, self::EXTENSAO_DA_SELFIE), FonteDeConteudo::deTexto($cifrado))->chave;
            $novas[] = $selfie;
            $signatario->registrarReforco($selfie->nome, $reforco->selfie->sha256, CofreDaSelfie::VERSAO, $reforco->localizacao);
        }

        return $rubrica;
    }

    /**
     * Com a linha travada: a vez, o bloqueio, o código pedido e vigente, e a conferência. Nulo quando o código confere.
     *
     * @throws AcaoRecusada fora da vez, sem código ou com o código vencido (nada a gravar)
     */
    private function conferirCodigo(Signatario $signatario, EntradaDaAssinatura $entrada, EvidenciaDaRequisicao $evidencia, \DateTimeImmutable $agora): ?DesfechoDaAcaoPublica
    {
        $signatario->getDocumento()->exigirVez($signatario, $agora);
        if ($signatario->estaBloqueado($agora)) {
            return DesfechoDaAcaoPublica::recusado(new MuitasTentativas());
        }
        if (!$signatario->temCodigo()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_pedir_codigo');
        }
        if ($signatario->codigoVencido($agora)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.codigo_vencido');
        }
        if ($this->chave->codigoConfere($signatario, $entrada->codigo)) {
            return null;
        }

        return $this->codigoErrado($signatario, $evidencia, $agora);
    }

    /** Conta a tentativa e grava o erro; a recusa sai depois do COMMIT. */
    private function codigoErrado(Signatario $signatario, EvidenciaDaRequisicao $evidencia, \DateTimeImmutable $agora): DesfechoDaAcaoPublica
    {
        $queimou = $signatario->errarCodigo();
        $desde = $agora->modify('-' . SolicitarCodigoUseCase::JANELA);
        [$anteriores, $maisAntigo] = $this->eventos->contarDoSignatarioDesde($signatario, TipoDeEvento::CodigoErrado, $desde);
        $this->trilha->acrescentar(
            $signatario->getDocumento(),
            TipoDeEvento::CodigoErrado,
            ['tentativa' => $signatario->getTentativas()],
            null,
            $signatario,
            $evidencia,
        );

        if ($anteriores + 1 >= self::TETO_DE_ERROS) {
            $signatario->bloquearAte(($maisAntigo ?? $agora)->modify('+' . SolicitarCodigoUseCase::JANELA));

            return DesfechoDaAcaoPublica::recusado(new MuitasTentativas());
        }

        return DesfechoDaAcaoPublica::recusado(new AcaoRecusada($queimou ? 'assinatura_eletronica.erro.codigo_queimado' : 'assinatura_eletronica.erro.codigo_errado'));
    }

    /**
     * A assinatura, a evidência e, se foi a última, a conclusão. Devolve as cartas a mandar depois do COMMIT.
     *
     * @return list<CartaDaAssinatura>
     */
    private function registrar(Signatario $signatario, EntradaDaAssinatura $entrada, string $png, ChaveDeArquivo $rubrica, ?ReforcoDaAssinatura $reforco, EvidenciaDaRequisicao $evidencia, \DateTimeImmutable $agora): array
    {
        $documento = $signatario->getDocumento();
        $metodo = $reforco !== null ? NivelDeAssinatura::Reforcada->value : self::METODO;
        $concluiu = $documento->assinar($signatario, $entrada->nome, $entrada->cpf, $rubrica->nome, $metodo, $evidencia->ip, $evidencia->navegador, $agora);
        $this->trilha->acrescentar($documento, TipoDeEvento::Assinado, $this->evidencias($signatario, $entrada, $png, $reforco), null, $signatario, $evidencia);

        if ($concluiu) {
            $this->trilha->acrescentar($documento, TipoDeEvento::DocumentoConcluido, [], null);

            return [CartaDaAssinatura::conclusao($documento)];
        }

        $proximo = $documento->isEmOrdem() ? $documento->primeiroPendente() : null;

        return $proximo !== null
            ? [CartaDaAssinatura::vez($proximo, $this->apresentacao->linkDoSignatario($proximo), $this->eventos->quemEnviou($documento))]
            : [];
    }

    /**
     * Os `dados` do evento `assinado`: os da etapa 2 e, na Reforçada, o sha256 da selfie, a versão da chave dela e a
     * localização (a imagem, o nonce e a chave nunca entram).
     *
     * @return array<string, mixed>
     */
    private function evidencias(Signatario $signatario, EntradaDaAssinatura $entrada, string $png, ?ReforcoDaAssinatura $reforco): array
    {
        $dados = [
            'metodo' => $reforco !== null ? NivelDeAssinatura::Reforcada->value : self::METODO,
            'modo' => $entrada->modo,
            'rubrica_sha256' => hash('sha256', $png),
            'aceite' => [
                'versao' => PaginaDoSignatario::VERSAO_DO_ACEITE,
                'sha256' => hash('sha256', $this->tradutor->trans(PaginaDoSignatario::chaveDoAceite())),
            ],
            // O CPF digitado foi conferido com o cadastrado; sem CPF cadastrado, não havia com o que conferir.
            'cpf_confere' => (string) $signatario->getCpf() !== '',
        ];
        if ($reforco === null) {
            return $dados;
        }

        return array_merge($dados, [
            'selfie_sha256' => $reforco->selfie->sha256,
            'selfie_chave' => CofreDaSelfie::VERSAO,
            'latitude' => $reforco->localizacao->latitude,
            'longitude' => $reforco->localizacao->longitude,
            'precisao' => $reforco->localizacao->precisao,
        ]);
    }
}
