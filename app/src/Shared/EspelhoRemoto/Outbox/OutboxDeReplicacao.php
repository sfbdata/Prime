<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Outbox;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * A outbox da réplica — tabela `armazenamento_replicacao` (spec P2 §3.2, §14). Ela É a fila.
 *
 * ## Escrita na mesma conexão do domínio (DP-10 refinada)
 *
 * Cada registro abre `beginTransaction()` na conexão compartilhada. Com transação do domínio aberta,
 * isso é um **savepoint** (DBAL 4): o domínio desfeito leva a linha junto, e a falha da própria
 * outbox volta só o savepoint. Sem transação aberta, a linha é confirmada na hora.
 *
 * ## Reivindicação
 *
 * Sob `pg_advisory_xact_lock` (curta: só a escolha da linha), `FOR UPDATE SKIP LOCKED`, e com duas
 * regras de ordem por chave remota:
 *  - não entrega linha cuja chave tenha OUTRA em `processando` com posse válida;
 *  - linha com outra MAIS NOVA da mesma chave (envio ou exclusão) vira `superado` e não sai.
 * Posse vencida (worker morto) volta a ser reivindicável — mas só até o teto de tentativas: a linha que
 * derruba o worker toda vez vira `falhou` em vez de "veneno" eterno no topo da fila.
 *
 * ## Posse com token
 *
 * Cada reivindicação grava um token aleatório em `posse`. Concluir, reagendar e renovar só valem com
 * ele: um worker que perdeu a posse (demorou além do prazo) não reescreve o estado de quem a pegou
 * depois, e fica sabendo (o método devolve `false`). O worker RENOVA a posse imediatamente antes do
 * PUT e não envia se não conseguir — com o teto de duração do HTTP menor que a posse, um envio velho
 * não termina depois de outro worker ter enviado a versão nova.
 */
final class OutboxDeReplicacao
{
    public const ESTADO_PENDENTE    = 'pendente';
    public const ESTADO_PROCESSANDO = 'processando';
    public const ESTADO_VERIFICADO  = 'verificado';
    public const ESTADO_FALHOU      = 'falhou';
    public const ESTADO_SUPERADO    = 'superado';
    public const ESTADO_DESCARTADO  = 'descartado';
    public const ESTADO_REGISTRADO  = 'registrado';

    public const OPERACAO_ENVIAR   = 'enviar';
    public const OPERACAO_EXCLUSAO = 'exclusao_registrada';

    private const TABELA = 'armazenamento_replicacao';

    /** Identificador da trava consultiva da reivindicação ("replica" em decimal, sem colisão prática). */
    private const TRAVA_DA_REIVINDICACAO = 7_202_610_150;

