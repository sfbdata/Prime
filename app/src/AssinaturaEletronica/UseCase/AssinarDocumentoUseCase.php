<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\DTO\DesfechoDaAcaoPublica;
use App\AssinaturaEletronica\DTO\EntradaDaAssinatura;
use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Exception\MuitasTentativas;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Assinar" da tela pública, nível Simples (contrato da etapa 2, seções 3, 4.1 e 5.2).
 *
 * As conferências do formulário vêm na ordem do desenho (H21: nome, CPF, CPF cadastrado, rubrica, código pedido,
 * código, aceite) e as que não dependem do banco recusam antes da transação, sem gastar tentativa. Com a linha travada
 * e relida: a vez do signatário, o bloqueio do teto diário, o código pedido, a validade e a conferência do código.
 * Código errado conta tentativa (5 queimam o código; 15 em 24 h bloqueiam até o fim da janela) e a recusa é lançada só
 * depois do COMMIT, para o erro ficar gravado. Código certo: a rubrica é gravada, a assinatura registrada e, se foi o
 * último, o documento concluído. Os e-mails (vez do próximo ou conclusão ao remetente) saem depois do COMMIT.
 */
final readonly class AssinarDocumentoUseCase
{
    public const METODO = 'simples';
    public const TETO_DE_ERROS = 15;

    public function __construct(
        private SignatarioDoLink $link,
        private EventoDaTrilhaRepository $eventos,
        private ChaveDaAssinatura $chave,
        private TrilhaDoDocumento $trilha,
        private TransacaoComArquivoNovo $transacao,
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
        $png = self::conferirFormulario($signatario, $entrada);
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $novas = [];

        $desfecho = $this->transacao->executar(
            function () use ($signatario, $entrada, $png, $evidencia, $agora, &$novas): DesfechoDaAcaoPublica {
                $this->link->travar($signatario);
                $recusa = $this->conferirCodigo($signatario, $entrada, $evidencia, $agora);
                if ($recusa !== null) {
                    return $recusa;
                }

                $rubrica = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novaRubrica($signatario, 'png'), FonteDeConteudo::deTexto($png))->chave;
                $novas[] = $rubrica;

                return DesfechoDaAcaoPublica::feito($this->registrar($signatario, $entrada, $png, $rubrica, $evidencia, $agora));
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
     * O que não depende do banco, na ordem do desenho. Devolve os bytes do PNG da rubrica.
     *
     * @throws AcaoRecusada
     */
    private static function conferirFormulario(Signatario $signatario, EntradaDaAssinatura $entrada): string
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
        if (!$entrada->aceite) {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_aceite');
        }

        return $png;
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
    private function registrar(Signatario $signatario, EntradaDaAssinatura $entrada, string $png, ChaveDeArquivo $rubrica, EvidenciaDaRequisicao $evidencia, \DateTimeImmutable $agora): array
    {
        $documento = $signatario->getDocumento();
        $concluiu = $documento->assinar($signatario, $entrada->nome, $entrada->cpf, $rubrica->nome, self::METODO, $evidencia->ip, $evidencia->navegador, $agora);
        $this->trilha->acrescentar($documento, TipoDeEvento::Assinado, [
            'metodo' => self::METODO,
            'modo' => $entrada->modo,
            'rubrica_sha256' => hash('sha256', $png),
            'aceite' => [
                'versao' => PaginaDoSignatario::VERSAO_DO_ACEITE,
                'sha256' => hash('sha256', $this->tradutor->trans(PaginaDoSignatario::chaveDoAceite())),
            ],
            // O CPF digitado foi conferido com o cadastrado; sem CPF cadastrado, não havia com o que conferir.
            'cpf_confere' => (string) $signatario->getCpf() !== '',
        ], null, $signatario, $evidencia);

        if ($concluiu) {
            $this->trilha->acrescentar($documento, TipoDeEvento::DocumentoConcluido, [], null);

            return [CartaDaAssinatura::conclusao($documento)];
        }

        $proximo = $documento->isEmOrdem() ? $documento->primeiroPendente() : null;

        return $proximo !== null ? [CartaDaAssinatura::vez($proximo, (string) $this->apresentacao->linkDoSignatario($proximo))] : [];
    }
}
