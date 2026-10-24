<?php

declare(strict_types=1);

namespace App\Auditoria\Service;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use App\Pasta\UseCase\RenomearClienteDaPastaUseCase;
use App\Tarefa\Exception\MetaJaSeguiuAdianteException;
use App\Tarefa\Exception\PassagemRecusadaException;
use App\Tarefa\UseCase\DesfazerReaberturaDaMetaUseCase;
use App\Tarefa\UseCase\MontarEtapaDaMetaUseCase;
use App\Tarefa\UseCase\ReabrirMetaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Desfazer é editar, também no ESTADO: além de quem pode (`GuardaDaTelaNoDesfazer`), o desfazer só grava o que a tela
 * deixaria gravar no estado atual do registro (spec `docs/specs/seguranca-d-desfazer-estado.md`).
 *
 * As regras são CHAMADAS, nunca copiadas: a reabertura (`ReabrirMetaUseCase::seguiuAdiante`), a data de conclusão
 * (`DesfazerReaberturaDaMetaUseCase::motivoParaRecusarAData`), a etapa (`MontarEtapaDaMetaUseCase::podeMexerNaEtapa`),
 * a lápide (`Pasta::estaExcluida`, com a mensagem do `PastaSomenteLeituraListener`) e o nome montado pela carteira
 * (`RenomearClienteDaPastaUseCase::nomeEMontadoPelaCarteira`). A recusa é uma mensagem, não um 403: quem desfaz tem a
 * guarda; é o estado que não deixa.
 */
final class EstadoDaTelaNoDesfazer
{
    public const MENSAGEM_REGISTRO_MUDOU = 'O registro mudou depois desta alteração. Nada foi alterado.';
    public const MENSAGEM_SO_PELA_TELA_DA_PASTA = 'Esta alteração só pode ser desfeita pela tela da pasta.';
    public const MENSAGEM_META_INCOERENTE = 'A meta ficaria concluída sem data de conclusão, ou aberta com data de conclusão. Nada foi alterado.';

    /** Campos da pasta com efeitos (registro, andamento) que o setter não faz: só a tela da pasta os muda. */
    private const CAMPOS_SO_PELA_TELA_DA_PASTA = ['situacao', 'situacaoContrato'];

    private const CAMPOS_DA_ETAPA = ['etapa', 'etapasSaltadas'];