    public function __construct(
        private readonly Connection $conexao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function registrarEnvio(ChaveDeArquivo $chave, string $sha256, int $tamanhoBytes, string $mimeType): int
    {
        return $this->registrar($chave, self::OPERACAO_ENVIAR, self::ESTADO_PENDENTE, $sha256, $tamanhoBytes, $mimeType);
    }

    /** A exclusão vira evento — **nunca** chamada ao R2 (regra 0 da P2). */
    public function registrarExclusao(ChaveDeArquivo $chave): int
    {
        return $this->registrar($chave, self::OPERACAO_EXCLUSAO, self::ESTADO_REGISTRADO, null, null, null);
    }

    public function reivindicarProxima(int $posseEmSegundos, int $maximoDeTentativas): ?ItemDeReplicacao
    {
        return $this->conexao->transactional(function (Connection $c) use ($posseEmSegundos, $maximoDeTentativas): ?ItemDeReplicacao {
            $c->executeStatement('SELECT pg_advisory_xact_lock(?)', [self::TRAVA_DA_REIVINDICACAO]);
            $agora = $this->agora();

            while (true) {
                $linha = $this->proximaCandidata($c, $agora);
                if ($linha === false) {
                    return null;
                }

                $chave = $this->chaveSeAindaServe($c, $linha, $maximoDeTentativas);
                if ($chave !== null) {
                    return $this->tomarPosse($c, $linha, $chave, $posseEmSegundos, $agora);
                }
            }
        });
    }

    /**
     * A próxima linha de envio vencida (pendente na hora, ou em processamento com a posse vencida),
     * sem outra em processamento válido para a mesma chave remota.
     *
     * @return array<string, mixed>|false
     */
    private function proximaCandidata(Connection $c, string $agora): array|false
    {
        return $c->fetchAssociative(
            'SELECT * FROM ' . self::TABELA . ' r
              WHERE r.operacao = :enviar
                AND ((r.estado = :pendente AND r.proxima_tentativa_em <= :agora)
                     OR (r.estado = :processando AND r.processando_ate < :agora))
                AND NOT EXISTS (
                    SELECT 1 FROM ' . self::TABELA . ' o
                     WHERE o.chave_remota = r.chave_remota AND o.id <> r.id
                       AND o.estado = :processando AND o.processando_ate >= :agora)
              ORDER BY r.id
              LIMIT 1
              FOR UPDATE SKIP LOCKED',
            ['enviar' => self::OPERACAO_ENVIAR, 'pendente' => self::ESTADO_PENDENTE, 'processando' => self::ESTADO_PROCESSANDO, 'agora' => $agora],
        );
    }

    /**
     * A chave da linha, se ela ainda deve ser enviada; senão a linha muda de estado (superada por uma
     * mais nova, falhou pelo teto de tentativas ou por chave inválida) e volta null.
     *
     * @param array<string, mixed> $linha
     */
    private function chaveSeAindaServe(Connection $c, array $linha, int $maximoDeTentativas): ?ChaveDeArquivo
    {
        $id           = (int) $linha['id'];
        $novaMaisNova = $c->fetchOne(
            'SELECT id FROM ' . self::TABELA . ' WHERE chave_remota = :chave AND id > :id ORDER BY id DESC LIMIT 1',
            ['chave' => $linha['chave_remota'], 'id' => $id],
        );
        if ($novaMaisNova !== false) {
            $this->mudar($c, $id, self::ESTADO_SUPERADO, sprintf('há registro mais novo da mesma chave (#%d)', (int) $novaMaisNova));

            return null;
        }

        if ((int) $linha['tentativas'] >= $maximoDeTentativas) {
            $this->mudar($c, $id, self::ESTADO_FALHOU, sprintf('posse vencida depois de %d tentativas (o worker caiu ou o item derruba o worker)', (int) $linha['tentativas']));

            return null;
        }

        try {
            return self::chaveDaLinha($linha);
        } catch (ChaveDeArquivoInvalida $e) {
            // A mensagem da chave traz o nome cru; aqui vai só a classe.
            $this->mudar($c, $id, self::ESTADO_FALHOU, 'linha sem chave válida (' . $e::class . ')');

            return null;
        }
    }

    /** @param array<string, mixed> $linha */
    private function tomarPosse(Connection $c, array $linha, ChaveDeArquivo $chave, int $posseEmSegundos, string $agora): ItemDeReplicacao
    {
        $id    = (int) $linha['id'];
        $posse = bin2hex(random_bytes(16));
        $c->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :processando, processando_ate = :ate, posse = :posse, tentativas = tentativas + 1, atualizado_em = :agora WHERE id = :id',
            ['processando' => self::ESTADO_PROCESSANDO, 'ate' => $this->agora($posseEmSegundos), 'posse' => $posse, 'agora' => $agora, 'id' => $id],
        );

        return new ItemDeReplicacao(
            $id,
            $chave,
            (string) $linha['chave_remota'],
            (string) $linha['sha256'],
            (int) $linha['tamanho_bytes'],
            (string) ($linha['mime_type'] ?? 'application/octet-stream'),
            (int) $linha['tentativas'] + 1,
            $posse,
        );
    }

    /** Cada `marcar*`/`reagendar`/`renovarPosse` devolve false quando a posse já não era deste item. */
    public function marcarVerificado(ItemDeReplicacao $item): bool
    {
        return $this->concluir($item, self::ESTADO_VERIFICADO, null);
    }

    public function marcarSuperado(ItemDeReplicacao $item, string $motivo): bool
    {
        return $this->concluir($item, self::ESTADO_SUPERADO, $motivo);
    }

    public function marcarDescartado(ItemDeReplicacao $item, string $motivo): bool
    {
        return $this->concluir($item, self::ESTADO_DESCARTADO, $motivo);
    }

    public function marcarFalhou(ItemDeReplicacao $item, string $erroSemDadosPessoais): bool
    {
        return $this->concluir($item, self::ESTADO_FALHOU, $erroSemDadosPessoais);
    }

