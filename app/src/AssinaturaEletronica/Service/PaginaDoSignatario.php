<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\EstadoDaPagina;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O JSON `Publico` da tela do signatário (contrato da etapa 2, seção 5.3), e nada além dele: sem nome do escritório,
 * usuário, pasta, categoria, prazo, situação, trilha, e-mail, papel ou nível; dos outros signatários só o rótulo, a
 * rubrica e a hora em que assinaram (`lugares`); sem ids internos.
 *
 * O conteúdo do documento (folha, PDF, logo, rubricas) só vai no estado `pronto`: nos estados de bloqueio a página
 * mostra só o cartão, com o nome do documento no cabeçalho; `invalido` não traz documento nenhum.
 *
 * A prévia do escritório usa a mesma forma, com as URLs internas (a sessão de quem tem o módulo abre os arquivos), o
 * documento inteiro em qualquer estado e o rascunho como `pronto` (o que o signatário verá depois do envio).
 *
 * Etapa 7: `reforcada` (no topo, fora do `eu`) diz à tela que este signatário manda selfie e localização; só é
 * verdadeiro no estado `pronto` e na prévia de quem é Reforçada.
 */
final readonly class PaginaDoSignatario
{
    public const VERSAO_DO_ACEITE = 1;

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $tradutor,
        private ApresentacaoDoDocumento $apresentacao,
    ) {
    }

    /** @return array<string, mixed> */
    public function invalido(): array
    {
        return array_merge($this->vazio(EstadoDaPagina::Invalido, false), ['documento' => null]);
    }

    /**
     * A página que o signatário vê pelo link.
     *
     * @return array<string, mixed>
     */
    public function publico(Signatario $signatario, #[\SensitiveParameter] string $token, \DateTimeImmutable $agora): array
    {
        $documento = $signatario->getDocumento();
        $estado = $documento->estadoPara($signatario, $agora);
        if ($estado !== EstadoDaPagina::Pronto) {
            return $this->bloqueio($signatario, $estado);
        }

        $url = fn (string $rota, array $extra = []): string => $this->urls->generate(
            'assinatura_eletronica_publica_' . $rota,
            array_merge(['token' => $token], $extra),
        );

        return array_merge($this->vazio($estado, false), [
            'documento' => $this->documento($documento, $this->logoPublica($documento, $url), $documento->temArquivo() ? $url('arquivo') : null),
            'lugares' => $this->lugares($documento, static fn (Signatario $s): string => $url('rubrica', ['ordem' => $s->getOrdem()])),
            'eu' => self::eu($signatario),
            'reforcada' => self::reforcada($signatario),
            'codigo' => [
                'enviado' => $signatario->temCodigo(),
                'validoAte' => self::iso($signatario->codigoValidoAte()),
                'bloqueadoAte' => $signatario->estaBloqueado($agora) ? self::iso($signatario->getBloqueadoAte()) : null,
            ],
        ]);
    }

    /**
     * A prévia do escritório ("Tela do signatário"): só leitura, com as URLs internas.
     *
     * @return array<string, mixed>
     */
    public function previa(Signatario $signatario, \DateTimeImmutable $agora): array
    {
        $documento = $signatario->getDocumento();
        $estado = $documento->getSituacao() === SituacaoDoDocumento::Rascunho && !$documento->estaNaLixeira()
            ? EstadoDaPagina::Pronto
            : $documento->estadoPara($signatario, $agora);
        $arquivo = $documento->temArquivo()
            ? $this->urls->generate('assinatura_eletronica_arquivo', ['id' => $documento->getId()])
            : null;

        return array_merge($this->vazio($estado, true), [
            'anterior' => $documento->anteriorPendente($signatario)?->getNome(),
            'documento' => $this->documento($documento, $this->apresentacao->urlDaLogo($documento), $arquivo),
            'lugares' => $this->lugares($documento, $this->apresentacao->urlDaRubrica(...)),
            'eu' => self::eu($signatario),
            'reforcada' => self::reforcada($signatario),
        ]);
    }

    /**
     * Estado de bloqueio: o cartão e o nome do documento no cabeçalho; nada do conteúdo. O documento leva só `nome` e
     * `hashEnviado` (a tela pública, no bloqueio, só lê o nome para o cabeçalho; nem a forma da folha sai).
     *
     * Etapa 3: para quem já assinou (o estado que a assinatura devolve), também o `codigo` de validação, que a tela
     * verde mostra na caixa acima de "Baixar PDF com selo" (dc:270).
     *
     * @return array<string, mixed>
     */
    private function bloqueio(Signatario $signatario, EstadoDaPagina $estado): array
    {
        $documento = $signatario->getDocumento();
        $cabecalho = ['nome' => $documento->getNome(), 'hashEnviado' => $documento->getHashEnviado()];
        if ($estado === EstadoDaPagina::JaAssinado || $estado === EstadoDaPagina::Concluido) {
            $cabecalho['codigo'] = $documento->getCodigo();
        }

        return array_merge($this->vazio($estado, false), [
            'anterior' => $estado === EstadoDaPagina::AguardandoAnterior ? $documento->anteriorPendente($signatario)?->getNome() : null,
            'documento' => $cabecalho,
        ]);
    }

    /** @return array<string, mixed> */
    private function vazio(EstadoDaPagina $estado, bool $previa): array
    {
        return [
            'estado' => $estado->value,
            'previa' => $previa,
            'anterior' => null,
            'documento' => null,
            'lugares' => [],
            'eu' => null,
            'reforcada' => false,
            'aceite' => [
                'versao' => self::VERSAO_DO_ACEITE,
                'texto' => $this->tradutor->trans(self::chaveDoAceite()),
            ],
            'codigo' => ['enviado' => false, 'validoAte' => null, 'bloqueadoAte' => null],
        ];
    }

    /** A chave do texto do aceite da versão vigente (o sha256 do texto vai na trilha). */
    public static function chaveDoAceite(): string
    {
        return 'assinatura_eletronica.aceite.v' . self::VERSAO_DO_ACEITE;
    }

    /** @return array<string, mixed> */
    private function documento(DocumentoParaAssinar $documento, ?string $logo, ?string $arquivo): array
    {
        return [
            'nome' => $documento->getNome(),
            'mensagem' => (string) $documento->getMensagem(),
            'blocos' => $documento->getBlocos(),
            'layout' => $documento->getLayout(),
            'lateral' => $documento->getLateral(),
            'folhaBranca' => $documento->isFolhaBranca(),
            'logo' => ['tipo' => $documento->getLogoTipo()->value, 'url' => $logo],
            'arquivo' => $arquivo !== null ? ['url' => $arquivo, 'paginas' => $documento->getArquivoEnviadoPaginas()] : null,
            'hashEnviado' => $documento->getHashEnviado(),
        ];
    }

    /**
     * A logo pela rota pública: a BlueJus é arquivo estático; a do escritório e a própria passam pelo token.
     *
     * @param \Closure(string): string $url
     */
    private function logoPublica(DocumentoParaAssinar $documento, \Closure $url): ?string
    {
        return match ($documento->getLogoTipo()) {
            TipoDeLogo::Bluejus => $this->apresentacao->urlDaLogoBluejus(),
            TipoDeLogo::Escritorio => $this->apresentacao->urlDaLogoDoEscritorio($documento->getTenant()) !== null ? $url('logo') : null,
            TipoDeLogo::Propria => $documento->getLogoChave() !== null ? $url('logo') : null,
        };
    }

    /**
     * Os lugares de assinatura, na ordem dos signatários: o rótulo do bloco de assinatura (ou o nome cadastrado), a
     * rubrica de quem já assinou e a hora.
     *
     * @param \Closure(Signatario): ?string $rubrica
     *
     * @return list<array{rotulo: string, rubrica: ?string, assinadoEm: ?string}>
     */
    private function lugares(DocumentoParaAssinar $documento, \Closure $rubrica): array
    {
        $rotulos = [];
        foreach ($documento->getBlocos() as $bloco) {
            if (($bloco['k'] ?? null) === 'a' && \is_int($bloco['i'] ?? null) && trim((string) ($bloco['r'] ?? '')) !== '') {
                $rotulos[$bloco['i']] ??= trim((string) $bloco['r']);
            }
        }

        return array_map(static fn (Signatario $s): array => [
            'rotulo' => $rotulos[$s->getOrdem()] ?? $s->getNome(),
            'rubrica' => $s->getRubricaChave() !== null ? $rubrica($s) : null,
            'assinadoEm' => self::iso($s->getAssinadoEm()),
        ], $documento->getSignatarios());
    }

    private static function reforcada(Signatario $signatario): bool
    {
        return $signatario->getNivel() === NivelDeAssinatura::Reforcada;
    }

    /** @return array{nome: string, cpf: ?string, posicao: int} */
    private static function eu(Signatario $signatario): array
    {
        return ['nome' => $signatario->getNome(), 'cpf' => $signatario->getCpf(), 'posicao' => $signatario->getOrdem()];
    }

    private static function iso(?\DateTimeImmutable $data): ?string
    {
        return $data?->format(\DATE_ATOM);
    }
}
