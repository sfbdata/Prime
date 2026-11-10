<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\LocalizacaoDoSignatario;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Pdf\CartaoDoManifesto;
use App\AssinaturaEletronica\Pdf\ManifestoDeAssinaturas;
use App\AssinaturaEletronica\Pdf\SituacaoNoManifesto;
use App\AssinaturaEletronica\Pdf\TextosDaFolha;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Os textos do manifesto de assinaturas (A2, dc:417-420) a partir do documento e da trilha, no bloco `manifesto:` do
 * catálogo da tela. Como o desenho: o nome digitado ao assinar (senão o cadastrado); o CPF digitado (senão o
 * cadastrado) mascarado em `***.123.456-**`; o e-mail com os dois primeiros caracteres e o domínio; "Tipo: lei" com o
 * método de quem assinou; a data da assinatura ou da recusa (com o motivo); a trilha inteira do mais antigo ao mais
 * novo, cada texto cortado em 96 caracteres. Dado ausente vira "não informado" (E3-16). A selfie não entra; as
 * coordenadas da Reforçada vão no texto do método (E3-13).
 */
final readonly class ManifestoDoDocumento
{
    private const CHAVE = 'assinatura_eletronica_tela.js.manifesto.';
    private const METODO_SEM_LOCAL = 'assinatura_eletronica_tela.js.reforcada.metodo_sem_local';
    private const TAMANHO_DO_EVENTO = 96;

    public function __construct(
        private TranslatorInterface $tradutor,
        private EventoDaTrilhaRepository $trilha,
    ) {
    }

    public function de(DocumentoParaAssinar $documento): ManifestoDeAssinaturas
    {
        $eventos = array_reverse($this->trilha->doDocumento($documento));
        $cartoes = [];
        foreach ($documento->getSignatarios() as $indice => $signatario) {
            $cartoes[] = $this->cartao($indice, $signatario, $eventos);
        }

        return new ManifestoDeAssinaturas(
            titulo: $this->t('titulo'),
            documento: $documento->getNome(),
            cartoes: $cartoes,
            tituloDaTrilha: $this->t('trilha'),
            eventos: array_map($this->evento(...), $eventos),
            notaLegal: $this->t('nota_legal'),
        );
    }

    /** @param list<EventoDaTrilha> $eventos */
    private function cartao(int $indice, Signatario $signatario, array $eventos): CartaoDoManifesto
    {
        $situacao = match ($signatario->getSituacao()) {
            SituacaoDoSignatario::Assinado => SituacaoNoManifesto::Assinado,
            SituacaoDoSignatario::Recusado => SituacaoNoManifesto::Recusado,
            SituacaoDoSignatario::Pendente => SituacaoNoManifesto::Pendente,
        };

        return new CartaoDoManifesto(
            indice: $indice,
            nome: self::nome($signatario),
            dados: $this->t('linha_dados', ['papel' => $signatario->getPapel(), 'cpf' => $this->cpf($signatario), 'email' => $this->email($signatario)]),
            tipo: $this->tipo($signatario),
            situacao: $situacao,
            textoDaSituacao: $this->textoDaSituacao($signatario, $situacao, $eventos),
            nota: $this->t('nota_ip'),
        );
    }

    private static function nome(Signatario $signatario): string
    {
        $digitado = trim((string) $signatario->getNomeDigitado());

        return $digitado !== '' ? $digitado : $signatario->getNome();
    }

    /** `***.` + dígitos 4 a 6 + `.` + dígitos 7 a 9 + `-**`, com 11 dígitos; senão "não informado". */
    private function cpf(Signatario $signatario): string
    {
        $cpf = (string) preg_replace('/\D/', '', (string) ($signatario->getCpfDigitado() ?: $signatario->getCpf()));

        return \strlen($cpf) === 11
            ? '***.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-**'
            : $this->t('nao_informado');
    }

    /** `replace(/^(.{2}).*(@.*)$/, '$1***$2')` do desenho; sem e-mail, "não informado". */
    private function email(Signatario $signatario): string
    {
        $email = (string) $signatario->getEmail();

        return $email === '' ? $this->t('nao_informado') : (string) preg_replace('/^(.{2}).*(@.*)$/u', '$1***$2', $email);
    }

    private function tipo(Signatario $signatario): string
    {
        $lei = $this->t('lei.' . $signatario->getNivel()->value);
        $metodo = $this->metodo($signatario);

        return $metodo === null
            ? $this->t('tipo', ['lei' => $lei])
            : $this->t('tipo_com_metodo', ['lei' => $lei, 'metodo' => $metodo]);
    }

    /** O rótulo do método como o detalhe da tela (`metodoRotulo` do assinatura.js); valor desconhecido sai como veio. */
    private function metodo(Signatario $signatario): ?string
    {
        $metodo = (string) $signatario->getMetodo();
        $latitude = $signatario->getLatitude();
        $longitude = $signatario->getLongitude();

        return match (true) {
            $metodo === '' => null,
            $metodo === 'simples' => $this->t('metodo_simples'),
            $metodo !== 'reforcada' => $metodo,
            $latitude !== null && $longitude !== null => $this->t('metodo_reforcada', ['local' => LocalizacaoDoSignatario::textoDe($latitude, $longitude)]),
            default => $this->tradutor->trans(self::METODO_SEM_LOCAL),
        };
    }

    /** @param list<EventoDaTrilha> $eventos */
    private function textoDaSituacao(Signatario $signatario, SituacaoNoManifesto $situacao, array $eventos): string
    {
        if ($situacao === SituacaoNoManifesto::Pendente) {
            return $this->t('pendente');
        }
        if ($situacao === SituacaoNoManifesto::Assinado) {
            return $this->t('assinado_em', ['data' => $this->data($signatario->getAssinadoEm())]);
        }

        $data = $this->data(self::recusa($signatario, $eventos)?->getCriadoEm());
        $motivo = trim((string) $signatario->getMotivoRecusa());

        return $motivo === ''
            ? $this->t('recusou_em', ['data' => $data])
            : $this->t('recusou_em_motivo', ['data' => $data, 'motivo' => $motivo]);
    }

    /**
     * O evento da recusa deste signatário: a data da recusa só fica na trilha.
     *
     * @param list<EventoDaTrilha> $eventos
     */
    private static function recusa(Signatario $signatario, array $eventos): ?EventoDaTrilha
    {
        foreach (array_reverse($eventos) as $evento) {
            $dele = $evento->getSignatario() === $signatario
                || ($signatario->getId() !== null && $evento->getSignatarioRef() === $signatario->getId());
            if ($dele && $evento->getTipo() === TipoDeEvento::Recusado) {
                return $evento;
            }
        }

        return null;
    }

    private function data(?\DateTimeImmutable $quando): string
    {
        return $quando === null ? $this->t('nao_informado') : TextosDaFolha::dataHora($quando);
    }

    private function evento(EventoDaTrilha $evento): string
    {
        $texto = $this->tradutor->trans($evento->getTipo()->chaveDoTexto(), self::parametrosDoTexto($evento));

        return $this->t('evento', ['data' => TextosDaFolha::dataHora($evento->getCriadoEm()), 'texto' => mb_substr($texto, 0, self::TAMANHO_DO_EVENTO)]);
    }

    /**
     * Os parâmetros do texto do evento, os mesmos do detalhe da tela (`ApresentacaoDoDocumento::parametrosDoTexto`, que
     * é privado): os valores simples de `dados`, o `{nome}` do signatário ou do usuário e o `{local}` da Reforçada.
     *
     * @return array<string, scalar>
     */
    private static function parametrosDoTexto(EventoDaTrilha $evento): array
    {
        $parametros = array_filter($evento->getDados(), is_scalar(...));
        if (!isset($parametros['nome'])) {
            $nome = $evento->getSignatario()?->getNome() ?? $evento->getUsuario()?->getFullName();
            if ($nome !== null) {
                $parametros['nome'] = $nome;
            }
        }
        if (\is_string($parametros['latitude'] ?? null) && \is_string($parametros['longitude'] ?? null)) {
            $parametros['local'] = LocalizacaoDoSignatario::textoDe($parametros['latitude'], $parametros['longitude']);
        }

        return $parametros;
    }

    /** @param array<string, scalar> $parametros */
    private function t(string $chave, array $parametros = []): string
    {
        return $this->tradutor->trans(self::CHAVE . $chave, $parametros);
    }
}