    public function reagendar(ItemDeReplicacao $item, string $erroSemDadosPessoais, int $emSegundos): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :pendente, processando_ate = NULL, posse = NULL, proxima_tentativa_em = :quando, ultimo_erro = :erro, atualizado_em = :agora
              WHERE id = :id AND estado = :processando AND posse = :posse',
            ['pendente' => self::ESTADO_PENDENTE, 'quando' => $this->agora($emSegundos), 'erro' => $erroSemDadosPessoais, 'agora' => $this->agora(), 'id' => $item->id, 'processando' => self::ESTADO_PROCESSANDO, 'posse' => $item->posse],
        ) === 1;
    }

    /**
     * Estende a posse deste item por mais N segundos a partir de agora — só se ela ainda for dele e
     * ainda não tiver vencido. O worker chama logo antes do PUT; `false` = não envie.
     */
    public function renovarPosse(ItemDeReplicacao $item, int $porSegundos): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET processando_ate = :ate, atualizado_em = :agora
              WHERE id = :id AND estado = :processando AND posse = :posse AND processando_ate >= :agora',
            ['ate' => $this->agora($porSegundos), 'agora' => $this->agora(), 'id' => $item->id, 'processando' => self::ESTADO_PROCESSANDO, 'posse' => $item->posse],
        ) === 1;
    }

    /** Existe registro (envio ou exclusão) mais novo que este para a mesma chave remota? */
    public function existeRegistroMaisNovo(ItemDeReplicacao $item): bool
    {
        return $this->conexao->fetchOne(
            'SELECT 1 FROM ' . self::TABELA . ' WHERE chave_remota = ? AND id > ? LIMIT 1',
            [$item->chaveRemota, $item->id],
        ) !== false;
    }

    /** @return array<string, int> estado => quantidade, das linhas de envio */
    public function contarPorEstado(): array
    {
        $contagem = [];
        foreach ($this->conexao->fetchAllAssociative('SELECT estado, count(*) AS n FROM ' . self::TABELA . ' GROUP BY estado ORDER BY estado') as $linha) {
            $contagem[(string) $linha['estado']] = (int) $linha['n'];
        }

        return $contagem;
    }

    /** O estado da linha mais recente de uma chave remota, ou null se a chave nunca passou pela outbox. */
    public function ultimoEstadoDe(string $chaveRemota): ?string
    {
        $estado = $this->conexao->fetchOne(
            'SELECT estado FROM ' . self::TABELA . ' WHERE chave_remota = ? ORDER BY id DESC LIMIT 1',
            [$chaveRemota],
        );

        return $estado === false ? null : (string) $estado;
    }

    private function registrar(ChaveDeArquivo $chave, string $operacao, string $estado, ?string $sha256, ?int $tamanho, ?string $mime): int
    {
        $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);
        $agora       = $this->agora();

        $this->conexao->beginTransaction();
        try {
            $id = $this->conexao->fetchOne(
                'INSERT INTO ' . self::TABELA . ' (operacao, escopo_tenant_id, categoria, nome, chave_remota, sha256, tamanho_bytes, mime_type, estado, tentativas, proxima_tentativa_em, criado_em, atualizado_em)
                 VALUES (:operacao, :tenant, :categoria, :nome, :chave, :sha, :tamanho, :mime, :estado, 0, :agora, :agora, :agora)
                 RETURNING id',
                [
                    'operacao' => $operacao, 'tenant' => $chave->escopo->tenantIdOuNull(), 'categoria' => $chave->categoria->value,
                    'nome' => $chave->nome, 'chave' => $chaveRemota, 'sha' => $sha256, 'tamanho' => $tamanho, 'mime' => $mime,
                    'estado' => $estado, 'agora' => $agora,
                ],
            );
            $this->conexao->commit();
        } catch (\Throwable $e) {
            $this->conexao->rollBack();

            throw $e;
        }

        return (int) $id;
    }

    private function concluir(ItemDeReplicacao $item, string $estado, ?string $motivo): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :estado, processando_ate = NULL, posse = NULL, ultimo_erro = :motivo, atualizado_em = :agora
              WHERE id = :id AND estado = :processando AND posse = :posse',
            ['estado' => $estado, 'motivo' => $motivo, 'agora' => $this->agora(), 'id' => $item->id, 'processando' => self::ESTADO_PROCESSANDO, 'posse' => $item->posse],
        ) === 1;
    }

    private function mudar(Connection $c, int $id, string $estado, string $motivo): void
    {
        $c->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :estado, processando_ate = NULL, posse = NULL, ultimo_erro = :motivo, atualizado_em = :agora WHERE id = :id',
            ['estado' => $estado, 'motivo' => $motivo, 'agora' => $this->agora(), 'id' => $id],
        );
    }

    /** @param array<string, mixed> $linha */
    private static function chaveDaLinha(array $linha): ChaveDeArquivo
    {
        $categoria = CategoriaDeArquivo::tryFrom((string) $linha['categoria'])
            ?? throw new ChaveDeArquivoInvalida('categoria desconhecida');
        $escopo = $linha['escopo_tenant_id'] === null
            ? EscopoDeArquivo::global()
            : EscopoDeArquivo::deTenant((int) $linha['escopo_tenant_id']);

        return new ChaveDeArquivo($escopo, $categoria, (string) $linha['nome']);
    }

    /**
     * Sempre em UTC: as colunas são `TIMESTAMP WITHOUT TIME ZONE`, e só este relógio as escreve e
     * compara. Um worker rodando com outro fuso configurado não pode deslocar posse e espera.
     */
    private function agora(int $maisSegundos = 0): string
    {
        $agora = $this->relogio->now()->setTimezone(new \DateTimeZone('UTC'));
        if ($maisSegundos !== 0) {
            $agora = $agora->modify(sprintf('%+d seconds', $maisSegundos));
        }

        return $agora->format('Y-m-d H:i:s');
    }
}