    /**
     * O `AuditLogSubscriber::normalizeValue()` corta texto acima de 500 caracteres e acrescenta `…`; o valor atual é
     * cortado do mesmo jeito para ser comparado ao `to`. Se o limite de lá mudar, este tem de mudar junto.
     */
    private const LIMITE_DO_TEXTO_NO_LOG = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $tradutor,
        private readonly RenomearClienteDaPastaUseCase $renomearCliente,
    ) {
    }

    /**
     * @param array<array-key, mixed> $diff o `changes` do diff do log (campo => {from, to})
     *
     * @return string|null a mensagem da recusa; null quando a tela deixaria fazer a mesma mudança
     */
    public function recusa(object $entidade, array $diff, \DateTimeImmutable $agora): ?string
    {
        $campos = array_map('strval', array_keys($diff));
        $pasta = self::pastaMae($entidade);

        if ($pasta !== null && $pasta->estaExcluida()) {
            return PastaSomenteLeituraListener::MENSAGEM;
        }

        if ($entidade instanceof Pasta && array_intersect(self::CAMPOS_SO_PELA_TELA_DA_PASTA, $campos) !== []) {
            return self::MENSAGEM_SO_PELA_TELA_DA_PASTA;
        }

        if (!$this->continuaComoOLogDeixou($entidade, $diff)) {
            return self::MENSAGEM_REGISTRO_MUDOU;
        }

        if ($entidade instanceof Pasta && in_array('nomeCliente', $campos, true) && $this->renomearCliente->nomeEMontadoPelaCarteira($entidade)) {
            return RenomearClienteDaPastaUseCase::MENSAGEM_NOME_MONTADO_PELA_CARTEIRA;
        }

        if ($entidade instanceof Tarefa) {
            return $this->recusaDaMeta($entidade, $diff, $campos, $agora);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $diff
     * @param list<string>            $campos
     */
    private function recusaDaMeta(Tarefa $meta, array $diff, array $campos, \DateTimeImmutable $agora): ?string
    {
        $temStatus = in_array('status', $campos, true);
        $statusFinal = $temStatus ? self::from($diff, 'status') : $meta->getStatus();

        if ($temStatus && $statusFinal !== Tarefa::STATUS_CONCLUIDA && ReabrirMetaUseCase::seguiuAdiante($meta)) {
            return $this->tradutor->trans(MetaJaSeguiuAdianteException::CHAVE);
        }

        if (array_intersect(self::CAMPOS_DA_ETAPA, $campos) !== [] && !MontarEtapaDaMetaUseCase::podeMexerNaEtapa($meta)) {
            return PassagemRecusadaException::jaConcluida()->getMessage();
        }

        $temData = in_array('dataConclusao', $campos, true);
        $dataFinal = $temData ? self::data(self::from($diff, 'dataConclusao')) : $meta->getDataConclusao();

        if ($temData && self::from($diff, 'dataConclusao') !== null && $dataFinal === null) {
            // Data ilegível no log: a fase 1 do desfazer a recusa como irrecuperável.
            return null;
        }

        if ($statusFinal === Tarefa::STATUS_CONCLUIDA && $temData) {
            $motivo = DesfazerReaberturaDaMetaUseCase::motivoParaRecusarAData($dataFinal, $meta, $agora);

            if ($motivo !== null) {
                return $motivo;
            }
        }

        // Só o que o desfazer mexe: a meta que já estava incoerente não trava o desfazer de outro campo.
        if (($temStatus || $temData) && ($statusFinal === Tarefa::STATUS_CONCLUIDA) !== ($dataFinal !== null)) {
            return self::MENSAGEM_META_INCOERENTE;
        }

        return null;
    }

    /**
     * Cada campo do diff tem de estar hoje como o log o deixou (o `to`): senão alguém mudou depois, e voltar ao `from`
     * apagaria essa mudança em silêncio. Campo que não é coluna nem associação a um fica de fora: a fase 1 do desfazer
     * o recusa com a mensagem dele.
     *
     * @param array<array-key, mixed> $diff
     */
    private function continuaComoOLogDeixou(object $entidade, array $diff): bool
    {
        $metadata = $this->em->getClassMetadata($entidade::class);

        foreach ($diff as $campo => $mudanca) {
            $campo = (string) $campo;
            $to = is_array($mudanca) ? ($mudanca['to'] ?? null) : null;

            if ($metadata->hasField($campo)) {
                if (!self::igual($metadata->getFieldValue($entidade, $campo), $to)) {
                    return false;
                }

                continue;
            }

            if ($metadata->hasAssociation($campo) && $metadata->isSingleValuedAssociation($campo)
                && !self::mesmaReferencia($metadata->getFieldValue($entidade, $campo), $to)) {
                return false;
            }
        }

        return true;
    }

    /** O valor atual na forma em que o `AuditLogSubscriber` o gravaria, comparado ao `to`. */
    private static function igual(mixed $atual, mixed $to): bool
    {
        if ($atual === null || $to === null) {
            return $atual === $to;
        }

        if ($atual instanceof \DateTimeInterface) {
            $data = self::data($to);

            return $data !== null && $data->getTimestamp() === $atual->getTimestamp();
        }

        if ($atual instanceof \BackedEnum) {
            return is_scalar($to) && (string) $atual->value === (string) $to;
        }

        if (is_bool($atual) || is_bool($to)) {
            return $atual === $to;
        }

        if (is_string($atual)) {
            $noLog = mb_strlen($atual) > self::LIMITE_DO_TEXTO_NO_LOG
                ? mb_substr($atual, 0, self::LIMITE_DO_TEXTO_NO_LOG) . '…'
                : $atual;

            return is_scalar($to) && $noLog === (string) $to;
        }

        if (is_int($atual) || is_float($atual)) {
            return is_scalar($to) && (string) $atual === (string) $to;
        }

        if (is_array($atual)) {
            return is_array($to) && self::listasIguais($atual, $to);
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $atual
     * @param array<array-key, mixed> $to
     */
    private static function listasIguais(array $atual, array $to): bool
    {
        if (count($atual) !== count($to) || array_keys($atual) !== array_keys($to)) {
            return false;
        }

        foreach ($atual as $chave => $valor) {
            if (!self::igual($valor, $to[$chave])) {
                return false;
            }
        }

        return true;
    }

    /** Associação: o log guarda `{class, id, label}`; vale o id. */
    private static function mesmaReferencia(mixed $atual, mixed $to): bool
    {
        if ($atual === null || $to === null) {
            return $atual === $to;
        }

        $id = is_array($to) ? ($to['id'] ?? null) : null;
        $idAtual = is_object($atual) && method_exists($atual, 'getId') ? $atual->getId() : null;

        return is_scalar($id) && is_scalar($idAtual) && (string) $id === (string) $idAtual;
    }

    /** @param array<array-key, mixed> $diff */
    private static function from(array $diff, string $campo): mixed
    {
        $mudanca = $diff[$campo] ?? null;

        return is_array($mudanca) ? ($mudanca['from'] ?? null) : null;
    }

    private static function data(mixed $valor): ?\DateTimeImmutable
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($valor);
        } catch (\Exception) {
            return null;
        }
    }

    /** A pasta de que a entidade depende: a própria, a das filhas, a da meta e a da mensagem. */
    private static function pastaMae(object $entidade): ?Pasta
    {
        if ($entidade instanceof Pasta) {
            return $entidade;
        }

        if ($entidade instanceof Tarefa || $entidade instanceof PastaDocumento || $entidade instanceof PastaSecao
            || $entidade instanceof PastaChecklistItem || $entidade instanceof PastaObservacaoDetalhes
            || $entidade instanceof PastaMensagem) {
            return $entidade->getPasta();
        }

        return null;
    }
}
